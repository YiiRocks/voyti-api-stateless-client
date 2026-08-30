<?php

declare(strict_types=1);

namespace YiiRocks\Voyti\Api\StatelessClient\Controller\V1\Session;

use Psr\Http\Message\ResponseInterface;
use YiiRocks\Voyti\Api\Service\User\ApiTokenService;
use YiiRocks\Voyti\Model\User;
use YiiRocks\Voyti\Model\UserToken;
use Yiisoft\DataResponse\ResponseFactory\DataResponseFactoryInterface;
use Yiisoft\Http\Status;
use Yiisoft\Router\HydratorAttribute\RouteArgument;
use Yiisoft\User\CurrentUser;

/**
 * The logged-in user's own active "sessions" for the SPA API. A bearer-token client has no PHP
 * session (core's `UserSessions` tracking - the concurrent-session detection used by the HTML
 * app - assumes a session cookie, which never exists for this stateless flow), so "sessions" here
 * means active API bearer tokens instead: each {@see ApiTokenService::generate()} call (i.e. each
 * successful login) is one "device", identified by its stored hash since the raw token itself is
 * only ever visible once, at issuance.
 */
final readonly class SessionsController
{
    public function __construct(
        private ApiTokenService $apiTokenService,
        private CurrentUser $currentUser,
        private DataResponseFactoryInterface $responseFactory,
    ) {}

    public function index(): ResponseInterface
    {
        $tokens = $this->apiTokenService->listActive($this->currentUserOrFail());

        return $this->responseFactory->createResponse([
            'items' => array_map(
                static fn(UserToken $token): array => [
                    'id' => $token->getCode(),
                    'createdAt' => $token->getCreatedAt(),
                ],
                $tokens,
            ),
        ]);
    }

    public function terminate(#[RouteArgument] string $id): ResponseInterface
    {
        if (!$this->apiTokenService->revokeByHash($this->currentUserOrFail(), $id)) {
            return $this->responseFactory->createResponse(['error' => 'Not found'], Status::NOT_FOUND);
        }

        return $this->responseFactory->createResponse(['message' => 'Session terminated.']);
    }

    private function currentUserOrFail(): User
    {
        /** @var User $user */
        $user = $this->currentUser->getIdentity();

        return $user;
    }
}
