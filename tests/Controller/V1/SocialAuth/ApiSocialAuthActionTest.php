<?php

declare(strict_types=1);

namespace YiiRocks\Voyti\Api\StatelessClient\tests\Controller\V1\SocialAuth;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Http\Server\RequestHandlerInterface;
use YiiRocks\Voyti\Api\StatelessClient\Controller\V1\SocialAuth\ApiSocialAuthAction;
use YiiRocks\Voyti\Api\StatelessClient\SocialAuth\ApiSocialAuthCallbackService;
use YiiRocks\Voyti\Api\StatelessClient\tests\Support\CurrentUserTrait;
use YiiRocks\Voyti\Api\StatelessClient\tests\Support\DatabaseTestCase;
use YiiRocks\Voyti\Api\StatelessClient\tests\Support\EventCaptureDispatcher;
use YiiRocks\Voyti\Api\StatelessClient\tests\Support\MailCapture;
use YiiRocks\Voyti\Api\StatelessClient\tests\Support\TestPasswordHasherFactory;
use YiiRocks\Voyti\Api\StatelessClient\tests\Support\VoytiConfigFactory;
use YiiRocks\Voyti\Clock\SystemClock;
use YiiRocks\Voyti\Service\Auth\LoginCompletionService;
use YiiRocks\Voyti\Service\MailService;
use YiiRocks\Voyti\Service\Password\PasswordHistoryService;
use YiiRocks\Voyti\Service\RememberMeCookieService;
use YiiRocks\Voyti\Service\User\UserCreationHelper;
use YiiRocks\Voyti\SocialAuth\Http\AuthActionRequestHolder;
use YiiRocks\Voyti\SocialAuth\Service\Auth\PendingSocialAccountService;
use YiiRocks\Voyti\SocialAuth\Service\Auth\SocialAuthClientReturnUrlConfigurator;
use YiiRocks\Voyti\SocialAuth\Service\Auth\SocialUserAttributesNormalizer;
use YiiRocks\Voyti\SocialAuth\Service\Auth\UserSocialAuthenticateService;
use Yiisoft\Aliases\Aliases;
use Yiisoft\DataResponse\ResponseFactory\DataResponseFactory;
use Yiisoft\Router\CurrentRoute;
use Yiisoft\Router\Route;
use Yiisoft\Router\UrlGeneratorInterface;
use Yiisoft\Session\SessionInterface;
use Yiisoft\View\View;
use Yiisoft\View\WebView;
use Yiisoft\Yii\AuthClient\Collection;
use Yiisoft\Yii\AuthClient\OAuth2Interface;

#[AllowMockObjectsWithoutExpectations]
final class ApiSocialAuthActionTest extends DatabaseTestCase
{
    use CurrentUserTrait;

    public function testProcessConfiguresReturnUrlAndBuildsAuthUrl(): void
    {
        $client = $this->createMock(OAuth2Interface::class);
        $client->method('getOauth2ReturnUrl')->willReturn('');
        $client->expects($this->once())
            ->method('setOauth2ReturnUrl')
            ->with('https://host.example.com/v1/auth/social/github');
        $client->method('buildAuthUrl')->willReturn('https://provider.example.com/authorize');

        $action = $this->createAction($client, expectedReturnUrlRouteName: 'voyti/api-v1-auth-social-callback');
        $request = $this->requestFor('github');

        $response = $action->process($request, $this->createStub(RequestHandlerInterface::class));

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('https://provider.example.com/authorize', $response->getHeaderLine('Location'));
    }

    public function testProcessDelegatesCancelToCallbackService(): void
    {
        $client = $this->createMock(OAuth2Interface::class);
        $client->method('getOauth2ReturnUrl')->willReturn('https://already-configured.example.com');
        $client->method('getName')->willReturn('github');

        $action = $this->createAction($client, redirectUrl: 'https://spa.example.com/callback');
        $request = $this->requestFor('github', ['error' => 'access_denied']);

        $response = $action->process($request, $this->createStub(RequestHandlerInterface::class));

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('https://spa.example.com/callback?error=cancelled', $response->getHeaderLine('Location'));
    }

    private function buildCallbackService(string $redirectUrl): ApiSocialAuthCallbackService
    {
        $config = VoytiConfigFactory::create();
        $eventDispatcher = new EventCaptureDispatcher();
        $passwordHasher = TestPasswordHasherFactory::create();
        $currentUser = $this->createCurrentUser();
        $session = $this->createStub(SessionInterface::class);
        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $mailService = new MailService(new MailCapture(), '/tmp', new View(), $this->createTranslator(), $urlGenerator, 'Test');
        $passwordHistoryService = new PasswordHistoryService($passwordHasher, $config);
        $userCreationHelper = new UserCreationHelper($mailService, $eventDispatcher, $passwordHasher, $config, $passwordHistoryService, $this->createTranslator());
        $requestHolder = new AuthActionRequestHolder();
        $requestHolder->setRequest(new ServerRequest('GET', '/'));
        $loginCompletionService = new LoginCompletionService(
            $currentUser,
            $eventDispatcher,
            new Psr17Factory(),
            new RememberMeCookieService(3600, new SystemClock()),
            [],
            $session,
            $urlGenerator,
            $config,
        );
        $socialAuthenticateService = new UserSocialAuthenticateService(
            $config,
            true,
            $requestHolder,
            $loginCompletionService,
            $session,
            $userCreationHelper,
            new PendingSocialAccountService($session, false),
            $this->createTranslator(),
        );

        return new ApiSocialAuthCallbackService(
            $currentUser,
            new SocialUserAttributesNormalizer(),
            new DataResponseFactory(new Psr17Factory()),
            $redirectUrl,
            $socialAuthenticateService,
            $this->createTranslator(),
        );
    }

    private function createAction(
        OAuth2Interface&MockObject $client,
        string $redirectUrl = 'https://spa.example.com/callback',
        ?string $expectedReturnUrlRouteName = null,
    ): ApiSocialAuthAction {
        $collection = new Collection(['github' => $client]);
        $currentRoute = new CurrentRoute();
        $currentRoute->setRouteWithArguments(Route::get('/social/{authclient}'), ['authclient' => 'github']);
        $url = $this->createMock(UrlGeneratorInterface::class);
        if ($expectedReturnUrlRouteName !== null) {
            $url->expects($this->once())
                ->method('generateAbsolute')
                ->with($expectedReturnUrlRouteName, ['authclient' => 'github'])
                ->willReturn('https://host.example.com/v1/auth/social/github');
        }

        return new ApiSocialAuthAction(
            new Aliases(),
            $this->buildCallbackService($redirectUrl),
            $collection,
            $currentRoute,
            new Psr17Factory(),
            new SocialAuthClientReturnUrlConfigurator($url, false),
            new WebView(),
        );
    }

    /**
     * @param array<string, string> $queryParams
     */
    private function requestFor(string $authClient, array $queryParams = []): ServerRequest
    {
        return (new ServerRequest('GET', '/v1/auth/social/' . $authClient))->withQueryParams($queryParams);
    }
}
