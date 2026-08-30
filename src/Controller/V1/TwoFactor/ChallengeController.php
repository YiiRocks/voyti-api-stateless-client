<?php

declare(strict_types=1);

namespace YiiRocks\Voyti\Api\StatelessClient\Controller\V1\TwoFactor;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use YiiRocks\Voyti\Api\StatelessClient\Auth\ApiTwoFactorLoginChallenge;
use YiiRocks\Voyti\Api\StatelessClient\Service\ApiLoginCompletionService;
use YiiRocks\Voyti\Model\User;
use YiiRocks\Voyti\Model\UserToken;
use YiiRocks\Voyti\TwoFactor\Model\UserTwoFactor;
use YiiRocks\Voyti\TwoFactor\Service\BackupCodeService;
use YiiRocks\Voyti\TwoFactor\TwoFactorMethodRegistry;
use Yiisoft\DataResponse\ResponseFactory\DataResponseFactoryInterface;
use Yiisoft\Http\Status;
use Yiisoft\Input\Http\Attribute\Parameter\Body;

/**
 * Completes the 2FA step {@see ApiTwoFactorLoginChallenge} started: verifies the challenge token
 * issued at login, then the code/backup-code/client-collected payload against the user's registered
 * method (a code-based method also falls back to a backup code, matching `voyti-2fa`'s own
 * `ConfirmController`), and on success issues the real bearer token via
 * {@see ApiLoginCompletionService} - the same finalize step a non-2FA login uses.
 */
final readonly class ChallengeController
{
    public function __construct(
        private ApiLoginCompletionService $apiLoginCompletionService,
        private BackupCodeService $backupCodeService,
        private DataResponseFactoryInterface $responseFactory,
        private TwoFactorMethodRegistry $twoFactorMethods,
    ) {}

    public function verify(
        ServerRequestInterface $request,
        #[Body('challengeToken')]
        string $challengeToken = '',
        #[Body('code')]
        string $code = '',
        #[Body('payload')]
        string $payload = '',
    ): ResponseInterface {
        $challenge = UserToken::findByCodeAndType($challengeToken, UserToken::TYPE_API_CHALLENGE);

        if ($challenge === null || $challenge->isExpired(ApiTwoFactorLoginChallenge::CHALLENGE_LIFESPAN)) {
            return $this->responseFactory->createResponse(['error' => 'Challenge is invalid or expired.'], Status::BAD_REQUEST);
        }

        $user = $challenge->getUser();
        if ($user === null) {
            return $this->responseFactory->createResponse(['error' => 'Challenge is invalid or expired.'], Status::BAD_REQUEST);
        }

        $methodName = UserTwoFactor::forUser($user)->getMethod();
        if (!$this->twoFactorMethods->has($methodName)) {
            return $this->responseFactory->createResponse(['error' => 'Two-factor method is no longer available.'], Status::BAD_REQUEST);
        }

        $method = $this->twoFactorMethods->get((string) $methodName);
        $verified = $method->isCodeBased()
            ? ($method->verify($user, ['code' => $code]) || $this->backupCodeService->consume($user, $code))
            : $method->verify($user, ['payload' => $payload, 'domain' => $request->getUri()->getHost()]);

        if (!$verified) {
            $errorMessage = $method->getErrorMessage();
            return $this->responseFactory->createResponse(
                ['error' => $errorMessage !== '' ? $errorMessage : 'Invalid verification code.'],
                Status::UNAUTHORIZED,
            );
        }

        $challenge->delete();
        $token = $this->apiLoginCompletionService->complete($user, $request);

        return $this->responseFactory->createResponse(['status' => 'ok', 'token' => $token]);
    }
}
