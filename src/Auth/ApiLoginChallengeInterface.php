<?php

declare(strict_types=1);

namespace YiiRocks\Voyti\Api\StatelessClient\Auth;

use Psr\Http\Message\ServerRequestInterface;
use YiiRocks\Voyti\Auth\LoginChallengeInterface;
use YiiRocks\Voyti\Model\User;

/**
 * A step that may interrupt a successful password login to demand an additional check (e.g.
 * two-factor authentication) before a bearer token is issued. Handlers are collected via the
 * `voyti-api.login-challenge` DI tag and consulted in order; the first one to return a non-null
 * result short-circuits login with that JSON payload, while returning null lets login proceed.
 * Parallel to core's {@see LoginChallengeInterface}, which returns an HTML
 * response and can't be reused directly for a JSON API.
 */
interface ApiLoginChallengeInterface
{
    /**
     * @return array<string, mixed>|null Response body for a JSON challenge, or null to let login proceed.
     */
    public function challenge(User $user, ServerRequestInterface $request): ?array;
}
