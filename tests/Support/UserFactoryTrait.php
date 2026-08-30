<?php

declare(strict_types=1);

namespace YiiRocks\Voyti\Api\StatelessClient\tests\Support;

use YiiRocks\Voyti\Model\User;
use YiiRocks\Voyti\Model\UserToken;
use YiiRocks\Voyti\TwoFactor\Model\UserTwoFactor;

trait UserFactoryTrait
{
    /**
     * Builds an in-memory `User`, without persisting it - for unit tests that receive a
     * `User` as a plain argument and never look it up from the database.
     */
    private function buildUser(
        string $username = 'testuser',
        ?string $email = null,
    ): User {
        $user = new User();
        $user->setUsername($username);
        $user->setEmail($email ?? $username . '@example.com');
        $user->setPasswordHash('hash');
        $user->setAuthKey('key');
        $user->setCreatedAt(time());
        $user->setUpdatedAt(time());

        return $user;
    }

    /**
     * Persists a raw API challenge token for `$userId`, hashed the way `UserToken` expects it.
     */
    private function createChallengeToken(int $userId, string $rawToken): void
    {
        $userToken = new UserToken();
        $userToken->setUserId($userId);
        $userToken->setType(UserToken::TYPE_API_CHALLENGE);
        $userToken->setCode(hash('sha256', $rawToken));
        $userToken->setCreatedAt(time());
        $userToken->save();
    }

    private function createUser(
        string $username = 'testuser',
        string $email = 'test@example.com',
        string $passwordHash = 'hash',
        ?int $createdAt = null,
        ?int $confirmedAt = null,
        ?int $blockedAt = null,
        ?string $lastLoginIp = null,
        ?int $dataProcessingConsentDate = null,
    ): User {
        $timestamp = $createdAt ?? time();

        $user = new User();
        $user->setUsername($username);
        $user->setEmail($email);
        $user->setPasswordHash($passwordHash);
        $user->setAuthKey('key');
        $user->setCreatedAt($timestamp);
        $user->setUpdatedAt($timestamp);
        if ($confirmedAt !== null) {
            $user->setConfirmedAt($confirmedAt);
        }
        if ($blockedAt !== null) {
            $user->setBlockedAt($blockedAt);
        }
        if ($lastLoginIp !== null) {
            $user->setLastLoginIp($lastLoginIp);
        }
        if ($dataProcessingConsentDate !== null) {
            $user->setDataProcessingConsentDate($dataProcessingConsentDate);
        }
        $user->save();

        return $user;
    }

    /**
     * Enables 2FA on `$user` with the given method name and persists it.
     */
    private function enableTwoFactor(User $user, string $method): UserTwoFactor
    {
        $twoFactor = UserTwoFactor::forUser($user);
        $twoFactor->setEnabled(true);
        $twoFactor->setMethod($method);
        $twoFactor->save();

        return $twoFactor;
    }
}
