<?php

declare(strict_types=1);

namespace YiiRocks\Voyti\Api\StatelessClient\Controller\V1\Auth;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use YiiRocks\Voyti\Api\Service\User\ApiTokenService;
use YiiRocks\Voyti\Api\StatelessClient\Auth\ApiLoginChallengeInterface;
use YiiRocks\Voyti\Api\StatelessClient\Service\ApiLoginCompletionService;
use YiiRocks\Voyti\Event\Auth\BeforeLoginEvent;
use YiiRocks\Voyti\Event\Auth\FailedLoginEvent;
use YiiRocks\Voyti\Event\Auth\LogoutEvent;
use YiiRocks\Voyti\Exception\ActionPreventedException;
use YiiRocks\Voyti\Model\User;
use YiiRocks\Voyti\VoytiConfig;
use Yiisoft\DataResponse\ResponseFactory\DataResponseFactoryInterface;
use Yiisoft\Http\Header;
use Yiisoft\Http\Status;
use Yiisoft\Input\Http\Attribute\Parameter\Body;
use Yiisoft\Security\PasswordHasher;
use Yiisoft\Translator\TranslatorInterface;
use Yiisoft\User\CurrentUser;

/**
 * Credential-based login/logout for the SPA API: verifies username/email + password and issues a
 * bearer token (via {@see ApiTokenService}) instead of establishing a PHP session, replicating the
 * same event sequence core's `SessionController::login()` dispatches so listeners bound to those
 * events (session/audit-log tracking, `voyti-lockout`'s brute-force protection) keep working
 * unmodified regardless of whether a login came from the HTML app or this API.
 */
final readonly class AuthController
{
    private const string BEARER_PATTERN = '/^Bearer\s+(.*?)$/';

    public function __construct(
        private ApiLoginCompletionService $apiLoginCompletionService,
        private ApiTokenService $apiTokenService,
        private CurrentUser $currentUser,
        private DataResponseFactoryInterface $responseFactory,
        private EventDispatcherInterface $eventDispatcher,
        /** @var iterable<ApiLoginChallengeInterface> */
        private iterable $loginChallenges,
        private PasswordHasher $passwordHasher,
        private VoytiConfig $config,
        private TranslatorInterface $translator,
    ) {}

    public function login(
        ServerRequestInterface $request,
        #[Body('login')]
        string $login = '',
        #[Body('password')]
        string $password = '',
    ): ResponseInterface {
        $serverParams = $request->getServerParams();

        if ($login === '' || $password === '') {
            $this->eventDispatcher->dispatch(
                new FailedLoginEvent($login !== '' ? $login : null, 'validation_failed', $serverParams),
            );
            return $this->responseFactory->createResponse(
                [
                    'error' => $this->translator->translate(
                        'voyti-api-stateless-client.auth.login_password_required',
                        category: 'voyti-api-stateless-client',
                    ),
                ],
                Status::BAD_REQUEST,
            );
        }

        $user = User::findByUsernameOrEmail($login);

        try {
            $this->eventDispatcher->dispatch(new BeforeLoginEvent($user, $serverParams));
        } catch (ActionPreventedException $exception) {
            $this->eventDispatcher->dispatch(new FailedLoginEvent($login, 'locked_out', $serverParams));
            return $this->responseFactory->createResponse(['error' => $exception->getMessage()], Status::TOO_MANY_REQUESTS);
        }

        if ($user === null) {
            $this->eventDispatcher->dispatch(new FailedLoginEvent($login, 'user_not_found', $serverParams));
            return $this->responseFactory->createResponse(
                ['error' => $this->translator->translate('voyti.security.invalid_login', category: 'voyti')],
                Status::UNAUTHORIZED,
            );
        }

        if (!$this->passwordHasher->validate($password, $user->getPasswordHash())) {
            $this->eventDispatcher->dispatch(new FailedLoginEvent($login, 'invalid_password', $serverParams));
            return $this->responseFactory->createResponse(
                ['error' => $this->translator->translate('voyti.security.invalid_login', category: 'voyti')],
                Status::UNAUTHORIZED,
            );
        }

        if ($user->isBlocked()) {
            $this->eventDispatcher->dispatch(new FailedLoginEvent($login, 'account_blocked', $serverParams));
            return $this->responseFactory->createResponse(
                ['error' => $this->translator->translate('voyti.security.account_blocked', category: 'voyti')],
                Status::FORBIDDEN,
            );
        }

        if ($this->config->enableEmailConfirmation && !$user->isConfirmed()) {
            return $this->responseFactory->createResponse(
                ['error' => $this->translator->translate('voyti.security.need_email_confirmation', category: 'voyti')],
                Status::FORBIDDEN,
            );
        }

        foreach ($this->loginChallenges as $loginChallenge) {
            $challenge = $loginChallenge->challenge($user, $request);
            if ($challenge !== null) {
                return $this->responseFactory->createResponse($challenge, Status::ACCEPTED);
            }
        }

        $token = $this->apiLoginCompletionService->complete($user, $request);

        return $this->responseFactory->createResponse(['status' => 'ok', 'token' => $token]);
    }

    public function logout(ServerRequestInterface $request): ResponseInterface
    {
        $identity = $this->currentUser->getIdentity();
        $rawToken = $this->extractBearerToken($request);

        if ($identity instanceof User && $rawToken !== null) {
            $this->apiTokenService->revokeCurrent($identity, $rawToken);
            $this->eventDispatcher->dispatch(new LogoutEvent($identity, ''));
        }

        return $this->responseFactory->createResponse(
            ['message' => $this->translator->translate('voyti.security.logged_out', category: 'voyti')],
        );
    }

    private function extractBearerToken(ServerRequestInterface $request): ?string
    {
        $header = $request->getHeaderLine(Header::AUTHORIZATION);

        if (preg_match(self::BEARER_PATTERN, $header, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }
}
