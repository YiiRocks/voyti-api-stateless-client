<?php

declare(strict_types=1);

namespace YiiRocks\Voyti\Api\StatelessClient\Controller\V1\TwoFactor;

use Psr\Http\Message\ResponseInterface;
use YiiRocks\Voyti\Model\User;
use YiiRocks\Voyti\TwoFactor\Model\UserTwoFactor;
use YiiRocks\Voyti\TwoFactor\Totp\Service\QrCodeUriGeneratorService;
use Yiisoft\DataResponse\ResponseFactory\DataResponseFactoryInterface;
use Yiisoft\Http\Status;
use Yiisoft\Translator\TranslatorInterface;
use Yiisoft\User\CurrentUser;

/**
 * TOTP's own setup step, mirroring `voyti-2fa-totp`'s HTML `TotpController::settings()`/`renew()`:
 * a QR code and the underlying secret (for manual entry) must be issued before the generic
 * `TwoFactorManagementController::enable()` action has anything to verify a submitted code against.
 */
final readonly class TotpSetupController
{
    public function __construct(
        private CurrentUser $currentUser,
        private DataResponseFactoryInterface $responseFactory,
        private QrCodeUriGeneratorService $qrCodeUriGeneratorService,
        private TranslatorInterface $translator,
    ) {}

    public function renew(): ResponseInterface
    {
        $user = $this->currentUserOrFail();

        if (UserTwoFactor::forUser($user)->isEnabled()) {
            return $this->responseFactory->createResponse(
                [
                    'error' => $this->translator->translate(
                        'voyti-api-stateless-client.two_factor.already_enabled',
                        category: 'voyti-api-stateless-client',
                    ),
                ],
                Status::BAD_REQUEST,
            );
        }

        $qrCodeSvg = $this->qrCodeUriGeneratorService->regenerateQrCodeSvg($user);

        return $this->responseFactory->createResponse([
            'qrCodeUri' => $qrCodeSvg,
            'secret' => UserTwoFactor::forUser($user)->getSecret(),
        ]);
    }

    public function show(): ResponseInterface
    {
        $user = $this->currentUserOrFail();

        if (UserTwoFactor::forUser($user)->isEnabled()) {
            return $this->responseFactory->createResponse(
                [
                    'error' => $this->translator->translate(
                        'voyti-api-stateless-client.two_factor.already_enabled',
                        category: 'voyti-api-stateless-client',
                    ),
                ],
                Status::BAD_REQUEST,
            );
        }

        $qrCodeSvg = $this->qrCodeUriGeneratorService->generateQrCodeSvg($user);

        return $this->responseFactory->createResponse([
            'qrCodeUri' => $qrCodeSvg,
            'secret' => UserTwoFactor::forUser($user)->getSecret(),
        ]);
    }

    private function currentUserOrFail(): User
    {
        /** @var User $user */
        $user = $this->currentUser->getIdentity();

        return $user;
    }
}
