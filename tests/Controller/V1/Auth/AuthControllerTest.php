<?php

declare(strict_types=1);

namespace YiiRocks\Voyti\Api\StatelessClient\tests\Controller\V1\Auth;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ServerRequestInterface;
use YiiRocks\Voyti\Api\Service\User\ApiTokenService;
use YiiRocks\Voyti\Api\StatelessClient\Auth\ApiLoginChallengeInterface;
use YiiRocks\Voyti\Api\StatelessClient\Controller\V1\Auth\AuthController;
use YiiRocks\Voyti\Api\StatelessClient\Service\ApiLoginCompletionService;
use YiiRocks\Voyti\Api\StatelessClient\tests\Support\CurrentUserTrait;
use YiiRocks\Voyti\Api\StatelessClient\tests\Support\DatabaseTestCase;
use YiiRocks\Voyti\Api\StatelessClient\tests\Support\EventCaptureDispatcher;
use YiiRocks\Voyti\Api\StatelessClient\tests\Support\ExpectsResponseTrait;
use YiiRocks\Voyti\Api\StatelessClient\tests\Support\TestPasswordHasherFactory;
use YiiRocks\Voyti\Api\StatelessClient\tests\Support\UserFactoryTrait;
use YiiRocks\Voyti\Api\StatelessClient\tests\Support\VoytiConfigFactory;
use YiiRocks\Voyti\Clock\SystemClock;
use YiiRocks\Voyti\Event\Auth\AfterLoginEvent;
use YiiRocks\Voyti\Event\Auth\BeforeLoginEvent;
use YiiRocks\Voyti\Event\Auth\FailedLoginEvent;
use YiiRocks\Voyti\Event\Auth\LogoutEvent;
use YiiRocks\Voyti\Exception\ActionPreventedException;
use YiiRocks\Voyti\Model\User;
use YiiRocks\Voyti\Model\UserToken;
use YiiRocks\Voyti\VoytiConfig;
use Yiisoft\DataResponse\ResponseFactory\DataResponseFactoryInterface;
use Yiisoft\Http\Status;
use Yiisoft\Security\PasswordHasher;

#[AllowMockObjectsWithoutExpectations]
final class AuthControllerTest extends DatabaseTestCase
{
    use CurrentUserTrait;
    use ExpectsResponseTrait;
    use UserFactoryTrait;

