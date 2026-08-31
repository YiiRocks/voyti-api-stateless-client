<?php

declare(strict_types=1);

namespace YiiRocks\Voyti\Api\StatelessClient\tests\SocialAuth;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use YiiRocks\Voyti\Api\StatelessClient\SocialAuth\ApiSocialAuthCallbackService;
use YiiRocks\Voyti\Api\StatelessClient\tests\Support\CurrentUserTrait;
use YiiRocks\Voyti\Api\StatelessClient\tests\Support\DatabaseTestCase;
use YiiRocks\Voyti\Api\StatelessClient\tests\Support\EventCaptureDispatcher;
use YiiRocks\Voyti\Api\StatelessClient\tests\Support\MailCapture;
use YiiRocks\Voyti\Api\StatelessClient\tests\Support\TestPasswordHasherFactory;
use YiiRocks\Voyti\Api\StatelessClient\tests\Support\UserFactoryTrait;
use YiiRocks\Voyti\Api\StatelessClient\tests\Support\VoytiConfigFactory;
use YiiRocks\Voyti\Clock\SystemClock;
use YiiRocks\Voyti\Model\User;
use YiiRocks\Voyti\Model\UserToken;
use YiiRocks\Voyti\Service\Auth\LoginCompletionService;
use YiiRocks\Voyti\Service\MailService;
use YiiRocks\Voyti\Service\Password\PasswordHistoryService;
use YiiRocks\Voyti\Service\RememberMeCookieService;
use YiiRocks\Voyti\Service\User\UserCreationHelper;
use YiiRocks\Voyti\SocialAuth\Http\AuthActionRequestHolder;
use YiiRocks\Voyti\SocialAuth\Model\UserSocialAccount;
use YiiRocks\Voyti\SocialAuth\Service\Auth\PendingSocialAccountService;
use YiiRocks\Voyti\SocialAuth\Service\Auth\SocialUserAttributesNormalizer;
use YiiRocks\Voyti\SocialAuth\Service\Auth\UserSocialAuthenticateService;
use YiiRocks\Voyti\VoytiConfig;
use Yiisoft\DataResponse\DataStream\DataStream;
use Yiisoft\DataResponse\ResponseFactory\DataResponseFactory;
use Yiisoft\Router\UrlGeneratorInterface;
use Yiisoft\Security\PasswordHasher;
use Yiisoft\Session\SessionInterface;
use Yiisoft\View\View;
use Yiisoft\Yii\AuthClient\AuthClientInterface;

#[AllowMockObjectsWithoutExpectations]
final class ApiSocialAuthCallbackServiceTest extends DatabaseTestCase
{
    use CurrentUserTrait;
    use UserFactoryTrait;

    private VoytiConfig $config;
    private EventCaptureDispatcher $eventDispatcher;
    private PasswordHasher $passwordHasher;
    private SessionInterface $session;

    protected function setUp(): void
    {
        parent::setUp();
        $this->config = VoytiConfigFactory::create();
        $this->eventDispatcher = new EventCaptureDispatcher();
        $this->passwordHasher = TestPasswordHasherFactory::create();
        $this->session = $this->createStub(SessionInterface::class);
    }

    public function testHandleCancel(): void
    {
        $response = $this->createService('https://spa.example.com/callback')->handleCancel($this->client('github'));

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('https://spa.example.com/callback?error=cancelled', $response->getHeaderLine('Location'));
    }

    public function testHandleCancelWithNoRedirectUrlConfigured(): void
    {
        $response = $this->createService('')->handleCancel($this->client('github'));

        self::assertSame(500, $response->getStatusCode());
        self::assertInstanceOf(DataStream::class, $response->getBody());
        self::assertSame(
            ['error' => 'Social auth redirect URL is not configured.'],
            $response->getBody()->getData(),
        );
    }

    public function testHandleSuccessAlreadyAuthenticated(): void
    {
        $user = $this->createUser('alreadyauth', 'alreadyauth@example.com');
        $service = $this->createService('https://spa.example.com/callback', $user);

        $response = $service->handleSuccess($this->client('github'));

        self::assertSame('https://spa.example.com/callback?error=already_authenticated', $response->getHeaderLine('Location'));
    }

