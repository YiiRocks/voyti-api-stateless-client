<?php

declare(strict_types=1);

namespace YiiRocks\Voyti\Api\StatelessClient\Controller\V1\TwoFactor;

use Psr\Http\Message\ResponseInterface;
use YiiRocks\Voyti\Model\User;
use YiiRocks\Voyti\TwoFactor\Email\Service\EmailCodeGeneratorService;
use YiiRocks\Voyti\TwoFactor\Model\UserTwoFactor;
use Yiisoft\DataResponse\ResponseFactory\DataResponseFactoryInterface;
use Yiisoft\Http\Status;
use Yiisoft\User\CurrentUser;

/**
 * Email's own setup step, mirroring `voyti-2fa-email`'s HTML `EmailController::sendCode()`: a code
 * must be emailed before the generic `TwoFactorManagementController::enable()` action has anything
 * to verify a submitted code against. The code itself is never returned here - only the mailer sees
 * it, exactly as the HTML flow only ever emails it.
 */
final readonly class EmailCodeController
{
    public function __construct(
        private CurrentUser $currentUser,
        private DataResponseFactoryInterface $responseFactory,
        private EmailCodeGeneratorService $emailCodeGeneratorService,
    ) {}

    public function sendCode(): ResponseInterface
    {
        $user = $this->currentUserOrFail();

        if (UserTwoFactor::forUser($user)->isEnabled()) {
            return $this->responseFactory->createResponse(['error' => 'Two-factor authentication is already enabled.'], Status::BAD_REQUEST);
        }

        $this->emailCodeGeneratorService->run($user);

        return $this->responseFactory->createResponse(['message' => 'Verification code sent.']);
    }

    private function currentUserOrFail(): User
    {
        /** @var User $user */
        $user = $this->currentUser->getIdentity();

        return $user;
    }
}
