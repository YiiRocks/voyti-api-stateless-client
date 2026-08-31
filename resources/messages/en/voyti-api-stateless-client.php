<?php

declare(strict_types=1);

return [
    'voyti-api-stateless-client.auth.login_password_required' => 'Login and password are required.',

    'voyti-api-stateless-client.registration.account_already_confirmed' => 'Account already confirmed.',
    'voyti-api-stateless-client.registration.account_confirmed' => 'Account confirmed.',
    'voyti-api-stateless-client.registration.confirmation_email_sent' => 'Confirmation email sent if the account exists and is unconfirmed.',

    'voyti-api-stateless-client.two_factor.already_enabled' => 'Two-factor authentication is already enabled.',
    'voyti-api-stateless-client.two_factor.not_enabled' => 'Two-factor authentication is not enabled.',
    'voyti-api-stateless-client.two_factor.no_method_available' => 'No two-factor method is available.',
    'voyti-api-stateless-client.two_factor.method_requires_own_endpoint' => 'This method must be set up through its own endpoint.',
    'voyti-api-stateless-client.two_factor.backup_codes_regenerated' => 'Backup codes regenerated.',
    'voyti-api-stateless-client.two_factor.challenge_invalid_or_expired' => 'Challenge is invalid or expired.',
    'voyti-api-stateless-client.two_factor.method_unavailable' => 'Two-factor method is no longer available.',
    'voyti-api-stateless-client.two_factor.verification_code_sent' => 'Verification code sent.',
    'voyti-api-stateless-client.two_factor.webauthn_no_pending_registration' => 'No pending WebAuthn registration was found.',

    'voyti-api-stateless-client.social_auth.code_invalid_or_expired' => 'Code is invalid or expired.',
    'voyti-api-stateless-client.social_auth.redirect_url_not_configured' => 'Social auth redirect URL is not configured.',

    'voyti-api-stateless-client.rbac.invalid_or_missing_name' => 'Invalid or missing name.',
    'voyti-api-stateless-client.rbac.name_already_exists' => 'An item with this name already exists.',
    'voyti-api-stateless-client.rbac.invalid_name' => 'Invalid name.',
    'voyti-api-stateless-client.rbac.invalid_children' => 'Invalid children.',
    'voyti-api-stateless-client.rbac.user_not_found' => 'One or more user IDs do not match any user.',
    'voyti-api-stateless-client.rbac.assignments.updated' => 'Assignments updated.',
    'voyti-api-stateless-client.rbac.permission_assignment_disabled' => 'Assigning permissions directly is disabled. Assign the permission through a role instead.',
];
