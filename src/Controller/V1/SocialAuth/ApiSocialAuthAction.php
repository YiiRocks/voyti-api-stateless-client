<?php

declare(strict_types=1);

namespace YiiRocks\Voyti\Api\StatelessClient\Controller\V1\SocialAuth;

use Override;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use YiiRocks\Voyti\Api\StatelessClient\SocialAuth\ApiSocialAuthCallbackService;
use YiiRocks\Voyti\SocialAuth\Service\Auth\SocialAuthClientReturnUrlConfigurator;
use Yiisoft\Aliases\Aliases;
use Yiisoft\Router\CurrentRoute;
use Yiisoft\View\WebView;
use Yiisoft\Yii\AuthClient\AuthAction;
use Yiisoft\Yii\AuthClient\Collection;

/**
 * The `voyti/api-v1-auth-social-callback` route action: builds its own {@see AuthAction} instance
 * (rather than reusing the container's `AuthAction::class` binding, which is `voyti-social-auth`'s
 * own and points at its `voyti/session-auth` route) so this route gets its own return URL and its
 * own {@see ApiSocialAuthCallbackService} success/cancel callbacks, without touching that binding
 * or its route at all - both routes can share the same `Collection` safely because this whole
 * ecosystem targets a fresh per-request container (see
 * {@see SocialAuthClientReturnUrlConfigurator}'s docblock).
 */
final readonly class ApiSocialAuthAction implements MiddlewareInterface
{
    public function __construct(
        private Aliases $aliases,
        private ApiSocialAuthCallbackService $callback,
        private Collection $clientCollection,
        private CurrentRoute $currentRoute,
        private ResponseFactoryInterface $responseFactory,
        private SocialAuthClientReturnUrlConfigurator $returnUrlConfigurator,
        private WebView $view,
    ) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $action = (new AuthAction(
            $this->returnUrlConfigurator->configure($this->clientCollection, 'voyti/api-v1-auth-social-callback'),
            $this->aliases,
            $this->view,
            $this->responseFactory,
            $this->currentRoute,
        ))
            ->withSuccessCallback($this->callback->handleSuccess(...))
            ->withCancelCallback($this->callback->handleCancel(...));

        return $action->process($request, $handler);
    }
}
