<?php

declare(strict_types=1);

namespace YiiRocks\Voyti\Api\StatelessClient\tests\OpenApi;

use PHPUnit\Framework\Attributes\CoversClass;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ServerRequestInterface;
use YiiRocks\Voyti\Api\StatelessClient\Auth\ApiLoginChallengeInterface;
use YiiRocks\Voyti\Api\StatelessClient\OpenApi\StatelessClientOpenApiSpecContributor;
use YiiRocks\Voyti\Api\StatelessClient\tests\TestCase;
use YiiRocks\Voyti\Model\User;

#[CoversClass(StatelessClientOpenApiSpecContributor::class)]
final class StatelessClientOpenApiSpecContributorTest extends TestCase
{
    /** @return iterable<string, array{string, string, string}> */
    public static function routeProvider(): iterable
    {
        foreach (['auth-login', 'auth-register', 'auth-register-confirm', 'auth-register-resend', 'auth-password-reset-request', 'auth-password-reset-confirm', 'auth-challenge-verify', 'auth-social-callback', 'auth-social-exchange', 'auth-logout', 'auth-me-show', 'auth-me-update', 'auth-sessions-index', 'auth-sessions-terminate', '2fa-status', '2fa-enable', '2fa-disable', '2fa-backup-codes-regenerate', '2fa-webauthn-register-start', '2fa-webauthn-register-finish', '2fa-totp-setup', '2fa-totp-renew', '2fa-email-send-code', 'gdpr-export', 'gdpr-anonymize', 'audit-log-index', 'rbac-index', 'rbac-create', 'rbac-update', 'rbac-delete', 'rbac-assignments-index', 'rbac-assignments-update'] as $suffix) {
            yield $suffix => ['voyti/api-v1-' . $suffix, self::methodFor($suffix), self::operationIdFor($suffix)];
        }
    }

