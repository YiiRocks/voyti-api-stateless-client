<?php

declare(strict_types=1);

namespace YiiRocks\Voyti\Api\StatelessClient\Controller\V1\TwoFactor;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use YiiRocks\Voyti\Model\User;
use YiiRocks\Voyti\TwoFactor\Model\UserTwoFactor;
use YiiRocks\Voyti\TwoFactor\Service\BackupCodeService;
use YiiRocks\Voyti\TwoFactor\Webauthn\Service\WebauthnService;
use Yiisoft\DataResponse\ResponseFactory\DataResponseFactoryInterface;
use Yiisoft\Http\Status;
use Yiisoft\Input\Http\Attribute\Parameter\Body;
use Yiisoft\User\CurrentUser;

/**
 * WebAuthn's own registration ceremony, mirroring `voyti-2fa-webauthn`'s HTML
 * `WebauthnController::settings()`/`register()`, adapted for a stateless bearer-token caller: the
 * ceremony challenge {@see WebauthnService::getCreateArgs()} would normally stash in the session is
 * instead persisted on the user's own (not-yet-enabled) {@see UserTwoFactor} row via its otherwise
 * unused `secret` column, then read back and passed as {@see WebauthnService::register()}'s
 * `$challengeOverride` on `finish()`.
 */
final readonly class WebauthnEnrollmentController
{
    public function __construct(
        private BackupCodeService $backupCodeService,
        private CurrentUser $currentUser,
        private DataResponseFactoryInterface $responseFactory,
        private WebauthnService $webauthnService,
    ) {}

    public function finish(
        ServerRequestInterface $request,
        #[Body('clientDataJSON')]
        string $clientDataJSON = '',
        #[Body('attestationObject')]
        string $attestationObject = '',
    ): ResponseInterface {
        $user = $this->currentUserOrFail();
        $twoFactor = UserTwoFactor::forUser($user);

        if ($twoFactor->isEnabled()) {
            return $this->responseFactory->createResponse(['error' => 'Two-factor authentication is already enabled.'], Status::BAD_REQUEST);
        }

        $challenge = $twoFactor->getSecret();
        if ($challenge === null) {
            return $this->responseFactory->createResponse(['error' => 'No pending WebAuthn registration was found.'], Status::BAD_REQUEST);
        }

        $registered = $this->webauthnService->register(
            $user,
            ['clientDataJSON' => $clientDataJSON, 'attestationObject' => $attestationObject],
            $request->getUri()->getHost(),
            $challenge,
        );

        if (!$registered) {
            return $this->responseFactory->createResponse(['error' => $this->webauthnService->getErrorMessage()], Status::UNAUTHORIZED);
        }

        $twoFactor->setMethod('webauthn');
        $twoFactor->setEnabled(true);
        $twoFactor->setSecret(null);
        $twoFactor->save();

        return $this->responseFactory->createResponse(
            ['message' => 'Two-factor authentication enabled.', 'backupCodes' => $this->backupCodeService->generate($user)],
            Status::CREATED,
        );
    }

    public function start(ServerRequestInterface $request): ResponseInterface
    {
        $user = $this->currentUserOrFail();
        $twoFactor = UserTwoFactor::forUser($user);

        if ($twoFactor->isEnabled()) {
            return $this->responseFactory->createResponse(['error' => 'Two-factor authentication is already enabled.'], Status::BAD_REQUEST);
        }

        $challenge = null;
        $createArgs = $this->webauthnService->getCreateArgs($user, $request->getUri()->getHost(), $challenge);

        $twoFactor->setSecret($challenge);
        $twoFactor->save();

        return $this->responseFactory->createResponse(['requestOptions' => $createArgs]);
    }

    private function currentUserOrFail(): User
    {
        /** @var User $user */
        $user = $this->currentUser->getIdentity();

        return $user;
    }
}
