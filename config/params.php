<?php

declare(strict_types=1);

use YiiRocks\Voyti\Api\StatelessClient\Controller\V1\AuditLog\AuditLogController;
use YiiRocks\Voyti\Api\StatelessClient\Controller\V1\Auth\AuthController;
use YiiRocks\Voyti\Api\StatelessClient\Controller\V1\Gdpr\GdprController;
use YiiRocks\Voyti\Api\StatelessClient\Controller\V1\Me\MeController;
use YiiRocks\Voyti\Api\StatelessClient\Controller\V1\PasswordReset\PasswordResetController;
use YiiRocks\Voyti\Api\StatelessClient\Controller\V1\Rbac\RbacAssignmentController;
use YiiRocks\Voyti\Api\StatelessClient\Controller\V1\Rbac\RbacController;
use YiiRocks\Voyti\Api\StatelessClient\Controller\V1\Registration\RegistrationController;
use YiiRocks\Voyti\Api\StatelessClient\Controller\V1\Session\SessionsController;
use YiiRocks\Voyti\Api\StatelessClient\Controller\V1\SocialAuth\ApiSocialAuthAction;
use YiiRocks\Voyti\Api\StatelessClient\Controller\V1\SocialAuth\SocialExchangeController;
use YiiRocks\Voyti\Api\StatelessClient\Controller\V1\TwoFactor\ChallengeController;
use YiiRocks\Voyti\Api\StatelessClient\Controller\V1\TwoFactor\EmailCodeController;
use YiiRocks\Voyti\Api\StatelessClient\Controller\V1\TwoFactor\TotpSetupController;
use YiiRocks\Voyti\Api\StatelessClient\Controller\V1\TwoFactor\TwoFactorManagementController;
use YiiRocks\Voyti\Api\StatelessClient\Controller\V1\TwoFactor\WebauthnEnrollmentController;
use YiiRocks\Voyti\Gdpr\Service\AnonymizeUserService;
use YiiRocks\Voyti\SocialAuth\Middleware\CaptureAuthActionRequestMiddleware;
use YiiRocks\Voyti\SocialAuth\Service\Auth\UserSocialAuthenticateService;
use YiiRocks\Voyti\TwoFactor\Email\Service\EmailCodeGeneratorService;
use YiiRocks\Voyti\TwoFactor\Totp\Service\QrCodeUriGeneratorService;
use YiiRocks\Voyti\TwoFactor\TwoFactorMethodInterface;
use YiiRocks\Voyti\TwoFactor\Webauthn\Service\WebauthnService;
use Yiisoft\Router\Group;
use Yiisoft\Router\Route;

// Reachable before a bearer token exists.
$publicRoutes = [
    Group::create('v1/auth')
        ->namePrefix('voyti/api-v1-auth-')
        ->routes(
            Route::post('/login')->name('login')->action([AuthController::class, 'login']),
            Route::post('/register')->name('register')->action([RegistrationController::class, 'register']),
            Route::get('/register/confirm/{id:\d+}/{code}')
                ->name('register-confirm')
                ->action([RegistrationController::class, 'confirm']),
            Route::post('/register/resend')->name('register-resend')->action([RegistrationController::class, 'resend']),
            Route::post('/password-reset/request')
                ->name('password-reset-request')
                ->action([PasswordResetController::class, 'request']),
            Route::post('/password-reset/confirm')
                ->name('password-reset-confirm')
                ->action([PasswordResetController::class, 'confirm']),
        ),
];

// Dynamic feature activation: only reachable when the optional `voyti-2fa` package is installed
// (see config/di.php's matching guard) - a host that never installs it never registers this route,
// and ChallengeController itself is never instantiated.
if (interface_exists(TwoFactorMethodInterface::class)) {
    $publicRoutes[] = Group::create('v1/auth')
        ->namePrefix('voyti/api-v1-auth-')
        ->routes(
            Route::post('/challenge/verify')->name('challenge-verify')->action([ChallengeController::class, 'verify']),
        );
}

// Dynamic feature activation: only reachable when the optional `voyti-social-auth` package is
// installed (see config/di.php's matching guard) - a host that never installs it never registers
// these routes, and neither ApiSocialAuthAction nor SocialExchangeController is ever instantiated.
if (class_exists(UserSocialAuthenticateService::class)) {
    $publicRoutes[] = Group::create('v1/auth')
        ->namePrefix('voyti/api-v1-auth-')
        ->routes(
            Group::create('social')
                ->middleware(CaptureAuthActionRequestMiddleware::class)
                ->routes(
                    Route::get('/{authclient}')->name('social-callback')->action(ApiSocialAuthAction::class),
                ),
            Route::post('/social/exchange')->name('social-exchange')->action([SocialExchangeController::class, 'exchange']),
        );
}

