<?php

declare(strict_types=1);

namespace YiiRocks\Voyti\Api\StatelessClient\SocialAuth;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use YiiRocks\Voyti\Api\StatelessClient\Controller\V1\SocialAuth\ApiSocialAuthAction;
use YiiRocks\Voyti\Api\StatelessClient\Controller\V1\SocialAuth\SocialExchangeController;
use YiiRocks\Voyti\Model\User;
use YiiRocks\Voyti\Model\UserToken;
use YiiRocks\Voyti\SocialAuth\Service\Auth\SocialAuthCallbackService;
use YiiRocks\Voyti\SocialAuth\Service\Auth\SocialUserAttributesNormalizer;
use YiiRocks\Voyti\SocialAuth\Service\Auth\UserSocialAuthenticateService;
use Yiisoft\Http\Header;
use Yiisoft\Http\Status;
use Yiisoft\Security\Random;
use Yiisoft\User\CurrentUser;
use Yiisoft\User\Guest\GuestIdentityInterface;
use Yiisoft\Yii\AuthClient\AuthClientInterface;

/**
 * Success/cancel callbacks for {@see ApiSocialAuthAction}'s
 * `AuthAction`: a popup-friendly variant of `voyti-social-auth`'s own
 * {@see SocialAuthCallbackService}. Instead of finalizing
 * an HTML session, a successful login redirects the popup to the configured SPA URL with a
 * short-lived one-time exchange code ({@see UserToken::TYPE_API_SOCIAL_EXCHANGE}) - never the
 * bearer token itself, which the SPA fetches separately via
 * {@see SocialExchangeController} so it never
 * appears in a URL or browser history entry.
 *
 * Only the guest-login path is supported: connecting a social account to an already-authenticated
 * user, and completing a "pending" account link that needs manual registration first, both require
 * request context (an existing bearer session, a registration form) this stateless popup callback
 * doesn't have - both are reported to the SPA as an error instead.
 */
final readonly class ApiSocialAuthCallbackService
{
    /**
     * Matches {@see SocialExchangeController}'s
     * expectation: long enough for the popup's redirect and the SPA's follow-up request, short
     * enough that a leaked code in a redirected URL isn't useful for long.
     */
    public const int EXCHANGE_LIFESPAN = 60;

    public function __construct(
        private CurrentUser $currentUser,
        private SocialUserAttributesNormalizer $normalizer,
        private ResponseFactoryInterface $responseFactory,
        private string $redirectUrl,
        private UserSocialAuthenticateService $socialAuthenticateService,
    ) {}

    public function handleCancel(AuthClientInterface $client): ResponseInterface
    {
        return $this->redirect(['error' => 'cancelled']);
    }

    public function handleSuccess(AuthClientInterface $client): ResponseInterface
    {
        if (!$this->currentUser->getIdentity() instanceof GuestIdentityInterface) {
            return $this->redirect(['error' => 'already_authenticated']);
        }

        $provider = $client->getName();
        $attributes = $this->normalizer->normalize($provider, $client);
        $result = $this->socialAuthenticateService->run($provider, $attributes['id'], $attributes, $_SERVER);

        if ($result->isFailure()) {
            return $this->redirect(['error' => $result->getMessage()]);
        }

        if ($result->user === null) {
            return $this->redirect(['error' => 'connection_pending_registration_required']);
        }

        return $this->redirect(['code' => $this->issueExchangeCode($result->user)]);
    }

    private function issueExchangeCode(User $user): string
    {
        $rawCode = Random::string(32);

        $exchangeToken = new UserToken();
        $exchangeToken->setUserId($user->getIdOrZero());
        $exchangeToken->setType(UserToken::TYPE_API_SOCIAL_EXCHANGE);
        $exchangeToken->setCode(hash('sha256', $rawCode));
        $exchangeToken->setCreatedAt(time());
        $exchangeToken->save();

        return $rawCode;
    }

    /**
     * @param array<string, string> $query
     */
    private function redirect(array $query): ResponseInterface
    {
        if ($this->redirectUrl === '') {
            return $this->responseFactory->createResponse(Status::INTERNAL_SERVER_ERROR, 'Social auth redirect URL is not configured.');
        }

        $separator = str_contains($this->redirectUrl, '?') ? '&' : '?';

        return $this->responseFactory->createResponse(Status::FOUND)
            ->withHeader(Header::LOCATION, $this->redirectUrl . $separator . http_build_query($query));
    }
}
