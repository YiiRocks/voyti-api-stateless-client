<?php

declare(strict_types=1);

namespace YiiRocks\Voyti\Api\StatelessClient\OpenApi;

use Override;
use YiiRocks\Voyti\Api\OpenApi\OpenApiSpecContributorInterface;

/** Describes every stateless-client operation in Voyti's merged OpenAPI document. */
final readonly class StatelessClientOpenApiSpecContributor implements OpenApiSpecContributorInterface
{
    #[Override]
    public function getMethodSpec(string $routeName, string $method): ?array
    {
        $route = self::routes()[$routeName] ?? null;
        if ($route === null || $route[0] !== $method) {
            return null;
        }

        [$httpMethod, $operationId, $summary, $tag, $parameters, $requestSchema, $successStatus, $successSchema, $errorStatuses] = $route;
        $spec = [
            'operationId' => $operationId,
            'summary' => $summary,
            'tags' => [$tag],
            'responses' => [$successStatus => self::response($summary, $successSchema)],
        ];
        if ($parameters !== []) {
            $spec['parameters'] = $parameters;
        }
        if ($requestSchema !== null) {
            $spec['requestBody'] = [
                'required' => true,
                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/' . $requestSchema]]],
            ];
        }
        foreach ($errorStatuses as $status) {
            $spec['responses'][$status] = self::response('Request failed', 'ErrorResponse');
        }

        return $spec;
    }

    #[Override]
    public function schemas(): array
    {
        $string = ['type' => 'string'];
        $message = ['type' => 'object', 'required' => ['message'], 'properties' => ['message' => $string]];
        $user = ['type' => 'object', 'properties' => [
            'id' => ['type' => 'string'], 'username' => $string, 'email' => $string,
            'unconfirmedEmail' => ['type' => ['string', 'null']], 'createdAt' => ['type' => 'integer'],
            'confirmedAt' => ['type' => ['integer', 'null']], 'lastLoginAt' => ['type' => ['integer', 'null']],
        ]];
        $password = ['type' => 'object', 'required' => ['password'], 'properties' => ['password' => $string]];
        $code = ['type' => 'object', 'properties' => ['code' => $string, 'payload' => $string]];
        $item = ['type' => 'object', 'required' => ['name', 'description', 'rule', 'children'], 'properties' => [
            'name' => $string, 'description' => $string, 'rule' => ['type' => ['string', 'null']],
            'children' => ['type' => 'array', 'items' => $string],
        ]];

        return [
            'Object' => ['type' => 'object', 'additionalProperties' => true],
            'MessageResponse' => $message,
            'ErrorResponse' => ['type' => 'object', 'required' => ['error'], 'properties' => ['error' => $string, 'errors' => ['type' => 'object'], 'userIds' => ['type' => 'array', 'items' => $string]]],
            'TokenResponse' => ['type' => 'object', 'required' => ['status', 'token'], 'properties' => ['status' => $string, 'token' => $string]],
            'LoginRequest' => ['type' => 'object', 'required' => ['login', 'password'], 'properties' => ['login' => $string, 'password' => $string]],
            'RegistrationRequest' => ['type' => 'object', 'required' => ['username', 'email'], 'properties' => ['username' => $string, 'email' => ['type' => 'string', 'format' => 'email'], 'password' => $string]],
            'EmailRequest' => ['type' => 'object', 'required' => ['email'], 'properties' => ['email' => ['type' => 'string', 'format' => 'email']]],
            'PasswordRequest' => $password,
            'PasswordResetRequest' => ['type' => 'object', 'required' => ['id', 'code', 'password'], 'properties' => ['id' => ['type' => 'integer'], 'code' => $string, 'password' => $string]],
            'ChallengeVerifyRequest' => ['type' => 'object', 'required' => ['challengeToken'], 'properties' => ['challengeToken' => $string, 'code' => $string, 'payload' => $string]],
            'SocialExchangeRequest' => ['type' => 'object', 'required' => ['code'], 'properties' => ['code' => $string]],
            'MeUpdateRequest' => ['type' => 'object', 'properties' => ['username' => $string, 'email' => ['type' => 'string', 'format' => 'email'], 'password' => $string]],
            'CurrentUser' => $user,
            'CurrentUserUpdate' => array_merge($user, ['required' => ['message'], 'properties' => $user['properties'] + ['message' => $string]]),
            'SessionList' => ['type' => 'object', 'required' => ['items'], 'properties' => ['items' => ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['id', 'createdAt'], 'properties' => ['id' => $string, 'createdAt' => ['type' => 'integer']]]]]],
            'TwoFactorEnableRequest' => ['type' => 'object', 'required' => ['method', 'code'], 'properties' => ['method' => $string, 'code' => $string]],
            'ReauthenticationRequest' => $code,
            'TwoFactorEnabled' => ['type' => 'object', 'required' => ['message', 'backupCodes'], 'properties' => ['message' => $string, 'backupCodes' => ['type' => 'array', 'items' => $string]]],
            'BackupCodesResponse' => ['type' => 'object', 'required' => ['message', 'backupCodes'], 'properties' => ['message' => $string, 'backupCodes' => ['type' => 'array', 'items' => $string]]],
            'TwoFactorStatus' => ['type' => 'object', 'required' => ['enabled', 'method', 'hasUnusedBackupCodes', 'availableMethods'], 'properties' => ['enabled' => ['type' => 'boolean'], 'method' => ['type' => ['string', 'null']], 'hasUnusedBackupCodes' => ['type' => 'boolean'], 'availableMethods' => ['type' => 'array', 'items' => ['type' => 'object']]]],
            'WebauthnOptions' => ['type' => 'object', 'required' => ['requestOptions'], 'properties' => ['requestOptions' => ['type' => 'object', 'additionalProperties' => true]]],
            'WebauthnFinishRequest' => ['type' => 'object', 'required' => ['clientDataJSON', 'attestationObject'], 'properties' => ['clientDataJSON' => $string, 'attestationObject' => $string]],
            'TotpSetup' => ['type' => 'object', 'required' => ['qrCodeUri', 'secret'], 'properties' => ['qrCodeUri' => $string, 'secret' => $string]],
            'AuthorizationItemRequest' => ['type' => 'object', 'required' => ['name'], 'properties' => ['name' => $string, 'description' => $string, 'rule' => $string, 'children' => ['type' => 'array', 'items' => $string]]],
            'AuthorizationItemUpdateRequest' => ['type' => 'object', 'properties' => ['name' => $string, 'description' => $string, 'rule' => $string, 'children' => ['type' => 'array', 'items' => $string]]],
            'AuthorizationItemResponse' => array_merge($item, ['properties' => $item['properties'] + ['message' => $string]]),
            'AuthorizationItemList' => ['type' => 'object', 'required' => ['items'], 'properties' => ['items' => ['type' => 'array', 'items' => $item]]],
            'AssignmentUpdateRequest' => ['type' => 'object', 'properties' => ['userIds' => ['type' => 'array', 'items' => ['type' => ['integer', 'string']]]]],
            'AssignmentList' => ['type' => 'object', 'required' => ['assignments'], 'properties' => ['assignments' => ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['id', 'username'], 'properties' => ['id' => $string, 'username' => $string]]]]],
            'PaginatedAuditLog' => ['type' => 'object', 'required' => ['items', 'totalCount', 'currentPage', 'pageSize', 'totalPages'], 'properties' => ['items' => ['type' => 'array', 'items' => ['type' => 'object']], 'totalCount' => ['type' => 'integer'], 'currentPage' => ['type' => 'integer'], 'pageSize' => ['type' => 'integer'], 'totalPages' => ['type' => 'integer']]],
        ];
    }

    /** @return array<string, array{string, string, string, string, list<array<string, mixed>>, string|null, int, string|null, list<int>}> */
    private static function routes(): array
    {
        return [
            'voyti/api-v1-auth-login' => ['post', 'login', 'Log in', 'Authentication', [], 'LoginRequest', 200, 'TokenResponse', [202, 400, 401, 403, 429]],
            'voyti/api-v1-auth-register' => ['post', 'register', 'Register an account', 'Authentication', [], 'RegistrationRequest', 201, 'MessageResponse', [400, 403]],
            'voyti/api-v1-auth-register-confirm' => ['get', 'confirmRegistration', 'Confirm registration', 'Authentication', [self::idParameter(), self::codeParameter()], null, 200, 'MessageResponse', [400]],
            'voyti/api-v1-auth-register-resend' => ['post', 'resendConfirmation', 'Resend confirmation email', 'Authentication', [], 'EmailRequest', 200, 'MessageResponse', [403]],
            'voyti/api-v1-auth-password-reset-request' => ['post', 'requestPasswordReset', 'Request a password reset', 'Authentication', [], 'EmailRequest', 200, 'MessageResponse', [403]],
            'voyti/api-v1-auth-password-reset-confirm' => ['post', 'confirmPasswordReset', 'Confirm a password reset', 'Authentication', [], 'PasswordResetRequest', 200, 'MessageResponse', [400, 403]],
            'voyti/api-v1-auth-challenge-verify' => ['post', 'verifyLoginChallenge', 'Verify a login challenge', 'Authentication', [], 'ChallengeVerifyRequest', 200, 'TokenResponse', [400, 401]],
            'voyti/api-v1-auth-social-callback' => ['get', 'socialCallback', 'Complete social authentication', 'Authentication', [self::authClientParameter()], null, 302, null, []],
            'voyti/api-v1-auth-social-exchange' => ['post', 'exchangeSocialLogin', 'Exchange a social-login code', 'Authentication', [], 'SocialExchangeRequest', 200, 'TokenResponse', [400]],
            'voyti/api-v1-auth-logout' => ['post', 'logout', 'Log out', 'Authentication', [], null, 200, 'MessageResponse', []],
            'voyti/api-v1-auth-me-show' => ['get', 'getCurrentUser', 'Get the current user', 'Account', [], null, 200, 'CurrentUser', []],
            'voyti/api-v1-auth-me-update' => ['patch', 'updateCurrentUser', 'Update the current user', 'Account', [], 'MeUpdateRequest', 200, 'CurrentUserUpdate', [400]],
            'voyti/api-v1-auth-sessions-index' => ['get', 'listSessions', 'List active API sessions', 'Sessions', [], null, 200, 'SessionList', []],
            'voyti/api-v1-auth-sessions-terminate' => ['delete', 'terminateSession', 'Terminate an API session', 'Sessions', [self::tokenIdParameter()], null, 200, 'MessageResponse', [404]],
            'voyti/api-v1-2fa-status' => ['get', 'getTwoFactorStatus', 'Get two-factor status', 'Two-factor authentication', [], null, 200, 'TwoFactorStatus', []],
            'voyti/api-v1-2fa-enable' => ['post', 'enableTwoFactor', 'Enable two-factor authentication', 'Two-factor authentication', [], 'TwoFactorEnableRequest', 201, 'TwoFactorEnabled', [400, 401]],
            'voyti/api-v1-2fa-disable' => ['post', 'disableTwoFactor', 'Disable two-factor authentication', 'Two-factor authentication', [], 'ReauthenticationRequest', 200, 'MessageResponse', [400, 401]],
            'voyti/api-v1-2fa-backup-codes-regenerate' => ['post', 'regenerateBackupCodes', 'Regenerate backup codes', 'Two-factor authentication', [], 'ReauthenticationRequest', 200, 'BackupCodesResponse', [400, 401]],
            'voyti/api-v1-2fa-webauthn-register-start' => ['post', 'startPasskeyRegistration', 'Start passkey registration', 'Two-factor authentication', [], null, 200, 'WebauthnOptions', [400]],
            'voyti/api-v1-2fa-webauthn-register-finish' => ['post', 'finishPasskeyRegistration', 'Finish passkey registration', 'Two-factor authentication', [], 'WebauthnFinishRequest', 201, 'TwoFactorEnabled', [400, 401]],
            'voyti/api-v1-2fa-totp-setup' => ['get', 'getTotpSetup', 'Get TOTP setup data', 'Two-factor authentication', [], null, 200, 'TotpSetup', [400]],
            'voyti/api-v1-2fa-totp-renew' => ['post', 'renewTotpSetup', 'Renew TOTP setup data', 'Two-factor authentication', [], null, 200, 'TotpSetup', [400]],
            'voyti/api-v1-2fa-email-send-code' => ['post', 'sendTwoFactorEmailCode', 'Send a two-factor email code', 'Two-factor authentication', [], null, 200, 'MessageResponse', [400]],
            'voyti/api-v1-gdpr-export' => ['get', 'exportPersonalData', 'Export personal data', 'GDPR', [], null, 200, 'Object', []],
            'voyti/api-v1-gdpr-anonymize' => ['post', 'anonymizeAccount', 'Anonymize the current account', 'GDPR', [], 'PasswordRequest', 200, 'MessageResponse', [400]],
            'voyti/api-v1-audit-log-index' => ['get', 'listAuditLog', 'List audit-log entries', 'Audit log', self::auditLogParameters(), null, 200, 'PaginatedAuditLog', []],
            'voyti/api-v1-rbac-index' => ['get', 'listAuthorizationItems', 'List roles or permissions', 'RBAC', [self::itemTypeParameter()], null, 200, 'AuthorizationItemList', []],
            'voyti/api-v1-rbac-create' => ['post', 'createAuthorizationItem', 'Create a role or permission', 'RBAC', [self::itemTypeParameter()], 'AuthorizationItemRequest', 201, 'AuthorizationItemResponse', [400]],
            'voyti/api-v1-rbac-update' => ['patch', 'updateAuthorizationItem', 'Update a role or permission', 'RBAC', [self::itemTypeParameter(), self::nameParameter()], 'AuthorizationItemUpdateRequest', 200, 'AuthorizationItemResponse', [400, 404]],
            'voyti/api-v1-rbac-delete' => ['delete', 'deleteAuthorizationItem', 'Delete a role or permission', 'RBAC', [self::itemTypeParameter(), self::nameParameter()], null, 200, 'MessageResponse', [404]],
            'voyti/api-v1-rbac-assignments-index' => ['get', 'listAssignments', 'List authorization assignments', 'RBAC', [self::itemTypeParameter(), self::nameParameter()], null, 200, 'AssignmentList', [404]],
            'voyti/api-v1-rbac-assignments-update' => ['put', 'updateAssignments', 'Update authorization assignments', 'RBAC', [self::itemTypeParameter(), self::nameParameter()], 'AssignmentUpdateRequest', 200, 'MessageResponse', [400, 404]],
        ];
    }

    /** @return array<string, mixed> */
    private static function response(string $description, ?string $schema): array
    {
        $response = ['description' => $description];
        if ($schema !== null) {
            $response['content'] = ['application/json' => ['schema' => ['$ref' => '#/components/schemas/' . $schema]]];
        }
        return $response;
    }

    /** @return array<string, mixed> */
    private static function idParameter(string $description = 'User ID'): array
    {
        return ['name' => 'id', 'in' => 'path', 'required' => true, 'description' => $description, 'schema' => ['type' => 'integer']];
    }

    /** @return array<string, mixed> */
    private static function tokenIdParameter(): array
    {
        return ['name' => 'id', 'in' => 'path', 'required' => true, 'description' => 'Token hash', 'schema' => ['type' => 'string']];
    }

    /** @return array<string, mixed> */
    private static function codeParameter(): array
    {
        return ['name' => 'code', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']];
    }

    /** @return array<string, mixed> */
    private static function authClientParameter(): array
    {
        return ['name' => 'authclient', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']];
    }

    /** @return array<string, mixed> */
    private static function itemTypeParameter(): array
    {
        return ['name' => 'itemType', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string', 'enum' => ['role', 'permission']]];
    }

    /** @return array<string, mixed> */
    private static function nameParameter(): array
    {
        return ['name' => 'name', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']];
    }

    /** @return list<array<string, mixed>> */
    private static function auditLogParameters(): array
    {
        return [
            self::parameter('actorUserId', 'query', ['type' => 'string'], required: false),
            self::parameter('targetUserId', 'query', ['type' => 'string'], required: false),
            self::parameter('action', 'query', ['type' => 'string'], required: false),
            self::parameter('page', 'query', ['type' => 'integer', 'minimum' => 1, 'default' => 1], required: false),
        ];
    }

    /** @return array<string, mixed> */
    private static function parameter(string $name, string $in, array $schema, bool $required): array
    {
        return ['name' => $name, 'in' => $in, 'required' => $required, 'schema' => $schema];
    }
}
