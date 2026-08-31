<?php

declare(strict_types=1);

return [
    'voyti-api-stateless-client.auth.login_password_required' => 'Identifiant et mot de passe requis.',

    'voyti-api-stateless-client.registration.account_already_confirmed' => 'Le compte est déjà confirmé.',
    'voyti-api-stateless-client.registration.account_confirmed' => 'Compte confirmé.',
    'voyti-api-stateless-client.registration.confirmation_email_sent' => "E-mail de confirmation envoyé si le compte existe et n'est pas confirmé.",

    'voyti-api-stateless-client.two_factor.already_enabled' => "L'authentification à deux facteurs est déjà activée.",
    'voyti-api-stateless-client.two_factor.not_enabled' => "L'authentification à deux facteurs n'est pas activée.",
    'voyti-api-stateless-client.two_factor.no_method_available' => "Aucune méthode à deux facteurs n'est disponible.",
    'voyti-api-stateless-client.two_factor.method_requires_own_endpoint' => 'Cette méthode doit être configurée via son propre point de terminaison.',
    'voyti-api-stateless-client.two_factor.backup_codes_regenerated' => 'Codes de secours régénérés.',
    'voyti-api-stateless-client.two_factor.challenge_invalid_or_expired' => 'Le défi est invalide ou expiré.',
    'voyti-api-stateless-client.two_factor.method_unavailable' => "La méthode à deux facteurs n'est plus disponible.",
    'voyti-api-stateless-client.two_factor.verification_code_sent' => 'Code de vérification envoyé.',
    'voyti-api-stateless-client.two_factor.webauthn_no_pending_registration' => "Aucune inscription WebAuthn en attente n'a été trouvée.",

    'voyti-api-stateless-client.social_auth.code_invalid_or_expired' => 'Le code est invalide ou expiré.',
    'voyti-api-stateless-client.social_auth.redirect_url_not_configured' => "L'URL de redirection de l'authentification sociale n'est pas configurée.",

    'voyti-api-stateless-client.rbac.invalid_or_missing_name' => 'Nom invalide ou manquant.',
    'voyti-api-stateless-client.rbac.name_already_exists' => 'Un élément portant ce nom existe déjà.',
    'voyti-api-stateless-client.rbac.invalid_name' => 'Nom invalide.',
    'voyti-api-stateless-client.rbac.invalid_children' => 'Éléments enfants invalides.',
];
