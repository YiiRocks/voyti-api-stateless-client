<?php

declare(strict_types=1);

namespace YiiRocks\Voyti\Api\StatelessClient\tests\Service;

use Nyholm\Psr7\ServerRequest;
use YiiRocks\Voyti\Api\Service\User\ApiTokenService;
use YiiRocks\Voyti\Api\StatelessClient\Service\ApiLoginCompletionService;
use YiiRocks\Voyti\Api\StatelessClient\tests\Support\DatabaseTestCase;
use YiiRocks\Voyti\Api\StatelessClient\tests\Support\EventCaptureDispatcher;
use YiiRocks\Voyti\Api\StatelessClient\tests\Support\UserFactoryTrait;
use YiiRocks\Voyti\Auth\PostLoginHookInterface;
use YiiRocks\Voyti\Clock\SystemClock;
use YiiRocks\Voyti\Event\Auth\AfterLoginEvent;
use YiiRocks\Voyti\Model\User;

final class ApiLoginCompletionServiceTest extends DatabaseTestCase
{
    use UserFactoryTrait;

    public function testComplete(): void
    {
        $user = $this->createUser('completeuser', 'complete@example.com');
        $eventDispatcher = new EventCaptureDispatcher();
        $postLoginHook = new class implements PostLoginHookInterface {
            /** @var list<?string> */
            public array $handledUsers = [];

            public function handle(User $user): void
            {
                $this->handledUsers[] = $user->getId();
            }
        };

        $service = new ApiLoginCompletionService(
            new ApiTokenService(new SystemClock()),
            $eventDispatcher,
            [$postLoginHook],
        );

        $token = $service->complete($user, new ServerRequest('POST', '/'));

        self::assertSame(64, strlen($token));
        self::assertSame([$user->getId()], $postLoginHook->handledUsers);
        self::assertTrue($eventDispatcher->hasEvent(AfterLoginEvent::class));
    }
}
