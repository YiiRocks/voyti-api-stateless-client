<?php

declare(strict_types=1);

namespace YiiRocks\Voyti\Api\StatelessClient\Service;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ServerRequestInterface;
use YiiRocks\Voyti\Api\Service\User\ApiTokenService;
use YiiRocks\Voyti\Api\StatelessClient\Auth\ApiLoginChallengeInterface;
use YiiRocks\Voyti\Auth\PostLoginHookInterface;
use YiiRocks\Voyti\Event\Auth\AfterLoginEvent;
use YiiRocks\Voyti\Model\User;

/**
 * Finalizes an authenticated API login once every check (password, and any
 * {@see ApiLoginChallengeInterface} step) has passed: runs every
 * {@see PostLoginHookInterface} (the same `voyti.post-login-hook` tag core's own
 * `LoginCompletionService` consults, e.g. connecting a pending social account), dispatches
 * {@see AfterLoginEvent}, and issues a bearer token. Deliberately does not call core's
 * `LoginCompletionService::finalize()`, which establishes a PHP session and writes a remember-me
 * cookie - meaningless for a stateless bearer-token client.
 */
final readonly class ApiLoginCompletionService
{
    public function __construct(
        private ApiTokenService $apiTokenService,
        private EventDispatcherInterface $eventDispatcher,
        /** @var iterable<PostLoginHookInterface> */
        private iterable $postLoginHooks,
    ) {}

    public function complete(User $user, ServerRequestInterface $request): string
    {
        foreach ($this->postLoginHooks as $postLoginHook) {
            $postLoginHook->handle($user);
        }

        $this->eventDispatcher->dispatch(
            new AfterLoginEvent($user, previousSessionId: null, serverParams: $request->getServerParams()),
        );

        return $this->apiTokenService->generate($user);
    }
}
