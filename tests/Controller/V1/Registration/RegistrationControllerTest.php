<?php

declare(strict_types=1);

namespace YiiRocks\Voyti\Api\StatelessClient\tests\Controller\V1\Registration;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use YiiRocks\Voyti\Api\StatelessClient\Controller\V1\Registration\RegistrationController;
use YiiRocks\Voyti\Api\StatelessClient\tests\Support\DatabaseTestCase;
use YiiRocks\Voyti\Api\StatelessClient\tests\Support\EventCaptureDispatcher;
use YiiRocks\Voyti\Api\StatelessClient\tests\Support\ExpectsResponseTrait;
use YiiRocks\Voyti\Api\StatelessClient\tests\Support\MailCapture;
use YiiRocks\Voyti\Api\StatelessClient\tests\Support\TestPasswordHasherFactory;
use YiiRocks\Voyti\Api\StatelessClient\tests\Support\UserFactoryTrait;
use YiiRocks\Voyti\Api\StatelessClient\tests\Support\VoytiConfigFactory;
use YiiRocks\Voyti\Auth\PostRegistrationHookInterface;
use YiiRocks\Voyti\Factory\UserTokenFactory;
use YiiRocks\Voyti\Model\User;
use YiiRocks\Voyti\Service\MailService;
use YiiRocks\Voyti\Service\Password\PasswordHistoryService;
use YiiRocks\Voyti\Service\User\ConfirmationService;
use YiiRocks\Voyti\Service\User\RegisterService;
use YiiRocks\Voyti\Service\User\UserCreationHelper;
use YiiRocks\Voyti\VoytiConfig;
use Yiisoft\DataResponse\ResponseFactory\DataResponseFactoryInterface;
use Yiisoft\Http\Status;
use Yiisoft\Router\UrlGeneratorInterface;
use Yiisoft\View\View;

#[AllowMockObjectsWithoutExpectations]
final class RegistrationControllerTest extends DatabaseTestCase
{
    use ExpectsResponseTrait;
    use UserFactoryTrait;

    private VoytiConfig $config;
    private MailCapture $mailer;
    private DataResponseFactoryInterface&MockObject $responseFactory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->config = VoytiConfigFactory::create();
        $this->mailer = new MailCapture();
        $this->responseFactory = $this->createMock(DataResponseFactoryInterface::class);
    }

    public function testConfirm(): void
    {
        $userTokenFactory = new UserTokenFactory();
        $confirmedUser = $this->createUser('confirmeduser', 'confirmed@example.com', confirmedAt: time());
        $unconfirmedUser = $this->createUser('unconfirmeduser', 'unconfirmed@example.com');
        $rawCode = $userTokenFactory->makeConfirmationToken((int) $unconfirmedUser->getId());

        // User not found
        $response = $this->expectResponse(['error' => 'Invalid confirmation link'], Status::BAD_REQUEST);
        self::assertSame($response, $this->createController()->confirm(999999, 'anycode'));

        // Already confirmed
        $response = $this->expectResponse(['message' => 'Account already confirmed.'], Status::OK);
        self::assertSame($response, $this->createController()->confirm((int) $confirmedUser->getId(), 'anycode'));

        // Invalid code
        $response = $this->expectResponse(['error' => 'The confirmation link is invalid or expired.'], Status::BAD_REQUEST);
        self::assertSame($response, $this->createController()->confirm((int) $unconfirmedUser->getId(), 'wrong-code'));

        // Valid code
        $response = $this->expectResponse(['message' => 'Account confirmed.'], Status::OK);
        self::assertSame($response, $this->createController()->confirm((int) $unconfirmedUser->getId(), $rawCode));
        self::assertTrue(User::findById((int) $unconfirmedUser->getId())?->isConfirmed());
    }

    public function testRegister(): void
    {
        // Registration disabled
        $disabledConfig = VoytiConfigFactory::create(enableRegistration: false);
        $response = $this->expectResponse(['error' => 'Registration is disabled'], Status::FORBIDDEN);
        self::assertSame(
            $response,
            $this->createController($disabledConfig)->register(new ServerRequest('POST', '/'), username: 'a', email: 'a@example.com', password: 'password123'),
        );

        // Uniqueness conflict
        $this->createUser('existinguser', 'existing@example.com');
        $response = $this->expectResponse(
            ['error' => 'Email already exists', 'errors' => ['Email already exists']],
            Status::BAD_REQUEST,
        );
        self::assertSame(
            $response,
            $this->createController()->register(new ServerRequest('POST', '/'), username: 'newuser', email: 'existing@example.com', password: 'password123'),
        );

        // Success, with a post-registration hook run against the newly created user
        $handledUsers = [];
        $hook = new class ($handledUsers) implements PostRegistrationHookInterface {
            public array $handledUsers = [];

            public function handle(User $user): void
            {
                $this->handledUsers[] = $user->getEmail();
            }
        };
        $response = $this->expectResponse(
            ['message' => 'Account created. Check your email for the confirmation link.'],
            Status::CREATED,
        );
        self::assertSame(
            $response,
            $this->createController(postRegistrationHooks: [$hook])->register(
                new ServerRequest('POST', '/'),
                username: 'newuser',
                email: 'new@example.com',
                password: 'password123',
            ),
        );
        self::assertSame(['new@example.com'], $hook->handledUsers);
        self::assertSame('newuser', User::findByEmail('new@example.com')?->getUsername());
    }

    public function testResend(): void
    {
        // Disabled
        $disabledConfig = VoytiConfigFactory::create(enableEmailConfirmation: false);
        $response = $this->expectResponse(['error' => 'Email confirmation is disabled'], Status::FORBIDDEN);
        self::assertSame($response, $this->createController($disabledConfig)->resend(email: 'anyone@example.com'));

        // Unknown address: same generic message, no enumeration
        $response = $this->expectResponse(
            ['message' => 'Confirmation email sent if the account exists and is unconfirmed.'],
            Status::OK,
        );
        self::assertSame($response, $this->createController()->resend(email: 'unknown@example.com'));

        // Known, unconfirmed address: same generic message, mail actually sent
        $user = $this->createUser('resenduser', 'resend@example.com');
        $response = $this->expectResponse(
            ['message' => 'Confirmation email sent if the account exists and is unconfirmed.'],
            Status::OK,
        );
        self::assertSame($response, $this->createController()->resend(email: 'resend@example.com'));
        self::assertNotNull($this->mailer->getLastMessage());
    }

    /**
     * @param list<PostRegistrationHookInterface> $postRegistrationHooks
     */
    private function createController(?VoytiConfig $config = null, array $postRegistrationHooks = []): RegistrationController
    {
        $config ??= $this->config;
        $passwordHasher = TestPasswordHasherFactory::create();
        $eventDispatcher = new EventCaptureDispatcher();
        $url = $this->createStub(UrlGeneratorInterface::class);
        $mailService = new MailService($this->mailer, '/tmp', new View(), $this->createTranslator(), $url, 'Test');
        $passwordHistoryService = new PasswordHistoryService($passwordHasher, $config);
        $userCreationHelper = new UserCreationHelper($mailService, $eventDispatcher, $passwordHasher, $config, $passwordHistoryService, $this->createTranslator());

        return new RegistrationController(
            $config,
            new ConfirmationService($eventDispatcher, new UserTokenFactory(), $mailService),
            $postRegistrationHooks,
            new RegisterService($eventDispatcher, $userCreationHelper, $config),
            $this->responseFactory,
            $this->createTranslator(),
        );
    }
}
