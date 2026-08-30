<?php

declare(strict_types=1);

namespace YiiRocks\Voyti\Api\StatelessClient\tests\Controller\V1\AuditLog;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use YiiRocks\Voyti\Api\StatelessClient\Controller\V1\AuditLog\AuditLogController;
use YiiRocks\Voyti\Api\StatelessClient\tests\Support\DatabaseTestCase;
use YiiRocks\Voyti\Api\StatelessClient\tests\Support\ExpectsResponseTrait;
use YiiRocks\Voyti\Model\UserAuditLog;
use Yiisoft\DataResponse\ResponseFactory\DataResponseFactoryInterface;
use Yiisoft\Http\Status;

#[AllowMockObjectsWithoutExpectations]
final class AuditLogControllerTest extends DatabaseTestCase
{
    use ExpectsResponseTrait;

    private DataResponseFactoryInterface&MockObject $responseFactory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->responseFactory = $this->createMock(DataResponseFactoryInterface::class);
    }

    public function testIndex(): void
    {
        $this->createLog(actorUserId: 1, targetUserId: 2, action: 'user.create', context: '{"a":1}', createdAt: 1000);
        $this->createLog(actorUserId: 1, targetUserId: 3, action: 'user.block', context: null, createdAt: 2000);
        $this->createLog(actorUserId: 5, targetUserId: 2, action: 'rbac.role.create', context: null, createdAt: 3000);

        // No filters: all logs, newest first, full item shape
        $response = $this->expectResponse([
            'items' => [
                [
                    'id' => 3,
                    'actorUserId' => 5,
                    'targetUserId' => 2,
                    'targetName' => null,
                    'action' => 'rbac.role.create',
                    'context' => null,
                    'actorIp' => '127.0.0.1',
                    'createdAt' => 3000,
                ],
                [
                    'id' => 2,
                    'actorUserId' => 1,
                    'targetUserId' => 3,
                    'targetName' => null,
                    'action' => 'user.block',
                    'context' => null,
                    'actorIp' => '127.0.0.1',
                    'createdAt' => 2000,
                ],
                [
                    'id' => 1,
                    'actorUserId' => 1,
                    'targetUserId' => 2,
                    'targetName' => null,
                    'action' => 'user.create',
                    'context' => '{"a":1}',
                    'actorIp' => '127.0.0.1',
                    'createdAt' => 1000,
                ],
            ],
            'totalCount' => 3,
            'currentPage' => 1,
            'pageSize' => 50,
            'totalPages' => 1,
        ], Status::OK);
        self::assertSame($response, $this->createController()->index());

        // Filter by actorUserId
        $response = $this->expectResponse(
            $this->callback(static fn(array $data): bool => $data['totalCount'] === 2),
            Status::OK,
        );
        self::assertSame($response, $this->createController()->index(actorUserId: '1'));

        // Filter by action (LIKE match)
        $response = $this->expectResponse(
            $this->callback(static fn(array $data): bool => $data['totalCount'] === 1 && $data['items'][0]['action'] === 'user.block'),
            Status::OK,
        );
        self::assertSame($response, $this->createController()->index(action: 'block'));

        // Filter by targetUserId
        $response = $this->expectResponse(
            $this->callback(static fn(array $data): bool => $data['totalCount'] === 2),
            Status::OK,
        );
        self::assertSame($response, $this->createController()->index(targetUserId: '2'));

        // Page clamped below range (0 -> 1, and above total pages -> total pages)
        $response = $this->expectResponse(
            $this->callback(static fn(array $data): bool => $data['currentPage'] === 1),
            Status::OK,
        );
        self::assertSame($response, $this->createController()->index(page: 0));

        $response = $this->expectResponse(
            $this->callback(static fn(array $data): bool => $data['currentPage'] === 1),
            Status::OK,
        );
        self::assertSame($response, $this->createController()->index(page: 999));

        // No matching logs at all: totalPages floors to 1, not 0, so page=1 stays 1
        $response = $this->expectResponse(
            $this->callback(static fn(array $data): bool => $data['currentPage'] === 1 && $data['totalCount'] === 0),
            Status::OK,
        );
        self::assertSame($response, $this->createController()->index(action: 'no-such-action', page: 1));

        // More logs than one page: page=1 must not be floored up to 2
        for ($i = 0; $i < 48; $i++) {
            $this->createLog(actorUserId: 9, targetUserId: null, action: 'bulk.action', context: null, createdAt: 4000 + $i);
        }
        $response = $this->expectResponse(
            $this->callback(static fn(array $data): bool => $data['currentPage'] === 1 && $data['totalPages'] === 2),
            Status::OK,
        );
        self::assertSame($response, $this->createController()->index(page: 1));
    }

    private function createController(): AuditLogController
    {
        return new AuditLogController($this->responseFactory);
    }

    private function createLog(?int $actorUserId, ?int $targetUserId, string $action, ?string $context, int $createdAt): void
    {
        $log = new UserAuditLog();
        $log->setActorUserId($actorUserId);
        $log->setTargetUserId($targetUserId);
        $log->setAction($action);
        $log->setContext($context);
        $log->setActorIp('127.0.0.1');
        $log->setCreatedAt($createdAt);
        $log->save();
    }
}
