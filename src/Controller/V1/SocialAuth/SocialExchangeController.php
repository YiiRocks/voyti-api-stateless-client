<?php

declare(strict_types=1);

namespace YiiRocks\Voyti\Api\StatelessClient\Controller\V1\SocialAuth;

use Psr\Http\Message\ResponseInterface;
use YiiRocks\Voyti\Api\Service\User\ApiTokenService;
use YiiRocks\Voyti\Api\StatelessClient\SocialAuth\ApiSocialAuthCallbackService;
use YiiRocks\Voyti\Model\UserToken;
use Yiisoft\DataResponse\ResponseFactory\DataResponseFactoryInterface;
use Yiisoft\Http\Status;
use Yiisoft\Input\Http\Attribute\Parameter\Body;
use Yiisoft\Translator\TranslatorInterface;

/**
 * Trades the one-time code {@see ApiSocialAuthCallbackService} redirected the popup with for a real
 * bearer token, minted only now via {@see ApiTokenService} - the token is never generated at
 * callback time, so there's nowhere it could have leaked into a URL or browser history entry.
 */
final readonly class SocialExchangeController
{
    public function __construct(
        private ApiTokenService $apiTokenService,
        private DataResponseFactoryInterface $responseFactory,
        private TranslatorInterface $translator,
    ) {}

    public function exchange(
        #[Body('code')]
        string $code = '',
    ): ResponseInterface {
        $exchangeToken = UserToken::findByCodeAndType($code, UserToken::TYPE_API_SOCIAL_EXCHANGE);

        if ($exchangeToken === null || $exchangeToken->isExpired(ApiSocialAuthCallbackService::EXCHANGE_LIFESPAN)) {
            return $this->responseFactory->createResponse(
                [
                    'error' => $this->translator->translate(
                        'voyti-api-stateless-client.social_auth.code_invalid_or_expired',
                        category: 'voyti-api-stateless-client',
                    ),
                ],
                Status::BAD_REQUEST,
            );
        }

        $user = $exchangeToken->getUser();
        if ($user === null) {
            return $this->responseFactory->createResponse(
                [
                    'error' => $this->translator->translate(
                        'voyti-api-stateless-client.social_auth.code_invalid_or_expired',
                        category: 'voyti-api-stateless-client',
                    ),
                ],
                Status::BAD_REQUEST,
            );
        }

        if (!$exchangeToken->consume()) {
            return $this->responseFactory->createResponse(
                ['error' => $this->translator->translate('voyti-api-stateless-client.social_auth.code_invalid_or_expired', category: 'voyti-api-stateless-client')],
                Status::BAD_REQUEST,
            );
        }
        $token = $this->apiTokenService->generate($user);

        return $this->responseFactory->createResponse(['status' => 'ok', 'token' => $token]);
    }
}