// Reachable by any authenticated bearer-token holder, on their own behalf.
$authenticatedRoutes = [
    Group::create('v1/auth')
        ->namePrefix('voyti/api-v1-auth-')
        ->routes(
            Route::post('/logout')->name('logout')->action([AuthController::class, 'logout']),
            Route::get('/me')->name('me-show')->action([MeController::class, 'show']),
            Route::patch('/me')->name('me-update')->action([MeController::class, 'update']),
            Route::get('/sessions')->name('sessions-index')->action([SessionsController::class, 'index']),
            Route::delete('/sessions/{id}')
                ->name('sessions-terminate')
                ->action([SessionsController::class, 'terminate']),
        ),
];

// Dynamic feature activation: only reachable when the optional `voyti-2fa` package is installed -
// enrollment/management for the login-challenge bridge above.
if (interface_exists(TwoFactorMethodInterface::class)) {
    $authenticatedRoutes[] = Group::create('v1/2fa')
        ->namePrefix('voyti/api-v1-2fa-')
        ->routes(
            Route::get('')->name('status')->action([TwoFactorManagementController::class, 'status']),
            Route::post('/enable')->name('enable')->action([TwoFactorManagementController::class, 'enable']),
            Route::post('/disable')->name('disable')->action([TwoFactorManagementController::class, 'disable']),
            Route::post('/backup-codes/regenerate')
                ->name('backup-codes-regenerate')
                ->action([TwoFactorManagementController::class, 'regenerateBackupCodes']),
        );
}

// Dynamic feature activation: only reachable when the optional `voyti-2fa-webauthn` package is
// installed - registering a passkey has its own dedicated ceremony, not part of the generic
// enable/disable action above.
if (class_exists(WebauthnService::class)) {
    $authenticatedRoutes[] = Group::create('v1/2fa/webauthn')
        ->namePrefix('voyti/api-v1-2fa-webauthn-')
        ->routes(
            Route::post('/register/start')->name('register-start')->action([WebauthnEnrollmentController::class, 'start']),
            Route::post('/register/finish')->name('register-finish')->action([WebauthnEnrollmentController::class, 'finish']),
        );
}

// Dynamic feature activation: only reachable when the optional `voyti-2fa-totp` package is
// installed - the QR code/secret must be issued before the generic enable action has anything to
// verify a submitted code against.
if (class_exists(QrCodeUriGeneratorService::class)) {
    $authenticatedRoutes[] = Group::create('v1/2fa/totp')
        ->namePrefix('voyti/api-v1-2fa-totp-')
        ->routes(
            Route::get('/setup')->name('setup')->action([TotpSetupController::class, 'show']),
            Route::post('/renew')->name('renew')->action([TotpSetupController::class, 'renew']),
        );
}

// Dynamic feature activation: only reachable when the optional `voyti-2fa-email` package is
// installed - a code must be emailed before the generic enable action has anything to verify a
// submitted code against.
if (class_exists(EmailCodeGeneratorService::class)) {
    $authenticatedRoutes[] = Group::create('v1/2fa/email')
        ->namePrefix('voyti/api-v1-2fa-email-')
        ->routes(
            Route::post('/send-code')->name('send-code')->action([EmailCodeController::class, 'sendCode']),
        );
}

// Dynamic feature activation: only reachable when the optional `voyti-gdpr` package is installed -
// a host that never installs it never registers this route, and GdprController is never instantiated.
if (class_exists(AnonymizeUserService::class)) {
    $authenticatedRoutes[] = Group::create('v1/gdpr')
        ->namePrefix('voyti/api-v1-gdpr-')
        ->routes(
            Route::get('/export')->name('export')->action([GdprController::class, 'export']),
            Route::post('/anonymize')->name('anonymize')->action([GdprController::class, 'anonymize']),
        );
}

return [
    'yiirocks/voyti' => [
        'api' => [
            'publicRoutes' => $publicRoutes,
            'authenticatedRoutes' => $authenticatedRoutes,

            // Admin-only, wrapped in voyti-api's AccessRuleMiddleware.
            'routes' => [
                Group::create('v1/audit-log')
                    ->namePrefix('voyti/api-v1-audit-log-')
                    ->routes(
                        Route::get('')->name('index')->action([AuditLogController::class, 'index']),
                    ),
                Group::create('v1/rbac/{itemType:role|permission}')
                    ->namePrefix('voyti/api-v1-rbac-')
                    ->routes(
                        Route::get('')->name('index')->action([RbacController::class, 'index']),
                        Route::post('')->name('create')->action([RbacController::class, 'create']),
                        Route::patch('/{name}')->name('update')->action([RbacController::class, 'update']),
                        Route::delete('/{name}')->name('delete')->action([RbacController::class, 'delete']),
                        Group::create('/{name}/assignments')
                            ->namePrefix('assignments-')
                            ->routes(
                                Route::get('')->name('index')->action([RbacAssignmentController::class, 'index']),
                                Route::put('')->name('update')->action([RbacAssignmentController::class, 'update']),
                            ),
                    ),
            ],
        ],
    ],
];
