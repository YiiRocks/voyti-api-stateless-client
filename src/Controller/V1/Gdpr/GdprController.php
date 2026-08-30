<?php

declare(strict_types=1);

namespace YiiRocks\Voyti\Api\StatelessClient\Controller\V1\Gdpr;

use Psr\Http\Message\ResponseInterface;
use YiiRocks\Voyti\Api\Service\User\ApiTokenService;
use YiiRocks\Voyti\Gdpr\Service\AnonymizeUserService;
use YiiRocks\Voyti\Gdpr\Service\GdprExportService;
use YiiRocks\Voyti\Model\User;
use Yiisoft\DataResponse\ResponseFactory\DataResponseFactoryInterface;
use Yiisoft\Http\Status;
use Yiisoft\Input\Http\Attribute\Parameter\Body;
use Yiisoft\Security\PasswordHasher;
use Yiisoft\User\CurrentUser;

/**
 * Bridges the optional `voyti-gdpr` package into the SPA API: self-service data export and account
 * anonymization, delegating to the same {@see GdprExportService} and {@see AnonymizeUserService}
 * core's HTML `Privacy/PrivacyController` uses. Only bound/routed when `voyti-gdpr` is installed
 * (see `config/di.php`/`config/params.php`'s `class_exists()` guards).
 */
final readonly class GdprController
{
    public function __construct(
        private AnonymizeUserService $anonymizeUserService,
        private ApiTokenService $apiTokenService,
        private CurrentUser $currentUser,
        private GdprExportService $gdprExportService,
        private PasswordHasher $passwordHasher,
        private DataResponseFactoryInterface $responseFactory,
    ) {}

    public function anonymize(
        #[Body('password')]
        string $password = '',
    ): ResponseInterface {
        $user = $this->currentUserOrFail();

        if (!$this->passwordHasher->validate($password, $user->getPasswordHash())) {
            return $this->responseFactory->createResponse(['error' => 'Invalid password.'], Status::BAD_REQUEST);
        }

        $this->anonymizeUserService->run($user);
        $this->apiTokenService->revokeAll($user);

        return $this->responseFactory->createResponse(['message' => 'Account anonymized.']);
    }

    public function export(): ResponseInterface
    {
        return $this->responseFactory->createResponse($this->gdprExportService->run($this->currentUserOrFail()));
    }

    private function currentUserOrFail(): User
    {
        /** @var User $user */
        $user = $this->currentUser->getIdentity();

        return $user;
    }
}