    public function testHandleSuccessFailure(): void
    {
        // Registration disabled -> the underlying authenticate service fails.
        $service = $this->createService('https://spa.example.com/callback', null, enableSocialAuthRegistration: false);

        $response = $service->handleSuccess($this->client('github'));

        self::assertSame(302, $response->getStatusCode());
        self::assertSame(
            'https://spa.example.com/callback?error=voyti.social.registration_disabled',
            $response->getHeaderLine('Location'),
        );
    }

    public function testHandleSuccessIssuesExchangeCode(): void
    {
        $user = $this->createUser('sociallinked', 'sociallinked@example.com');
        $this->createSocialAccount(userId: (int) $user->getId());

        $service = $this->createService('https://spa.example.com/callback');
        $response = $service->handleSuccess($this->client('github'));

        self::assertSame(302, $response->getStatusCode());
        $location = $response->getHeaderLine('Location');
        self::assertStringStartsWith('https://spa.example.com/callback?code=', $location);

        $code = substr($location, strlen('https://spa.example.com/callback?code='));
        self::assertSame(32, strlen($code));
        $stored = UserToken::findByUserIdAndCodeAndType((int) $user->getId(), $code, UserToken::TYPE_API_SOCIAL_EXCHANGE);
        self::assertNotNull($stored);
        self::assertGreaterThan(0, $stored->getCreatedAt());
    }

    public function testHandleSuccessPendingConnection(): void
    {
        // A fresh guest login with no matching email and registration disabled at the account-creation
        // layer still succeeds at finding/creating the account, but with no linked user and no code
        // (connection_unavailable) - already covered by testHandleSuccessFailure's failure path.
        // A genuinely pending (unlinked, has a code) account is exercised by pre-creating one below.
        $this->createSocialAccount(userId: null, code: 'connect-code');

        $service = $this->createService('https://spa.example.com/callback');
        $client = $this->client('github', 'client123');

        $response = $service->handleSuccess($client);

        self::assertSame('https://spa.example.com/callback?error=connection_pending_registration_required', $response->getHeaderLine('Location'));
    }

    private function client(string $name, string $clientId = 'client123'): AuthClientInterface&MockObject
    {
        $client = $this->createMock(AuthClientInterface::class);
        $client->method('getName')->willReturn($name);
        $client->method('getUserAttributes')->willReturn(['id' => $clientId, 'email' => null, 'username' => null, 'name' => null]);

        return $client;
    }

    private function createService(
        string $redirectUrl,
        ?User $identity = null,
        bool $enableSocialAuthRegistration = true,
    ): ApiSocialAuthCallbackService {
        $currentUser = $this->createCurrentUser($identity);
        $url = $this->createStub(UrlGeneratorInterface::class);
        $responseFactory = new Psr17Factory();
        $mailService = new MailService(new MailCapture(), '/tmp', new View(), $this->createTranslator(), $url, 'Test');
        $passwordHistoryService = new PasswordHistoryService($this->passwordHasher, $this->config);
        $userCreationHelper = new UserCreationHelper($mailService, $this->eventDispatcher, $this->passwordHasher, $this->config, $passwordHistoryService, $this->createTranslator());
        $requestHolder = new AuthActionRequestHolder();
        $requestHolder->setRequest(new ServerRequest('GET', '/'));
        $loginCompletionService = new LoginCompletionService(
            $currentUser,
            $this->eventDispatcher,
            $responseFactory,
            new RememberMeCookieService(3600, new SystemClock()),
            [],
            $this->session,
            $url,
            $this->config,
        );
        $pendingSocialAccountService = new PendingSocialAccountService($this->session, false);

        $socialAuthenticateService = new UserSocialAuthenticateService(
            $this->config,
            $enableSocialAuthRegistration,
            $requestHolder,
            $loginCompletionService,
            $this->session,
            $userCreationHelper,
            $pendingSocialAccountService,
            $this->createTranslator(),
        );

        return new ApiSocialAuthCallbackService(
            $currentUser,
            new SocialUserAttributesNormalizer(),
            new DataResponseFactory($responseFactory),
            $redirectUrl,
            $socialAuthenticateService,
            $this->createTranslator(),
        );
    }

    private function createSocialAccount(?int $userId, ?string $code = null, string $clientId = 'client123'): UserSocialAccount
    {
        $account = new UserSocialAccount();
        $account->setProvider('github');
        $account->setClientId($clientId);
        $account->setUserId($userId);
        $account->setCode($code);
        $account->setCreatedAt(time());
        $account->save();

        return $account;
    }
}
