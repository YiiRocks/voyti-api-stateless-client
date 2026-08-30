<?php

declare(strict_types=1);

namespace YiiRocks\Voyti\Api\StatelessClient\tests\Controller\V1\PasswordReset;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use YiiRocks\Voyti\Api\StatelessClient\Controller\V1\PasswordReset\PasswordResetController;
use YiiRocks\Voyti\Api\StatelessClient\tests\Support\DatabaseTestCase;
use YiiRocks\Voyti\Api\StatelessClient\tests\Support\EventCaptureDispatcher;
use YiiRocks\Voyti\Api\StatelessClient\tests\Support\ExpectsResponseTrait;
use YiiRocks\Voyti\Api\StatelessClient\tests\Support\MailCapture;
use YiiRocks\Voyti\Api\StatelessClient\tests\Support\TestPasswordHasherFactory;
use YiiRocks\Voyti\Api\StatelessClient\tests\Support\UserFactoryTrait;
use YiiRocks\Voyti\Api\StatelessClient\tests\Support\VoytiConfigFactory;
use YiiRocks\Voyti\Factory\UserTokenFactory;
use YiiRocks\Voyti\Model\UserToken;
use YiiRocks\Voyti\Service\MailService;
use YiiRocks\Voyti\Service\Password\PasswordHistoryService;
use YiiRocks\Voyti\Service\Password\RecoveryService;
use YiiRocks\Voyti\Service\Password\ResetService;
use YiiRocks\Voyti\VoytiConfig;
use Yiisoft\DataResponse\ResponseFactory\DataResponseFactoryInterface;
use Yiisoft\Http\Status;
use Yiisoft\Router\UrlGeneratorInterface;
use Yiisoft\Security\PasswordHasher;
use Yiisoft\View\View;

#[AllowMockObjectsWithoutExpectations]
final class PasswordResetControllerTest extends DatabaseTestCase
{
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

    public function testConfirm(): void
    {
        $user = $this->createUser('resetuser', 'reset@example.com', $this->passwordHasher->hash('old-password'));
        $rawCode = (new UserTokenFactory())->makeRecoveryToken((int) $user->getId());

        // Disabled
        $disabledConfig = VoytiConfigFactory::create(allowPasswordRecovery: false, allowAdminPasswordRecovery: false);
        $response = $this->expectResponse(['error' => 'Password reset is disabled.'], Status::FORBIDDEN);
        self::assertSame(
            $response,
            $this->createController($disabledConfig)->confirm(id: (int) $user->getId(), code: $rawCode, password: 'new-password123'),
        );

        // Invalid code
        $response = $this->expectResponse(['error' => 'Reset link is invalid or expired.'], Status::BAD_REQUEST);
        self::assertSame($response, $this->createController()->confirm(id: (int) $user->getId(), code: 'wrong-code', password: 'new-password123'));

        // Missing id (falls back to default 0, which can't match any real user token)
        $response = $this->expectResponse(['error' => 'Reset link is invalid or expired.'], Status::BAD_REQUEST);
        self::assertSame($response, $this->createController()->confirm(code: $rawCode, password: 'new-password123'));

        // Token whose user has been deleted since (orphaned, not expired): still rejected
        $orphanToken = (new UserTokenFactory())->makeRecoveryToken(999999);
        $response = $this->expectResponse(['error' => 'Reset link is invalid or expired.'], Status::BAD_REQUEST);
        self::assertSame($response, $this->createController()->confirm(id: 999999, code: $orphanToken, password: 'new-password123'));

        // Recently used password
        $response = $this->expectResponse(
            ['error' => 'This password has been used recently. Please choose a different one.'],
            Status::BAD_REQUEST,
        );
        self::assertSame(
            $response,
            $this->createController(VoytiConfigFactory::create(maxPasswordAge: 90))->confirm(id: (int) $user->getId(), code: $rawCode, password: 'old-password'),
        );

        // Success
        $response = $this->expectResponse(['message' => 'Password changed.'], Status::OK);
        self::assertSame($response, $this->createController()->confirm(id: (int) $user->getId(), code: $rawCode, password: 'new-password123'));
        self::assertNull(UserToken::findByUserIdAndCodeAndType((int) $user->getId(), $rawCode, UserToken::TYPE_RECOVERY));
    }

    public function testRequest(): void
    {
        // Disabled
        $response = $this->expectResponse(['error' => 'Password reset is disabled.'], Status::FORBIDDEN);
        self::assertSame(
            $response,
            $this->createController(VoytiConfigFactory::create(allowPasswordRecovery: false))->request(email: 'anyone@example.com'),
        );

        // Unknown address: generic non-enumerating success message
        $response = $this->expectResponse(['message' => 'If the email exists, a recovery message has been sent'], Status::OK);
        self::assertSame($response, $this->createController()->request(email: 'unknown@example.com'));

        // Known address: mail actually sent
        $this->createUser('recoveryuser', 'recovery@example.com', $this->passwordHasher->hash('old-password'));
        $response = $this->expectResponse(['message' => 'Recovery message sent'], Status::OK);
        self::assertSame($response, $this->createController()->request(email: 'recovery@example.com'));
        self::assertNotNull($this->mailer->getLastMessage());
    }

    private function createController(?VoytiConfig $config = null): PasswordResetController
    {
        $config ??= $this->config;
        $eventDispatcher = new EventCaptureDispatcher();
        $url = $this->createStub(UrlGeneratorInterface::class);
        $mailService = new MailService($this->mailer, '/tmp', new View(), $this->createTranslator(), $url, 'Test');

        return new PasswordResetController(
            new RecoveryService(new UserTokenFactory(), $mailService, $config, $this->createTranslator()),
            new ResetService($config, $eventDispatcher, new PasswordHistoryService($this->passwordHasher, $config)),
            $this->responseFactory,
            $config,
        );
    }
}
