<?php

declare(strict_types=1);

namespace YiiRocks\Voyti\Api\StatelessClient\tests\Controller\V1\Me;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\EventDispatcher\EventDispatcherInterface;
use YiiRocks\Voyti\Api\StatelessClient\Controller\V1\Me\MeController;
use YiiRocks\Voyti\Api\StatelessClient\tests\Support\CurrentUserTrait;
use YiiRocks\Voyti\Api\StatelessClient\tests\Support\DatabaseTestCase;
use YiiRocks\Voyti\Api\StatelessClient\tests\Support\EventCaptureDispatcher;
use YiiRocks\Voyti\Api\StatelessClient\tests\Support\ExpectsResponseTrait;
use YiiRocks\Voyti\Api\StatelessClient\tests\Support\MailCapture;
use YiiRocks\Voyti\Api\StatelessClient\tests\Support\TestPasswordHasherFactory;
use YiiRocks\Voyti\Api\StatelessClient\tests\Support\UserFactoryTrait;
use YiiRocks\Voyti\Api\StatelessClient\tests\Support\VoytiConfigFactory;
use YiiRocks\Voyti\Clock\SystemClock;
use YiiRocks\Voyti\Event\User\BeforeAccountUpdateEvent;
use YiiRocks\Voyti\Exception\ActionPreventedException;
use YiiRocks\Voyti\Factory\UserTokenFactory;
use YiiRocks\Voyti\Model\User;
use YiiRocks\Voyti\Service\EmailChangeService;
use YiiRocks\Voyti\Service\MailService;
use YiiRocks\Voyti\Service\Password\PasswordHistoryService;
use YiiRocks\Voyti\Service\User\UserUpdateHelper;
use YiiRocks\Voyti\VoytiConfig;
use Yiisoft\DataResponse\ResponseFactory\DataResponseFactoryInterface;
use Yiisoft\Http\Status;
use Yiisoft\Router\UrlGeneratorInterface;
use Yiisoft\Security\PasswordHasher;
use Yiisoft\View\View;

#[AllowMockObjectsWithoutExpectations]
final class MeControllerTest extends DatabaseTestCase
{
    use CurrentUserTrait;
    use ExpectsResponseTrait;
    use UserFactoryTrait;

    private VoytiConfig $config;
    private MailCapture $mailer;
    private PasswordHasher $passwordHasher;
    private DataResponseFactoryInterface&MockObject $responseFactory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->config = VoytiConfigFactory::create();
        $this->mailer = new MailCapture();
        $this->passwordHasher = TestPasswordHasherFactory::create();
        $this->responseFactory = $this->createMock(DataResponseFactoryInterface::class);
    }

    public function testShow(): void
    {
        $user = $this->createUser('meuser', 'me@example.com', confirmedAt: time());

        $response = $this->expectResponse([
            'id' => $user->getId(),
            'username' => 'meuser',
            'email' => 'me@example.com',
            'unconfirmedEmail' => null,
            'createdAt' => $user->getCreatedAt(),
            'confirmedAt' => $user->getConfirmedAt(),
            'lastLoginAt' => null,
        ], Status::OK);

        self::assertSame($response, $this->createController($user)->show());
    }

    public function testUpdate(): void
    {
        $user = $this->createUser('updateuser', 'update@example.com', $this->passwordHasher->hash('old-password'));

        // Recently used password, config gates the check
        $response = $this->expectResponse(
            ['error' => 'This password has been used recently. Please choose a different one.'],
            Status::BAD_REQUEST,
        );
        self::assertSame(
            $response,
            $this->createController($user, VoytiConfigFactory::create(maxPasswordAge: 90))->update(password: 'old-password'),
        );

        // New, not-previously-used password: proceeds (kills LogicalAnd -> LogicalOr on the guard above)
        $response = $this->expectResponse([
            'id' => $user->getId(),
            'username' => 'updateuser',
            'email' => 'update@example.com',
            'unconfirmedEmail' => null,
            'message' => 'Account has been updated',
        ], Status::OK);
        self::assertSame($response, $this->createController($user)->update(password: 'brand-new-password'));

        // Username change only
        $response = $this->expectResponse([
            'id' => $user->getId(),
            'username' => 'renameduser',
            'email' => 'update@example.com',
            'unconfirmedEmail' => null,
            'message' => 'Account has been updated',
        ], Status::OK);
        self::assertSame($response, $this->createController($user)->update(username: 'renameduser'));
        self::assertSame('renameduser', $user->getUsername());

        // Email change is not applied immediately - it's routed through EmailChangeService
        $response = $this->expectResponse([
            'id' => $user->getId(),
            'username' => 'renameduser',
            'email' => 'update@example.com',
            'unconfirmedEmail' => 'new@example.com',
            'message' => 'Account has been updated',
        ], Status::OK);
        self::assertSame($response, $this->createController($user)->update(email: 'new@example.com'));
        self::assertSame('update@example.com', $user->getEmail());
        self::assertSame('new@example.com', $user->getUnconfirmedEmail());
        self::assertNotNull($this->mailer->getLastMessage());

        // A BeforeAccountUpdateEvent listener rejecting the change
        $response = $this->expectResponse(['error' => 'Update rejected'], Status::BAD_REQUEST);
        self::assertSame($response, $this->createController($user, rejectUpdate: true)->update(username: 'blockeduser'));
        self::assertSame('renameduser', $user->getUsername());
    }

    private function createController(User $user, ?VoytiConfig $config = null, bool $rejectUpdate = false): MeController
    {
        $config ??= $this->config;
        $url = $this->createStub(UrlGeneratorInterface::class);
        $mailService = new MailService($this->mailer, '/tmp', new View(), $this->createTranslator(), $url, 'Test');
        $eventDispatcher = $rejectUpdate ? $this->createRejectingDispatcher() : new EventCaptureDispatcher();

        return new MeController(
            $config,
            $this->createCurrentUser($user),
            new EmailChangeService($config, new UserTokenFactory(), $mailService),
            new PasswordHistoryService($this->passwordHasher, $config),
            new UserUpdateHelper(new SystemClock(), $eventDispatcher, new PasswordHistoryService($this->passwordHasher, $config)),
            $this->responseFactory,
            $this->createTranslator(),
        );
    }

    private function createRejectingDispatcher(): EventDispatcherInterface
    {
        return new class implements EventDispatcherInterface {
            public function dispatch(object $event): object
            {
                if ($event instanceof BeforeAccountUpdateEvent) {
                    throw new ActionPreventedException('Update rejected');
                }
                return $event;
            }
        };
    }
}
