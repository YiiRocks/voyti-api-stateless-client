<?php

declare(strict_types=1);

namespace YiiRocks\Voyti\Api\StatelessClient\tests\Controller\V1\SocialAuth;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use YiiRocks\Voyti\Api\Service\User\ApiTokenService;
use YiiRocks\Voyti\Api\StatelessClient\Controller\V1\SocialAuth\SocialExchangeController;
use YiiRocks\Voyti\Api\StatelessClient\tests\Support\DatabaseTestCase;
use YiiRocks\Voyti\Api\StatelessClient\tests\Support\ExpectsResponseTrait;
use YiiRocks\Voyti\Api\StatelessClient\tests\Support\UserFactoryTrait;
use YiiRocks\Voyti\Clock\SystemClock;
use YiiRocks\Voyti\Model\UserToken;
use Yiisoft\DataResponse\ResponseFactory\DataResponseFactoryInterface;
use Yiisoft\Http\Status;

#[AllowMockObjectsWithoutExpectations]
final class SocialExchangeControllerTest extends DatabaseTestCase
{
    use ExpectsResponseTrait;
    use UserFactoryTrait;

    private ApiTokenService $apiTokenService;
    private DataResponseFactoryInterface&MockObject $responseFactory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->apiTokenService = new ApiTokenService(new SystemClock());
        $this->responseFactory = $this->createMock(DataResponseFactoryInterface::class);
    }

    public function testExchange(): void
    {
        $user = $this->createUser('exchangeuser', 'exchange@example.com');
        $rawCode = 'raw-exchange-code';
        $exchangeToken = new UserToken();
        $exchangeToken->setUserId((int) $user->getId());
        $exchangeToken->setType(UserToken::TYPE_API_SOCIAL_EXCHANGE);
        $exchangeToken->setCode(hash('sha256', $rawCode));
        $exchangeToken->setCreatedAt(time());
        $exchangeToken->save();

        // Invalid/unknown code
        $response = $this->expectResponse(['error' => 'Code is invalid or expired.'], Status::BAD_REQUEST);
        self::assertSame($response, $this->createController()->exchange(code: 'wrong'));

        // Valid code: issues a token and consumes the exchange code
        $response = $this->expectResponse(
            $this->callback(static fn(array $data): bool => $data['status'] === 'ok' && strlen($data['token']) === 64),
            Status::OK,
        );
        self::assertSame($response, $this->createController()->exchange(code: $rawCode));
        self::assertNull(UserToken::findByUserIdAndCodeAndType((int) $user->getId(), $rawCode, UserToken::TYPE_API_SOCIAL_EXCHANGE));

        // Reusing the (now-deleted) code fails
        $response = $this->expectResponse(['error' => 'Code is invalid or expired.'], Status::BAD_REQUEST);
        self::assertSame($response, $this->createController()->exchange(code: $rawCode));
    }

    public function testExchangeOrphanedCode(): void
    {
        $rawCode = 'raw-orphan-code';
        $exchangeToken = new UserToken();
        $exchangeToken->setUserId(999999);
        $exchangeToken->setType(UserToken::TYPE_API_SOCIAL_EXCHANGE);
        $exchangeToken->setCode(hash('sha256', $rawCode));
        $exchangeToken->setCreatedAt(time());
        $exchangeToken->save();

        $response = $this->expectResponse(['error' => 'Code is invalid or expired.'], Status::BAD_REQUEST);
        self::assertSame($response, $this->createController()->exchange(code: $rawCode));
    }

    private function createController(): SocialExchangeController
    {
        return new SocialExchangeController($this->apiTokenService, $this->responseFactory);
    }
}
