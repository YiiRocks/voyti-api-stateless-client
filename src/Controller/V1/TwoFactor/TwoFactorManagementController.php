<?php

declare(strict_types=1);

namespace YiiRocks\Voyti\Api\StatelessClient\Controller\V1\TwoFactor;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use YiiRocks\Voyti\Model\User;
use YiiRocks\Voyti\TwoFactor\Model\UserTwoFactor;
use YiiRocks\Voyti\TwoFactor\Service\BackupCodeService;
use YiiRocks\Voyti\TwoFactor\Service\TwoFactorDisableService;
use YiiRocks\Voyti\TwoFactor\TwoFactorMethodInterface;
use YiiRocks\Voyti\TwoFactor\TwoFactorMethodRegistry;
use Yiisoft\DataResponse\ResponseFactory\DataResponseFactoryInterface;
use Yiisoft\Http\Status;
use Yiisoft\Input\Http\Attribute\Parameter\Body;
use Yiisoft\Translator\TranslatorInterface;
use Yiisoft\User\CurrentUser;

/**
 * Enrollment/management for the SPA API's own 2FA bridge: status, enabling a code-based method,
 * disabling, and regenerating backup codes - mirroring `voyti-2fa`'s HTML
 * `TwoFactorController` (same collaborators, same re-authentication rule), but returning the backup
 * codes directly in the response instead of stashing them for a follow-up reveal page, since a JSON
 * caller has no such second request to make.
 *
 * Registering a client-collected method (WebAuthn) isn't included here - each such method sets up
 * through its own dedicated ceremony in the HTML app too, never through this generic action.
 */
