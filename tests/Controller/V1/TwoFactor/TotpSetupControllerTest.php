<?php

declare(strict_types=1);

namespace YiiRocks\Voyti\Api\StatelessClient\tests\Controller\V1\TwoFactor;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use YiiRocks\Voyti\Api\StatelessClient\Controller\V1\TwoFactor\TotpSetupController;
use YiiRocks\Voyti\Api\StatelessClient\tests\Support\CurrentUserTrait;
use YiiRocks\Voyti\Api\StatelessClient\tests\Support\DatabaseTestCase;
use YiiRocks\Voyti\Api\StatelessClient\tests\Support\ExpectsResponseTrait;
use YiiRocks\Voyti\Api\StatelessClient\tests\Support\UserFactoryTrait;
use YiiRocks\Voyti\Api\StatelessClient\tests\Support\VoytiConfigFactory;
use YiiRocks\Voyti\Model\User;
use YiiRocks\Voyti\TwoFactor\Model\UserTwoFactor;
use YiiRocks\Voyti\TwoFactor\Totp\Service\QrCodeUriGeneratorService;
use Yiisoft\DataResponse\ResponseFactory\DataResponseFactoryInterface;
use Yiisoft\Http\Status;

#[AllowMockObjectsWithoutExpectations]
final class TotpSetupControllerTest extends DatabaseTestCase
{
    use CurrentUserTrait;
    use ExpectsResponseTrait;
    use UserFactoryTrait;

    private DataResponseFactoryInterface&MockObject $responseFactory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->responseFactory = $this->createMock(DataResponseFactoryInterface::class);
    }

    public function testRenew(): void
    {
        $user = $this->createUser('totprenewuser', 'totprenew@example.com');

        $response = $this->expectResponse(
            $this->callback(static fn(array $data): bool => $data['qrCodeUri'] !== '' && $data['secret'] !== null),
            Status::OK,
        );
        self::assertSame($response, $this->createController($user)->renew());
        $firstSecret = UserTwoFactor::forUser($user)->getSecret();
        self::assertNotNull($firstSecret);

        // Renewing again always issues a fresh secret, unlike show()'s reuse.
        $response = $this->expectResponse(
            $this->callback(static fn(array $data): bool => $data['secret'] !== null),
            Status::OK,
        );
        self::assertSame($response, $this->createController($user)->renew());
        self::assertNotSame($firstSecret, UserTwoFactor::forUser($user)->getSecret());

        // Already enabled
        $this->enableTwoFactor($user, 'totp');

        $response = $this->expectResponse(['error' => 'Two-factor authentication is already enabled.'], Status::BAD_REQUEST);
        self::assertSame($response, $this->createController($user)->renew());
    }

    public function testShow(): void
    {
        $user = $this->createUser('totpshowuser', 'totpshow@example.com');

        $response = $this->expectResponse(
            $this->callback(static fn(array $data): bool => $data['qrCodeUri'] !== '' && $data['secret'] !== null),
            Status::OK,
        );
        self::assertSame($response, $this->createController($user)->show());
        $secret = UserTwoFactor::forUser($user)->getSecret();
        self::assertNotNull($secret);

        // A second call reuses the existing secret rather than issuing a new one.
        $response = $this->expectResponse(
            $this->callback(static fn(array $data): bool => $data['secret'] === $secret),
            Status::OK,
        );
        self::assertSame($response, $this->createController($user)->show());

        // Already enabled
        $this->enableTwoFactor($user, 'totp');

        $response = $this->expectResponse(['error' => 'Two-factor authentication is already enabled.'], Status::BAD_REQUEST);
        self::assertSame($response, $this->createController($user)->show());
    }

    private function createController(User $user): TotpSetupController
    {
        return new TotpSetupController(
            $this->createCurrentUser($user),
            $this->responseFactory,
            new QrCodeUriGeneratorService(VoytiConfigFactory::create()),
        );
    }
}
