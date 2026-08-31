<?php

declare(strict_types=1);

return [
    'voyti-api-stateless-client.auth.login_password_required' => 'Требуются логин и пароль.',

    'voyti-api-stateless-client.registration.account_already_confirmed' => 'Аккаунт уже подтверждён.',
    'voyti-api-stateless-client.registration.account_confirmed' => 'Аккаунт подтверждён.',
    'voyti-api-stateless-client.registration.confirmation_email_sent' => 'Письмо с подтверждением отправлено, если аккаунт существует и не подтверждён.',

    'voyti-api-stateless-client.two_factor.already_enabled' => 'Двухфакторная аутентификация уже включена.',
    'voyti-api-stateless-client.two_factor.not_enabled' => 'Двухфакторная аутентификация не включена.',
    'voyti-api-stateless-client.two_factor.no_method_available' => 'Нет доступного метода двухфакторной аутентификации.',
    'voyti-api-stateless-client.two_factor.method_requires_own_endpoint' => 'Этот метод необходимо настроить через его собственную конечную точку.',
    'voyti-api-stateless-client.two_factor.backup_codes_regenerated' => 'Резервные коды перегенерированы.',
    'voyti-api-stateless-client.two_factor.challenge_invalid_or_expired' => 'Запрос недействителен или истёк.',
    'voyti-api-stateless-client.two_factor.method_unavailable' => 'Метод двухфакторной аутентификации больше недоступен.',
    'voyti-api-stateless-client.two_factor.verification_code_sent' => 'Код подтверждения отправлен.',
    'voyti-api-stateless-client.two_factor.webauthn_no_pending_registration' => 'Ожидающая регистрация WebAuthn не найдена.',

    'voyti-api-stateless-client.social_auth.code_invalid_or_expired' => 'Код недействителен или истёк.',
    'voyti-api-stateless-client.social_auth.redirect_url_not_configured' => 'URL перенаправления для социальной авторизации не настроен.',

    'voyti-api-stateless-client.rbac.invalid_or_missing_name' => 'Недопустимое или отсутствующее имя.',
    'voyti-api-stateless-client.rbac.name_already_exists' => 'Элемент с таким именем уже существует.',
    'voyti-api-stateless-client.rbac.invalid_name' => 'Недопустимое имя.',
    'voyti-api-stateless-client.rbac.invalid_children' => 'Недопустимые дочерние элементы.',
];