final readonly class TwoFactorManagementController
{
    public function __construct(
        private BackupCodeService $backupCodeService,
        private CurrentUser $currentUser,
        private DataResponseFactoryInterface $responseFactory,
        private TwoFactorDisableService $twoFactorDisableService,
        private TwoFactorMethodRegistry $twoFactorMethods,
        private TranslatorInterface $translator,
    ) {}

    public function disable(
        ServerRequestInterface $request,
        #[Body('code')]
        string $code = '',
        #[Body('payload')]
        string $payload = '',
    ): ResponseInterface {
        $user = $this->currentUserOrFail();
        $twoFactor = UserTwoFactor::forUser($user);

        if (!$twoFactor->isEnabled()) {
            return $this->responseFactory->createResponse(
                ['error' => $this->translator->translate('voyti-api-stateless-client.two_factor.not_enabled', category: 'voyti-api-stateless-client')],
                Status::BAD_REQUEST,
            );
        }

        $method = $this->resolveMethod($twoFactor->getMethod());
        $reauthResult = $this->verifyReauthentication($user, $method, $code, $payload, $request);
        if ($reauthResult !== null) {
            return $reauthResult;
        }

        $this->twoFactorDisableService->disable($user);

        return $this->responseFactory->createResponse(
            ['message' => $this->translator->translate('voyti-2fa.settings.two_factor_disabled', category: 'voyti-2fa')],
        );
    }

    public function enable(
        #[Body('method')]
        string $method = '',
        #[Body('code')]
        string $code = '',
    ): ResponseInterface {
        $user = $this->currentUserOrFail();
        $twoFactor = UserTwoFactor::forUser($user);

        if ($twoFactor->isEnabled()) {
            return $this->responseFactory->createResponse(
                ['error' => $this->translator->translate('voyti-api-stateless-client.two_factor.already_enabled', category: 'voyti-api-stateless-client')],
                Status::BAD_REQUEST,
            );
        }

        if (!$this->twoFactorMethods->has($method)) {
            if (!$this->twoFactorMethods->hasAvailable()) {
                return $this->responseFactory->createResponse(
                    [
                        'error' => $this->translator->translate(
                            'voyti-api-stateless-client.two_factor.no_method_available',
                            category: 'voyti-api-stateless-client',
                        ),
                    ],
                    Status::BAD_REQUEST,
                );
            }

            $method = $this->twoFactorMethods->getDefault()->getName();
        }

        $twoFactorMethod = $this->twoFactorMethods->get($method);

        if (!$twoFactorMethod->isCodeBased()) {
            return $this->responseFactory->createResponse(
                [
                    'error' => $this->translator->translate(
                        'voyti-api-stateless-client.two_factor.method_requires_own_endpoint',
                        category: 'voyti-api-stateless-client',
                    ),
                ],
                Status::BAD_REQUEST,
            );
        }

        if (!$twoFactorMethod->verify($user, ['code' => $code])) {
            return $this->responseFactory->createResponse(
                ['error' => $this->errorMessage($twoFactorMethod)],
                Status::UNAUTHORIZED,
            );
        }

        $twoFactor->setMethod($twoFactorMethod->getName());
        $twoFactor->setEnabled(true);
        $twoFactor->save();

        return $this->responseFactory->createResponse(
            [
                'message' => $this->translator->translate('voyti-2fa.settings.two_factor_enabled', category: 'voyti-2fa'),
                'backupCodes' => $this->backupCodeService->generate($user),
            ],
            Status::CREATED,
        );
    }

    public function regenerateBackupCodes(
        ServerRequestInterface $request,
        #[Body('code')]
        string $code = '',
        #[Body('payload')]
        string $payload = '',
    ): ResponseInterface {
        $user = $this->currentUserOrFail();
        $twoFactor = UserTwoFactor::forUser($user);

        if (!$twoFactor->isEnabled()) {
            return $this->responseFactory->createResponse(
                ['error' => $this->translator->translate('voyti-api-stateless-client.two_factor.not_enabled', category: 'voyti-api-stateless-client')],
                Status::BAD_REQUEST,
            );
        }

        $method = $this->resolveMethod($twoFactor->getMethod());
        $reauthResult = $this->verifyReauthentication($user, $method, $code, $payload, $request);
        if ($reauthResult !== null) {
            return $reauthResult;
        }

        return $this->responseFactory->createResponse([
            'message' => $this->translator->translate(
                'voyti-api-stateless-client.two_factor.backup_codes_regenerated',
                category: 'voyti-api-stateless-client',
            ),
            'backupCodes' => $this->backupCodeService->generate($user),
        ]);
    }

    public function status(): ResponseInterface
    {
        $user = $this->currentUserOrFail();
        $twoFactor = UserTwoFactor::forUser($user);

        return $this->responseFactory->createResponse([
            'enabled' => $twoFactor->isEnabled(),
            'method' => $twoFactor->getMethod(),
            'hasUnusedBackupCodes' => $this->backupCodeService->hasUnused($user),
            'availableMethods' => array_map(
                static fn(TwoFactorMethodInterface $method): array => [
                    'name' => $method->getName(),
                    'isCodeBased' => $method->isCodeBased(),
                    'requiresCodeDelivery' => $method->requiresCodeDelivery(),
                ],
                $this->twoFactorMethods->getAvailable(),
            ),
        ]);
    }

    private function currentUserOrFail(): User
    {
        /** @var User $user */
        $user = $this->currentUser->getIdentity();

        return $user;
    }

    private function errorMessage(TwoFactorMethodInterface $method): string
    {
        $message = $method->getErrorMessage();

        return $message !== ''
            ? $message
            : $this->translator->translate('voyti-2fa.validator.invalid_verification_code', category: 'voyti-2fa');
    }

    /**
     * Falls back to the default method when the stored type is null or no longer registered.
     */
    private function resolveMethod(?string $name): TwoFactorMethodInterface
    {
        return $this->twoFactorMethods->has($name)
            ? $this->twoFactorMethods->get((string) $name)
            : $this->twoFactorMethods->getDefault();
    }

    /**
     * Re-verifies the user before a sensitive action (disabling 2FA, regenerating backup codes).
     * Code-based methods accept a typed code or a backup code; client-collected methods (WebAuthn)
     * verify the posted payload. Returns an error response on failure, or null on success.
     */
    private function verifyReauthentication(
        User $user,
        TwoFactorMethodInterface $method,
        string $code,
        string $payload,
        ServerRequestInterface $request,
    ): ?ResponseInterface {
        $verified = $method->isCodeBased()
            ? ($method->verify($user, ['code' => $code]) || $this->backupCodeService->consume($user, $code))
            : $method->verify($user, ['payload' => $payload, 'domain' => $request->getUri()->getHost()]);

        return $verified ? null : $this->responseFactory->createResponse(['error' => $this->errorMessage($method)], Status::UNAUTHORIZED);
    }
}