    private ApiTokenService $apiTokenService;
    private VoytiConfig $config;
    private EventCaptureDispatcher $eventDispatcher;
    private PasswordHasher $passwordHasher;
    private DataResponseFactoryInterface&MockObject $responseFactory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->apiTokenService = new ApiTokenService(new SystemClock());
        $this->eventDispatcher = new EventCaptureDispatcher();
        $this->passwordHasher = TestPasswordHasherFactory::create();
        $this->responseFactory = $this->createMock(DataResponseFactoryInterface::class);
        $this->config = VoytiConfigFactory::create();
    }

    public function testLogin(): void
    {
        $password = 'correct-password';
        $user = $this->createUser('loginuser', 'login@example.com', $this->passwordHasher->hash($password), confirmedAt: time());

        // Missing credentials: both empty
        $response = $this->expectResponse(['error' => 'Login and password are required.'], Status::BAD_REQUEST);
        self::assertSame($response, $this->createController()->login(new ServerRequest('POST', '/'), login: '', password: ''));
        self::assertTrue($this->eventDispatcher->hasEvent(FailedLoginEvent::class));
        self::assertSame('validation_failed', $this->lastFailedLoginReason());
        self::assertNull($this->lastFailedLoginEmail());

        // Missing credentials: only password empty
        $this->eventDispatcher = new EventCaptureDispatcher();
        $response = $this->expectResponse(['error' => 'Login and password are required.'], Status::BAD_REQUEST);
        self::assertSame($response, $this->createController()->login(new ServerRequest('POST', '/'), login: 'loginuser', password: ''));
        self::assertSame('validation_failed', $this->lastFailedLoginReason());
        self::assertSame('loginuser', $this->lastFailedLoginEmail());

        // Locked out (BeforeLoginEvent listener throws)
        $this->eventDispatcher = new EventCaptureDispatcher();
        $response = $this->expectResponse(['error' => 'Too many attempts'], Status::TOO_MANY_REQUESTS);
        $lockedOutController = $this->createController(beforeLoginThrows: true);
        self::assertSame($response, $lockedOutController->login(new ServerRequest('POST', '/'), login: 'loginuser', password: $password));
        self::assertSame('locked_out', $this->lastFailedLoginReason());

        // User not found
        $this->eventDispatcher = new EventCaptureDispatcher();
        $response = $this->expectResponse(['error' => 'Invalid login or password'], Status::UNAUTHORIZED);
        self::assertSame($response, $this->createController()->login(new ServerRequest('POST', '/'), login: 'nobody', password: $password));
        self::assertSame('user_not_found', $this->lastFailedLoginReason());

        // Invalid password
        $this->eventDispatcher = new EventCaptureDispatcher();
        $response = $this->expectResponse(['error' => 'Invalid login or password'], Status::UNAUTHORIZED);
        self::assertSame($response, $this->createController()->login(new ServerRequest('POST', '/'), login: 'loginuser', password: 'wrong'));
        self::assertSame('invalid_password', $this->lastFailedLoginReason());

        // Blocked account
        $this->eventDispatcher = new EventCaptureDispatcher();
        $blocked = $this->createUser('blockeduser', 'blocked@example.com', $this->passwordHasher->hash($password), confirmedAt: time(), blockedAt: time());
        $response = $this->expectResponse(['error' => 'Your account has been blocked'], Status::FORBIDDEN);
        self::assertSame($response, $this->createController()->login(new ServerRequest('POST', '/'), login: 'blockeduser', password: $password));
        self::assertSame('account_blocked', $this->lastFailedLoginReason());

        // Email confirmation required (no FailedLoginEvent dispatched, matching core's SessionController)
        $this->eventDispatcher = new EventCaptureDispatcher();
        $unconfirmed = $this->createUser('unconfirmeduser', 'unconfirmed@example.com', $this->passwordHasher->hash($password));
        $response = $this->expectResponse(['error' => 'You need to confirm your email address'], Status::FORBIDDEN);
        self::assertSame($response, $this->createController()->login(new ServerRequest('POST', '/'), login: 'unconfirmeduser', password: $password));
        self::assertFalse($this->eventDispatcher->hasEvent(FailedLoginEvent::class));

        // 2FA challenge intercepts
        $this->eventDispatcher = new EventCaptureDispatcher();
        $challenge = ['status' => 'challenge_required', 'method' => 'totp'];
        $response = $this->expectResponse($challenge, Status::ACCEPTED);
        $challengingController = $this->createController(challengeResult: $challenge);
        self::assertSame($response, $challengingController->login(new ServerRequest('POST', '/'), login: 'loginuser', password: $password));
        self::assertFalse($this->eventDispatcher->hasEvent(AfterLoginEvent::class));

        // Success
        $this->eventDispatcher = new EventCaptureDispatcher();
        $response = $this->expectResponse(
            $this->callback(static fn(array $data): bool => $data['status'] === 'ok' && strlen($data['token']) === 64),
            Status::OK,
        );
        self::assertSame($response, $this->createController()->login(new ServerRequest('POST', '/'), login: 'loginuser', password: $password));
        self::assertTrue($this->eventDispatcher->hasEvent(BeforeLoginEvent::class));
        self::assertTrue($this->eventDispatcher->hasEvent(AfterLoginEvent::class));
    }

    public function testLogout(): void
    {
        $user = $this->createUser('logoutuser', 'logout@example.com');
        $rawToken = $this->apiTokenService->generate($user);

        // Authenticated with a valid bearer token: revokes it and dispatches LogoutEvent
        $request = (new ServerRequest('POST', '/'))->withHeader('Authorization', 'Bearer ' . $rawToken);
        $response = $this->expectResponse(['message' => 'Logged out'], Status::OK);
        $controller = $this->createController(identity: $user);
        self::assertSame($response, $controller->logout($request));
        self::assertTrue($this->eventDispatcher->hasEvent(LogoutEvent::class));
        self::assertNull(UserToken::findByUserIdAndCode((int) $user->getId(), $rawToken));

        // No Authorization header: no-op, still responds
        $this->eventDispatcher = new EventCaptureDispatcher();
        $response = $this->expectResponse(['message' => 'Logged out'], Status::OK);
        $controller = $this->createController(identity: $user);
        self::assertSame($response, $controller->logout(new ServerRequest('POST', '/')));
        self::assertFalse($this->eventDispatcher->hasEvent(LogoutEvent::class));
    }

    private function createController(
        bool $beforeLoginThrows = false,
        ?array $challengeResult = null,
        ?User $identity = null,
    ): AuthController {
        $loginChallenges = [];
        if ($challengeResult !== null) {
            $loginChallenges[] = new class ($challengeResult) implements ApiLoginChallengeInterface {
                public function __construct(private array $result) {}

                public function challenge(User $user, ServerRequestInterface $request): ?array
                {
                    return $this->result;
                }
            };
        }

        if ($beforeLoginThrows) {
            $dispatcher = $this->eventDispatcher;
            $eventDispatcher = new class ($dispatcher) implements EventDispatcherInterface {
                public function __construct(private EventCaptureDispatcher $inner) {}

                public function dispatch(object $event): object
                {
                    $this->inner->dispatch($event);
                    if ($event instanceof BeforeLoginEvent) {
                        throw new ActionPreventedException('Too many attempts');
                    }
                    return $event;
                }
            };
        } else {
            $eventDispatcher = $this->eventDispatcher;
        }

        return new AuthController(
            new ApiLoginCompletionService($this->apiTokenService, $eventDispatcher, []),
            $this->apiTokenService,
            $this->createCurrentUser($identity),
            $this->responseFactory,
            $eventDispatcher,
            $loginChallenges,
            $this->passwordHasher,
            $this->config,
            $this->createTranslator(),
        );
    }

    private function lastFailedLoginEmail(): ?string
    {
        /** @var ?FailedLoginEvent $event */
        $event = $this->eventDispatcher->getEvent(FailedLoginEvent::class);
        return $event?->getEmail();
    }

    private function lastFailedLoginReason(): ?string
    {
        /** @var ?FailedLoginEvent $event */
        $event = $this->eventDispatcher->getEvent(FailedLoginEvent::class);
        return $event?->getReason();
    }
}
