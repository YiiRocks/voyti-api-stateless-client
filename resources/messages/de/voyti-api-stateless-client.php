<?php

declare(strict_types=1);

return [
    'voyti-api-stateless-client.auth.login_password_required' => 'Anmeldename und Passwort sind erforderlich.',

    'voyti-api-stateless-client.registration.account_already_confirmed' => 'Konto ist bereits bestätigt.',
    'voyti-api-stateless-client.registration.account_confirmed' => 'Konto bestätigt.',
    'voyti-api-stateless-client.registration.confirmation_email_sent' => 'Bestätigungs-E-Mail wurde gesendet, falls das Konto existiert und noch nicht bestätigt ist.',

    'voyti-api-stateless-client.two_factor.already_enabled' => 'Zwei-Faktor-Authentifizierung ist bereits aktiviert.',
    'voyti-api-stateless-client.two_factor.not_enabled' => 'Zwei-Faktor-Authentifizierung ist nicht aktiviert.',
    'voyti-api-stateless-client.two_factor.no_method_available' => 'Es ist keine Zwei-Faktor-Methode verfügbar.',
    'voyti-api-stateless-client.two_factor.method_requires_own_endpoint' => 'Diese Methode muss über ihren eigenen Endpunkt eingerichtet werden.',
    'voyti-api-stateless-client.two_factor.backup_codes_regenerated' => 'Backup-Codes neu generiert.',
    'voyti-api-stateless-client.two_factor.challenge_invalid_or_expired' => 'Challenge ist ungültig oder abgelaufen.',
    'voyti-api-stateless-client.two_factor.method_unavailable' => 'Zwei-Faktor-Methode ist nicht mehr verfügbar.',
    'voyti-api-stateless-client.two_factor.verification_code_sent' => 'Bestätigungscode gesendet.',
    'voyti-api-stateless-client.two_factor.webauthn_no_pending_registration' => 'Es wurde keine ausstehende WebAuthn-Registrierung gefunden.',

    'voyti-api-stateless-client.social_auth.code_invalid_or_expired' => 'Code ist ungültig oder abgelaufen.',
    'voyti-api-stateless-client.social_auth.redirect_url_not_configured' => 'Die Weiterleitungs-URL für die Social-Anmeldung ist nicht konfiguriert.',

    'voyti-api-stateless-client.rbac.invalid_or_missing_name' => 'Ungültiger oder fehlender Name.',
    'voyti-api-stateless-client.rbac.name_already_exists' => 'Ein Element mit diesem Namen existiert bereits.',
    'voyti-api-stateless-client.rbac.invalid_name' => 'Ungültiger Name.',
    'voyti-api-stateless-client.rbac.invalid_children' => 'Ungültige Unterelemente.',
];
