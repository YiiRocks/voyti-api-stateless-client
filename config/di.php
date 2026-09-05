<?php

declare(strict_types=1);

use YiiRocks\Voyti\Api\StatelessClient\Auth\ApiTwoFactorLoginChallenge;
use YiiRocks\Voyti\Api\StatelessClient\Controller\V1\Auth\AuthController;
use YiiRocks\Voyti\Api\StatelessClient\Controller\V1\Registration\RegistrationController;
use YiiRocks\Voyti\Api\StatelessClient\OpenApi\StatelessClientOpenApiSpecContributor;
use YiiRocks\Voyti\Api\StatelessClient\SocialAuth\ApiSocialAuthCallbackService;
use YiiRocks\Voyti\SocialAuth\Service\Auth\UserSocialAuthenticateService;
use YiiRocks\Voyti\TwoFactor\TwoFactorMethodInterface;
use Yiisoft\Di\Reference\TagReference;
use Yiisoft\Translator\CategorySource;
use Yiisoft\Translator\Message\Php\MessageSource;
use Yiisoft\Translator\SimpleMessageFormatter;

/** @var array $params */

$definitions = [
    StatelessClientOpenApiSpecContributor::class => [
        'class' => StatelessClientOpenApiSpecContributor::class,
        'tags' => ['voyti-api.openapi-contributor'],
    ],
    // 2FA login challenges: steps that may interrupt a successful password login before a bearer
    // token is issued (e.g. a two-factor step). The optional `voyti-2fa` bridge (once installed) tags
    // its challenge with `voyti-api.login-challenge`; AuthController consults them all, in
    // registration order. Empty when no such package is installed.
    AuthController::class => [
        'class' => AuthController::class,
        '__construct()' => [
            'loginChallenges' => TagReference::to('voyti-api.login-challenge'),
        ],
    ],

    // Post-registration hooks: the same `voyti.post-registration-hook` tag core's own HTML
    // RegistrationController consults (e.g. connecting a pending social account).
    RegistrationController::class => [
        'class' => RegistrationController::class,
        '__construct()' => [
            'postRegistrationHooks' => TagReference::to('voyti.post-registration-hook'),
        ],
    ],

    // Translation category source for this package's own message files.
    'yiirocks/voyti-api-stateless-client.translator' => [
        'definition' => static fn(): CategorySource => new CategorySource(
            'voyti-api-stateless-client',
            new MessageSource(dirname(__DIR__) . '/resources/messages'),
            new SimpleMessageFormatter(),
        ),
        'tags' => ['translation.categorySource'],
    ],
];

// Dynamic feature activation: only bound when the optional `voyti-2fa` package is actually
// installed, mirroring core's own Composer\InstalledVersions-based conditional resolution in
// voyti/config/params-console.php. A host that never installs voyti-2fa never references this
// class at all - no compile-time dependency, no runtime error, the tag collection simply stays
// empty and AuthController's login flow behaves exactly as it does today.
if (interface_exists(TwoFactorMethodInterface::class)) {
    $definitions[ApiTwoFactorLoginChallenge::class] = [
        'class' => ApiTwoFactorLoginChallenge::class,
        'tags' => ['voyti-api.login-challenge'],
    ];
}

// Dynamic feature activation: only bound when the optional `voyti-social-auth` package is
// installed. See ApiTwoFactorLoginChallenge's guard above for the same pattern.
if (class_exists(UserSocialAuthenticateService::class)) {
    $definitions[ApiSocialAuthCallbackService::class] = [
        'class' => ApiSocialAuthCallbackService::class,
        '__construct()' => [
            'redirectUrl' => $params['yiirocks/voyti']['api']['social']['redirectUrl'] ?? '',
        ],
    ];
}

return $definitions;
