<?php

declare(strict_types=1);

namespace YiiRocks\Voyti\Api\StatelessClient\Controller\V1\PasswordReset;

use Psr\Http\Message\ResponseInterface;
use YiiRocks\Voyti\Model\User;
use YiiRocks\Voyti\Model\UserToken;
use YiiRocks\Voyti\Service\Password\RecoveryService;
use YiiRocks\Voyti\Service\Password\ResetService;
use YiiRocks\Voyti\VoytiConfig;
use Yiisoft\DataResponse\ResponseFactory\DataResponseFactoryInterface;
use Yiisoft\Http\Status;
use Yiisoft\Input\Http\Attribute\Parameter\Body;

/**
 * Public "forgot password" flow for the SPA API: requests a recovery email via core's
 * {@see RecoveryService}, then confirms the emailed token and sets the new password via core's
 * {@see ResetService} - the same services core's HTML `PasswordResetController` uses.
 */
final readonly class PasswordResetController
{
    public function __construct(
        private RecoveryService $recoveryService,
        private ResetService $resetService,
        private DataResponseFactoryInterface $responseFactory,
        private VoytiConfig $config,
    ) {}

    public function confirm(
        /** @infection-ignore-all No real user id is ever <= 0, so any non-positive default (0, -1, ...) is behaviorally identical: findByUserIdAndCodeAndType() never matches a real token. */
        #[Body('id')]
        int $id = 0,
        #[Body('code')]
        string $code = '',
        #[Body('password')]
        string $password = '',
    ): ResponseInterface {
        if (!$this->config->allowPasswordRecovery && !$this->config->allowAdminPasswordRecovery) {
            return $this->responseFactory->createResponse(['error' => 'Password reset is disabled.'], Status::FORBIDDEN);
        }

        $userToken = UserToken::findByUserIdAndCodeAndType($id, $code, UserToken::TYPE_RECOVERY);

        if ($userToken === null || $userToken->isExpired($this->config->tokenRecoveryLifespan) || $userToken->getUser() === null) {
            return $this->responseFactory->createResponse(['error' => 'Reset link is invalid or expired.'], Status::BAD_REQUEST);
        }

        /** @var User $user */
        $user = $userToken->getUser();

        if (!$this->resetService->run($password, $user, $userToken)) {
            return $this->responseFactory->createResponse(
                ['error' => 'This password has been used recently. Please choose a different one.'],
                Status::BAD_REQUEST,
            );
        }

        return $this->responseFactory->createResponse(['message' => 'Password changed.']);
    }

    public function request(
        #[Body('email')]
        string $email = '',
    ): ResponseInterface {
        if (!$this->config->allowPasswordRecovery) {
            return $this->responseFactory->createResponse(['error' => 'Password reset is disabled.'], Status::FORBIDDEN);
        }

        $result = $this->recoveryService->run($email);

        return $this->responseFactory->createResponse(['message' => $result->getMessage()]);
    }
}
