<?php

declare(strict_types=1);

namespace YiiRocks\Voyti\Api\StatelessClient\tests\Auth;

use Nyholm\Psr7\ServerRequest;
use YiiRocks\Voyti\Api\StatelessClient\Auth\ApiTwoFactorLoginChallenge;
use YiiRocks\Voyti\Api\StatelessClient\tests\Support\DatabaseTestCase;
use YiiRocks\Voyti\Api\StatelessClient\tests\Support\FakeTwoFactorMethod;
use YiiRocks\Voyti\Api\StatelessClient\tests\Support\UserFactoryTrait;
use YiiRocks\Voyti\Model\UserToken;
use YiiRocks\Voyti\TwoFactor\TwoFactorMethodRegistry;

final class ApiTwoFactorLoginChallengeTest extends DatabaseTestCase
{
    use UserFactoryTrait;

    public function testChallenge(): void
    {
        $user = $this->createUser('twofactoruser', 'twofactor@example.com');
        $method = $this->fakeMethod();
        $challenge = new ApiTwoFactorLoginChallenge(new TwoFactorMethodRegistry([$method]));

        // 2FA not enabled: proceeds
        self::assertNull($challenge->challenge($user, new ServerRequest('POST', '/')));

        // Enabled with an unregistered method: proceeds (treated as if 2FA were off)
        $twoFactor = $this->enableTwoFactor($user, 'unregistered');
        self::assertNull($challenge->challenge($user, new ServerRequest('POST', '/')));

        // Enabled with a registered method: issues a challenge token
        $twoFactor->setMethod('fake');
        $twoFactor->save();
        $result = $challenge->challenge($user, new ServerRequest('POST', '/'));

        self::assertNotNull($result);
        self::assertSame('challenge_required', $result['status']);
        self::assertSame('fake', $result['method']);
        self::assertTrue($result['isCodeBased']);
        self::assertSame(300, $result['expiresIn']);
        self::assertTrue($method->stepStarted);
        self::assertSame(32, strlen($result['challengeToken']));

        $stored = UserToken::findByUserIdAndCodeAndType(
            (int) $user->getId(),
            $result['challengeToken'],
            UserToken::TYPE_API_CHALLENGE,
        );
        self::assertNotNull($stored);
        self::assertGreaterThan(0, $stored->getCreatedAt());
    }

    private function fakeMethod(): FakeTwoFactorMethod
    {
        return new FakeTwoFactorMethod(
            errorMessage: '',
            verify: static fn(mixed $user, array $data): bool => ($data['code'] ?? '') === 'correct-code',
        );
    }
}
