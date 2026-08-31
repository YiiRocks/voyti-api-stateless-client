<?php

declare(strict_types=1);

return [
    'voyti-api-stateless-client.auth.login_password_required' => 'Se requieren usuario y contraseña.',

    'voyti-api-stateless-client.registration.account_already_confirmed' => 'La cuenta ya está confirmada.',
    'voyti-api-stateless-client.registration.account_confirmed' => 'Cuenta confirmada.',
    'voyti-api-stateless-client.registration.confirmation_email_sent' => 'Correo de confirmación enviado si la cuenta existe y no está confirmada.',

    'voyti-api-stateless-client.two_factor.already_enabled' => 'La autenticación de dos factores ya está activada.',
    'voyti-api-stateless-client.two_factor.not_enabled' => 'La autenticación de dos factores no está activada.',
    'voyti-api-stateless-client.two_factor.no_method_available' => 'No hay ningún método de dos factores disponible.',
    'voyti-api-stateless-client.two_factor.method_requires_own_endpoint' => 'Este método debe configurarse mediante su propio endpoint.',
    'voyti-api-stateless-client.two_factor.backup_codes_regenerated' => 'Códigos de respaldo regenerados.',
    'voyti-api-stateless-client.two_factor.challenge_invalid_or_expired' => 'El desafío no es válido o ha caducado.',
    'voyti-api-stateless-client.two_factor.method_unavailable' => 'El método de dos factores ya no está disponible.',
    'voyti-api-stateless-client.two_factor.verification_code_sent' => 'Código de verificación enviado.',
    'voyti-api-stateless-client.two_factor.webauthn_no_pending_registration' => 'No se encontró ningún registro de WebAuthn pendiente.',

    'voyti-api-stateless-client.social_auth.code_invalid_or_expired' => 'El código no es válido o ha caducado.',
    'voyti-api-stateless-client.social_auth.redirect_url_not_configured' => 'La URL de redirección de inicio de sesión social no está configurada.',

    'voyti-api-stateless-client.rbac.invalid_or_missing_name' => 'Nombre no válido o faltante.',
    'voyti-api-stateless-client.rbac.name_already_exists' => 'Ya existe un elemento con este nombre.',
    'voyti-api-stateless-client.rbac.invalid_name' => 'Nombre no válido.',
    'voyti-api-stateless-client.rbac.invalid_children' => 'Elementos secundarios no válidos.',
    'voyti-api-stateless-client.rbac.user_not_found' => 'Uno o más ID de usuario no coinciden con ningún usuario.',
    'voyti-api-stateless-client.rbac.assignments.updated' => 'Asignaciones actualizadas.',
    'voyti-api-stateless-client.rbac.permission_assignment_disabled' => 'La asignación directa de permisos está deshabilitada. Asigne el permiso a través de un rol en su lugar.',
];
