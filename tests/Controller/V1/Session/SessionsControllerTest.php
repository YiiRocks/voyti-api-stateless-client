<?php

declare(strict_types=1);

namespace YiiRocks\Voyti\Api\StatelessClient\tests\Controller\V1\Session;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use YiiRocks\Voyti\Api\Service\User\ApiTokenService;
use YiiRocks\Voyti\Api\StatelessClient\Controller\V1\Session\SessionsController;
use YiiRocks\Voyti\Api\StatelessClient\tests\Support\CurrentUserTrait;
use YiiRocks\Voyti\Api\StatelessClient\tests\Support\DatabaseTestCase;
use YiiRocks\Voyti\Api\StatelessClient\tests\Support\ExpectsResponseTrait;
use YiiRocks\Voyti\Api\StatelessClient\tests\Support\UserFactoryTrait;
use YiiRocks\Voyti\Clock\SystemClock;
use YiiRocks\Voyti\Model\User;
use Yiisoft\DataResponse\ResponseFactory\DataResponseFactoryInterface;
use Yiisoft\Http\Status;

#[AllowMockObjectsWithoutExpectations]
final class SessionsControllerTest extends DatabaseTestCase
{
    use CurrentUserTrait;
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

    public function testIndex(): void
    {
        $user = $this->createUser('sessionsuser', 'sessions@example.com');
        $this->apiTokenService->generate($user);
        $this->apiTokenService->generate($user);

        $response = $this->expectResponse(
            $this->callback(static fn(array $data): bool => count($data['items']) === 2 && isset($data['items'][0]['id'], $data['items'][0]['createdAt'])),
            Status::OK,
        );

        self::assertSame($response, $this->createController($user)->index());
    }

    public function testTerminate(): void
    {
        $user = $this->createUser('terminateuser', 'terminate@example.com');
        $rawToken = $this->apiTokenService->generate($user);
        $hash = hash('sha256', $rawToken);

        // Not found
        $response = $this->expectResponse(['error' => 'Not found'], Status::NOT_FOUND);
        self::assertSame($response, $this->createController($user)->terminate('not-a-real-hash'));

        // Success
        $response = $this->expectResponse(['message' => 'Session terminated.'], Status::OK);
        self::assertSame($response, $this->createController($user)->terminate($hash));
    }

    private function createController(User $user): SessionsController
    {
        return new SessionsController($this->apiTokenService, $this->createCurrentUser($user), $this->responseFactory);
    }
}
