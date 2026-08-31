<?php

declare(strict_types=1);

return [
    'voyti-api-stateless-client.auth.login_password_required' => 'Login en wachtwoord zijn verplicht.',

    'voyti-api-stateless-client.registration.account_already_confirmed' => 'Account is al bevestigd.',
    'voyti-api-stateless-client.registration.account_confirmed' => 'Account bevestigd.',
    'voyti-api-stateless-client.registration.confirmation_email_sent' => 'Bevestigingsmail verzonden als het account bestaat en nog niet is bevestigd.',

    'voyti-api-stateless-client.two_factor.already_enabled' => 'Tweefactorauthenticatie is al ingeschakeld.',
    'voyti-api-stateless-client.two_factor.not_enabled' => 'Tweefactorauthenticatie is niet ingeschakeld.',
    'voyti-api-stateless-client.two_factor.no_method_available' => 'Er is geen tweefactormethode beschikbaar.',
    'voyti-api-stateless-client.two_factor.method_requires_own_endpoint' => 'Deze methode moet via haar eigen eindpunt worden ingesteld.',
    'voyti-api-stateless-client.two_factor.backup_codes_regenerated' => 'Back-upcodes opnieuw gegenereerd.',
    'voyti-api-stateless-client.two_factor.challenge_invalid_or_expired' => 'Uitdaging is ongeldig of verlopen.',
    'voyti-api-stateless-client.two_factor.method_unavailable' => 'Tweefactormethode is niet meer beschikbaar.',
    'voyti-api-stateless-client.two_factor.verification_code_sent' => 'Verificatiecode verzonden.',
    'voyti-api-stateless-client.two_factor.webauthn_no_pending_registration' => 'Er is geen openstaande WebAuthn-registratie gevonden.',

    'voyti-api-stateless-client.social_auth.code_invalid_or_expired' => 'Code is ongeldig of verlopen.',
    'voyti-api-stateless-client.social_auth.redirect_url_not_configured' => 'Social-auth-omleidings-URL is niet geconfigureerd.',

    'voyti-api-stateless-client.rbac.invalid_or_missing_name' => 'Ongeldige of ontbrekende naam.',
    'voyti-api-stateless-client.rbac.name_already_exists' => 'Er bestaat al een item met deze naam.',
    'voyti-api-stateless-client.rbac.invalid_name' => 'Ongeldige naam.',
    'voyti-api-stateless-client.rbac.invalid_children' => 'Ongeldige subitems.',
    'voyti-api-stateless-client.rbac.user_not_found' => 'Een of meer gebruikers-ID\'s komen niet overeen met een gebruiker.',
    'voyti-api-stateless-client.rbac.assignments.updated' => 'Toewijzingen bijgewerkt.',
    'voyti-api-stateless-client.rbac.permission_assignment_disabled' => 'Directe toewijzing van rechten is uitgeschakeld. Wijs het recht in plaats daarvan toe via een rol.',
];
