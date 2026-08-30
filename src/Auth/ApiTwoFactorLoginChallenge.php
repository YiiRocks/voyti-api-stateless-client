<?php

declare(strict_types=1);

namespace YiiRocks\Voyti\Api\StatelessClient\Auth;

use Override;
use Psr\Http\Message\ServerRequestInterface;
use YiiRocks\Voyti\Model\User;
use YiiRocks\Voyti\Model\UserToken;
use YiiRocks\Voyti\TwoFactor\Auth\TwoFactorLoginChallenge;
use YiiRocks\Voyti\TwoFactor\Model\UserTwoFactor;
use YiiRocks\Voyti\TwoFactor\TwoFactorMethodRegistry;
use Yiisoft\Security\Random;

/**
 * Bridges the optional `voyti-2fa` package into the SPA API's login flow: only bound when that
 * package is installed (see `config/di.php`'s `interface_exists()` guard). Parallel to
 * `voyti-2fa`'s own {@see TwoFactorLoginChallenge}, but issues a
 * short-lived {@see UserToken} instead of stashing pending credentials in a PHP session - a
 * stateless bearer-token client has no session to stash them in.
 */
final readonly class ApiTwoFactorLoginChallenge implements ApiLoginChallengeInterface
{
    /**
     * Matches the confirmation code lifespan a logged-out user would otherwise have to re-enter
     * within; long enough to type a TOTP/email code, short enough that a leaked challenge token
     * isn't useful for long.
     */
    public const int CHALLENGE_LIFESPAN = 300;

    public function __construct(
        private TwoFactorMethodRegistry $twoFactorMethods,
    ) {}

    #[Override]
    public function challenge(User $user, ServerRequestInterface $request): ?array
    {
        $twoFactor = UserTwoFactor::forUser($user);
        if (!$twoFactor->isEnabled() || !$this->twoFactorMethods->has($twoFactor->getMethod())) {
            return null;
        }

        $method = $this->twoFactorMethods->get((string) $twoFactor->getMethod());
        $method->onAuthenticationStepStart($user);

        $rawToken = Random::string(32);
        $challengeToken = new UserToken();
        $challengeToken->setUserId($user->getIdOrZero());
        $challengeToken->setType(UserToken::TYPE_API_CHALLENGE);
        $challengeToken->setCode(hash('sha256', $rawToken));
        $challengeToken->setCreatedAt(time());
        $challengeToken->save();

        return [
            'status' => 'challenge_required',
            'challengeToken' => $rawToken,
            'method' => $method->getName(),
            'isCodeBased' => $method->isCodeBased(),
            'expiresIn' => self::CHALLENGE_LIFESPAN,
        ];
    }
}