    public function testOpenApiContract(): void
    {
        $contributor = new StatelessClientOpenApiSpecContributor();

        // Every registered route has an operation, parameters, request schema, and responses.
        foreach (self::routeProvider() as [$routeName, $method, $operationId]) {
            $spec = $contributor->getMethodSpec($routeName, $method);
            $this->assertNotNull($spec);
            $this->assertSame($operationId, $spec['operationId']);
            $suffix = substr($routeName, strlen('voyti/api-v1-'));
            $this->assertSame(self::summaryFor($suffix), $spec['summary']);
            $this->assertSame([self::tagFor($suffix)], $spec['tags']);
            $expected = self::openApiExpectations($suffix);
            $this->assertSame($expected['responses'], $spec['responses']);
            if ($expected['parameters'] === null) {
                $this->assertArrayNotHasKey('parameters', $spec);
            } else {
                $this->assertSame($expected['parameters'], $spec['parameters']);
            }
            if ($expected['requestSchema'] === null) {
                $this->assertArrayNotHasKey('requestBody', $spec);
            } else {
                $this->assertSame(
                    ['required' => true, 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/' . $expected['requestSchema']]]]],
                    $spec['requestBody'],
                );
            }
        }

        // Unknown routes and methods must not leak into the merged document.
        $this->assertNull($contributor->getMethodSpec('unknown', 'get'));
        $this->assertNull($contributor->getMethodSpec('voyti/api-v1-auth-login', 'get'));

        // Shared component schemas describe every payload used by the operations.
        $schemas = $contributor->schemas();

        $this->assertSame(
            '9449cc4b235d019e48ad2b607d9a4e78edea8fd856e6f838aded5e271ead28c5',
            hash('sha256', serialize($schemas)),
        );

        $this->assertSame(
            ['Object', 'MessageResponse', 'ErrorResponse', 'TokenResponse', 'LoginRequest', 'RegistrationRequest', 'EmailRequest', 'PasswordRequest', 'PasswordResetRequest', 'ChallengeVerifyRequest', 'SocialExchangeRequest', 'MeUpdateRequest', 'CurrentUser', 'CurrentUserUpdate', 'SessionList', 'TwoFactorEnableRequest', 'ReauthenticationRequest', 'TwoFactorEnabled', 'BackupCodesResponse', 'TwoFactorStatus', 'WebauthnOptions', 'WebauthnFinishRequest', 'TotpSetup', 'AuthorizationItemRequest', 'AuthorizationItemUpdateRequest', 'AuthorizationItemResponse', 'AuthorizationItemList', 'AssignmentUpdateRequest', 'AssignmentList', 'PaginatedAuditLog'],
            array_keys($schemas),
        );
        $this->assertSame(['type' => 'object', 'additionalProperties' => true], $schemas['Object']);
        $this->assertSame(
            ['type' => 'object', 'required' => ['message'], 'properties' => ['message' => ['type' => 'string']]],
            $schemas['MessageResponse'],
        );
        $this->assertSame(
            ['type' => 'object', 'required' => ['status', 'token'], 'properties' => ['status' => ['type' => 'string'], 'token' => ['type' => 'string']]],
            $schemas['TokenResponse'],
        );
        $this->assertSame(['type' => 'string'], $schemas['AuthorizationItemRequest']['properties']['name'] ?? null);
        $this->assertSame(['type' => 'integer'], $schemas['PasswordResetRequest']['properties']['id'] ?? null);
        $this->assertSame(['type' => 'array', 'items' => ['type' => 'object']], $schemas['TwoFactorStatus']['properties']['availableMethods'] ?? null);
        $this->assertArrayHasKey('message', $schemas['CurrentUserUpdate']['properties'] ?? []);
        $this->assertArrayHasKey('message', $schemas['AuthorizationItemResponse']['properties'] ?? []);
        // The login challenge extension point is an executable API contract, not a coverage target.
        $challenge = new class implements ApiLoginChallengeInterface {
            public function challenge(User $user, ServerRequestInterface $request): ?array
            {
                return ['challenge' => 'required'];
            }
        };

        $this->assertSame(
            ['challenge' => 'required'],
            $challenge->challenge(new User(), new ServerRequest('POST', '/')),
        );
    }

    private static function methodFor(string $suffix): string
    {
        return match ($suffix) {
            'auth-register-confirm', 'auth-social-callback', 'auth-me-show', 'auth-sessions-index', '2fa-status', '2fa-totp-setup', 'gdpr-export', 'audit-log-index', 'rbac-index', 'rbac-assignments-index' => 'get',
            'auth-me-update', 'rbac-update' => 'patch',
            'auth-sessions-terminate', 'rbac-delete' => 'delete',
            'rbac-assignments-update' => 'put',
            default => 'post',
        };
    }

    private static function operationIdFor(string $suffix): string
    {
        return match ($suffix) {
            'auth-login' => 'login',
            'auth-register' => 'register',
            'auth-register-confirm' => 'confirmRegistration',
            'auth-register-resend' => 'resendConfirmation',
            'auth-password-reset-request' => 'requestPasswordReset',
            'auth-password-reset-confirm' => 'confirmPasswordReset',
            'auth-challenge-verify' => 'verifyLoginChallenge',
            'auth-social-callback' => 'socialCallback',
            'auth-social-exchange' => 'exchangeSocialLogin',
            'auth-logout' => 'logout',
            'auth-me-show' => 'getCurrentUser',
            'auth-me-update' => 'updateCurrentUser',
            'auth-sessions-index' => 'listSessions',
            'auth-sessions-terminate' => 'terminateSession',
            '2fa-status' => 'getTwoFactorStatus',
            '2fa-enable' => 'enableTwoFactor',
            '2fa-disable' => 'disableTwoFactor',
            '2fa-backup-codes-regenerate' => 'regenerateBackupCodes',
            '2fa-webauthn-register-start' => 'startPasskeyRegistration',
            '2fa-webauthn-register-finish' => 'finishPasskeyRegistration',
            '2fa-totp-setup' => 'getTotpSetup',
            '2fa-totp-renew' => 'renewTotpSetup',
            '2fa-email-send-code' => 'sendTwoFactorEmailCode',
            'gdpr-export' => 'exportPersonalData',
            'gdpr-anonymize' => 'anonymizeAccount',
            'audit-log-index' => 'listAuditLog',
            'rbac-index' => 'listAuthorizationItems',
            'rbac-create' => 'createAuthorizationItem',
            'rbac-update' => 'updateAuthorizationItem',
            'rbac-delete' => 'deleteAuthorizationItem',
            'rbac-assignments-index' => 'listAssignments',
            'rbac-assignments-update' => 'updateAssignments',
        };
    }

    private static function summaryFor(string $suffix): string
    {
        return match ($suffix) {
            'auth-login' => 'Log in',
            'auth-register' => 'Register an account',
            'auth-register-confirm' => 'Confirm registration',
            'auth-register-resend' => 'Resend confirmation email',
            'auth-password-reset-request' => 'Request a password reset',
            'auth-password-reset-confirm' => 'Confirm a password reset',
            'auth-challenge-verify' => 'Verify a login challenge',
            'auth-social-callback' => 'Complete social authentication',
            'auth-social-exchange' => 'Exchange a social-login code',
            'auth-logout' => 'Log out',
            'auth-me-show' => 'Get the current user',
            'auth-me-update' => 'Update the current user',
            'auth-sessions-index' => 'List active API sessions',
            'auth-sessions-terminate' => 'Terminate an API session',
            '2fa-status' => 'Get two-factor status',
            '2fa-enable' => 'Enable two-factor authentication',
            '2fa-disable' => 'Disable two-factor authentication',
            '2fa-backup-codes-regenerate' => 'Regenerate backup codes',
            '2fa-webauthn-register-start' => 'Start passkey registration',
            '2fa-webauthn-register-finish' => 'Finish passkey registration',
            '2fa-totp-setup' => 'Get TOTP setup data',
            '2fa-totp-renew' => 'Renew TOTP setup data',
            '2fa-email-send-code' => 'Send a two-factor email code',
            'gdpr-export' => 'Export personal data',
            'gdpr-anonymize' => 'Anonymize the current account',
            'audit-log-index' => 'List audit-log entries',
            'rbac-index' => 'List roles or permissions',
            'rbac-create' => 'Create a role or permission',
            'rbac-update' => 'Update a role or permission',
            'rbac-delete' => 'Delete a role or permission',
            'rbac-assignments-index' => 'List authorization assignments',
            'rbac-assignments-update' => 'Update authorization assignments',
        };
    }

    private static function tagFor(string $suffix): string
    {
        return match (true) {
            str_starts_with($suffix, 'auth-me-') => 'Account',
            str_starts_with($suffix, 'auth-sessions-') => 'Sessions',
            str_starts_with($suffix, 'auth-') => 'Authentication',
            str_starts_with($suffix, '2fa-') => 'Two-factor authentication',
            str_starts_with($suffix, 'gdpr-') => 'GDPR',
            $suffix === 'audit-log-index' => 'Audit log',
            default => 'RBAC',
        };
    }

    /** @return array{responses: array<string, mixed>, parameters: list<array<string, mixed>>|null, requestSchema: string|null} */
    private static function openApiExpectations(string $suffix): array
    {
        $success = match ($suffix) {
            'auth-register', 'rbac-create', '2fa-enable', '2fa-webauthn-register-finish' => '201',
            'auth-social-callback' => '302',
            default => '200',
        };
        $schema = match ($suffix) {
            'auth-login', 'auth-challenge-verify', 'auth-social-exchange' => 'TokenResponse',
            'auth-social-callback' => null,
            'auth-me-show', 'auth-me-update' => $suffix === 'auth-me-show' ? 'CurrentUser' : 'CurrentUserUpdate',
            'auth-sessions-index' => 'SessionList',
            '2fa-status' => 'TwoFactorStatus',
            '2fa-enable', '2fa-webauthn-register-finish' => 'TwoFactorEnabled',
            '2fa-backup-codes-regenerate' => 'BackupCodesResponse',
            '2fa-webauthn-register-start' => 'WebauthnOptions',
            '2fa-totp-setup', '2fa-totp-renew' => 'TotpSetup',
            'gdpr-export' => 'Object',
            'rbac-index' => 'AuthorizationItemList',
            'rbac-create', 'rbac-update' => 'AuthorizationItemResponse',
            'rbac-assignments-index' => 'AssignmentList',
            'audit-log-index' => 'PaginatedAuditLog',
            default => 'MessageResponse',
        };
        $errors = match ($suffix) {
            'auth-login' => ['202', '400', '401', '403', '429'],
            'auth-register' => ['400', '403'],
            'auth-register-confirm' => ['400'],
            'auth-register-resend', 'auth-password-reset-request' => ['403'],
            'auth-password-reset-confirm' => ['400', '403'],
            'auth-challenge-verify' => ['400', '401'],
            'auth-social-exchange' => ['400'],
            'auth-sessions-terminate', 'rbac-delete', 'rbac-assignments-index' => ['404'],
            '2fa-status', 'auth-logout', 'auth-me-show', 'auth-sessions-index', 'auth-social-callback', 'gdpr-export', 'audit-log-index', 'rbac-index' => [],
            'auth-me-update' => ['400'],
            '2fa-enable', '2fa-webauthn-register-finish' => ['400', '401'],
            '2fa-disable', '2fa-backup-codes-regenerate' => ['400', '401'],
            '2fa-webauthn-register-start', '2fa-totp-setup', '2fa-totp-renew', '2fa-email-send-code', 'gdpr-anonymize' => ['400'],
            'rbac-create' => ['400'],
            'rbac-update', 'rbac-assignments-update' => ['400', '404'],
        };
        $responses = [
            $success => [
                'description' => self::summaryFor($suffix),
                ...($schema === null ? [] : ['content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/' . $schema]]]]),
            ],
        ];
        foreach ($errors as $status) {
            $responses[$status] = ['description' => 'Request failed', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ErrorResponse']]]];
        }
        $parameters = match ($suffix) {
            'auth-register-confirm' => [self::parameter('id', 'path', ['type' => 'integer'], description: 'User ID'), self::parameter('code', 'path', ['type' => 'string'])],
            'auth-social-callback' => [self::parameter('authclient', 'path', ['type' => 'string'])],
            'auth-sessions-terminate' => [self::parameter('id', 'path', ['type' => 'string'], description: 'Token hash')],
            'rbac-index', 'rbac-create' => [self::parameter('itemType', 'path', ['type' => 'string', 'enum' => ['role', 'permission']])],
            'rbac-update', 'rbac-delete', 'rbac-assignments-index', 'rbac-assignments-update' => [self::parameter('itemType', 'path', ['type' => 'string', 'enum' => ['role', 'permission']]), self::parameter('name', 'path', ['type' => 'string'])],
            'audit-log-index' => null,
            default => null,
        };
        $requestSchema = match ($suffix) {
            'auth-login' => 'LoginRequest',
            'auth-register' => 'RegistrationRequest',
            'auth-register-resend', 'auth-password-reset-request' => 'EmailRequest',
            'auth-password-reset-confirm' => 'PasswordResetRequest',
            'auth-challenge-verify' => 'ChallengeVerifyRequest',
            'auth-social-exchange' => 'SocialExchangeRequest',
            'auth-me-update' => 'MeUpdateRequest',
            '2fa-enable' => 'TwoFactorEnableRequest',
            '2fa-disable', '2fa-backup-codes-regenerate' => 'ReauthenticationRequest',
            '2fa-webauthn-register-finish' => 'WebauthnFinishRequest',
            'gdpr-anonymize' => 'PasswordRequest',
            'rbac-create' => 'AuthorizationItemRequest',
            'rbac-update' => 'AuthorizationItemUpdateRequest',
            'rbac-assignments-update' => 'AssignmentUpdateRequest',
            default => null,
        };
        if ($suffix === 'audit-log-index') {
            $parameters = [
                self::parameter('actorUserId', 'query', ['type' => 'string'], required: false),
                self::parameter('targetUserId', 'query', ['type' => 'string'], required: false),
                self::parameter('action', 'query', ['type' => 'string'], required: false),
                self::parameter('page', 'query', ['type' => 'integer', 'minimum' => 1, 'default' => 1], required: false),
            ];
        }
        return ['responses' => $responses, 'parameters' => $parameters, 'requestSchema' => $requestSchema];
    }

    /** @return array<string, mixed> */
    private static function parameter(string $name, string $in, array $schema, bool $required = true, ?string $description = null): array
    {
        return array_filter(['name' => $name, 'in' => $in, 'required' => $required, 'description' => $description, 'schema' => $schema], static fn(mixed $value): bool => $value !== null);
    }
}
