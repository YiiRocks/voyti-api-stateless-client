<?php

declare(strict_types=1);

namespace YiiRocks\Voyti\Api\StatelessClient\tests\Controller\V1\Rbac;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use YiiRocks\Voyti\Api\StatelessClient\Controller\V1\Rbac\RbacAssignmentController;
use YiiRocks\Voyti\Api\StatelessClient\tests\Support\CurrentUserTrait;
use YiiRocks\Voyti\Api\StatelessClient\tests\Support\DatabaseTestCase;
use YiiRocks\Voyti\Api\StatelessClient\tests\Support\ExpectsResponseTrait;
use YiiRocks\Voyti\Api\StatelessClient\tests\Support\SimpleAssignmentsStorage;
use YiiRocks\Voyti\Api\StatelessClient\tests\Support\SimpleItemsStorage;
use YiiRocks\Voyti\Api\StatelessClient\tests\Support\UserFactoryTrait;
use YiiRocks\Voyti\Api\StatelessClient\tests\Support\VoytiConfigFactory;
use YiiRocks\Voyti\Model\User;
use YiiRocks\Voyti\Model\UserAuditLog;
use YiiRocks\Voyti\Service\AuditLogService;
use Yiisoft\DataResponse\ResponseFactory\DataResponseFactoryInterface;
use Yiisoft\Http\Status;
use Yiisoft\Rbac\Assignment;
use Yiisoft\Rbac\AssignmentsStorageInterface;
use Yiisoft\Rbac\ItemsStorageInterface;
use Yiisoft\Rbac\Manager;
use Yiisoft\Rbac\ManagerInterface;
use Yiisoft\Rbac\Permission;
use Yiisoft\Rbac\Role;

#[AllowMockObjectsWithoutExpectations]
final class RbacAssignmentControllerTest extends DatabaseTestCase
{
    use CurrentUserTrait;
    use ExpectsResponseTrait;
    use UserFactoryTrait;

    private User $actor;
    private AssignmentsStorageInterface $assignmentsStorage;
    private ItemsStorageInterface $itemsStorage;
    private ManagerInterface $manager;
    private DataResponseFactoryInterface&MockObject $responseFactory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->itemsStorage = new SimpleItemsStorage();
        $this->assignmentsStorage = new SimpleAssignmentsStorage();
        $this->manager = new Manager($this->itemsStorage, $this->assignmentsStorage);
        $this->responseFactory = $this->createMock(DataResponseFactoryInterface::class);
        $this->actor = $this->createUser('rbacadmin', 'rbacadmin@example.com');
    }

    public static function itemTypeProvider(): array
    {
        return [
            'role' => ['role'],
            'permission' => ['permission'],
        ];
    }

    #[DataProvider('itemTypeProvider')]
    public function testIndex(string $itemType): void
    {
        // Not found
        $response = $this->expectResponse(['error' => 'Authorization item not found'], Status::NOT_FOUND);
        self::assertSame($response, $this->createController()->index($itemType, 'missing'));

        // Empty assignment list
        $this->addItem($itemType, 'editor');
        $response = $this->expectResponse(['assignments' => []], Status::OK);
        self::assertSame($response, $this->createController()->index($itemType, 'editor'));

        // Assignments resolved to usernames (roles only: direct permission assignment is disabled)
        if ($itemType === 'role') {
            $first = $this->createUser('firstuser', 'first@example.com');
            $second = $this->createUser('seconduser', 'second@example.com');
            $this->manager->assign('editor', (int) $first->getId());
            $this->manager->assign('editor', (int) $second->getId());
            $response = $this->expectResponse([
                'assignments' => [
                    ['id' => (string) $first->getId(), 'username' => 'firstuser'],
                    ['id' => (string) $second->getId(), 'username' => 'seconduser'],
                ],
            ], Status::OK);
            self::assertSame($response, $this->createController()->index('role', 'editor'));
        }
    }

    #[DataProvider('itemTypeProvider')]
    public function testUpdate(string $itemType): void
    {
        // Not found
        $response = $this->expectResponse(['error' => 'Authorization item not found'], Status::NOT_FOUND);
        self::assertSame($response, $this->createController()->update(new ServerRequest('PUT', '/'), $itemType, 'missing'));

        // Unknown user IDs are rejected before any assignment
        $this->addItem($itemType, 'editor');
        $response = $this->expectResponse(
            ['error' => 'One or more user IDs do not match any user.', 'userIds' => ['999999']],
            Status::BAD_REQUEST,
        );
        self::assertSame(
            $response,
            $this->createController()->update(new ServerRequest('PUT', '/'), $itemType, 'editor', userIds: ['999999']),
        );

        // An empty set revokes an existing assignment
        $this->assignmentsStorage->add(new Assignment((string) $this->actor->getId(), 'editor', time()));
        $response = $this->expectResponse(['message' => 'Assignments updated.'], Status::OK);
        self::assertSame(
            $response,
            $this->createController()->update(new ServerRequest('PUT', '/'), $itemType, 'editor', userIds: []),
        );
        self::assertFalse($this->assignmentsStorage->exists('editor', (string) $this->actor->getId()));

        // Roles: keep assigned, add new (deduping mixed/duplicate int+string IDs), revoke stale, audit-log
        if ($itemType === 'role') {
            $keep = $this->createUser('keepuser', 'keep@example.com');
            $stale = $this->createUser('staleuser', 'stale@example.com');
            $added = $this->createUser('addeduser', 'added@example.com');
            $this->manager->assign('editor', (int) $keep->getId());
            $this->manager->assign('editor', (int) $stale->getId());

            $addedId = (int) $added->getId();
            $response = $this->expectResponse(['message' => 'Assignments updated.'], Status::OK);
            self::assertSame(
                $response,
                $this->createController()->update(
                    new ServerRequest('PUT', '/'),
                    'role',
                    'editor',
                    userIds: [(int) $keep->getId(), $addedId, (string) $addedId, (string) $addedId],
                ),
            );
            self::assertTrue($this->assignmentsStorage->exists('editor', (string) $keep->getId()));
            self::assertTrue($this->assignmentsStorage->exists('editor', (string) $added->getId()));
            self::assertFalse($this->assignmentsStorage->exists('editor', (string) $stale->getId()));
            $log = $this->lastAuditLog();
            self::assertSame((int) $this->actor->getId(), $log?->getActorUserId());
            self::assertSame('rbac.role.assignments.update', $log?->getAction());
            self::assertSame('editor', $log?->getTargetName());
        }

        // Permissions: direct assignment is disabled, so the controller rejects the request
        if ($itemType === 'permission') {
            $permUser = $this->createUser('permuser', 'perm@example.com');
            $this->addItem('permission', 'edit-posts');
            $response = $this->expectResponse(
                ['error' => 'Assigning permissions directly is disabled. Assign the permission through a role instead.'],
                Status::BAD_REQUEST,
            );
            self::assertSame(
                $response,
                $this->createController()->update(
                    new ServerRequest('PUT', '/'),
                    'permission',
                    'edit-posts',
                    userIds: [(int) $permUser->getId()],
                ),
            );
            self::assertFalse($this->assignmentsStorage->exists('edit-posts', (string) $permUser->getId()));
        }
    }

    private function addItem(string $itemType, string $name): void
    {
        $item = $itemType === 'role' ? new Role($name) : new Permission($name);
        $itemType === 'role' ? $this->manager->addRole($item) : $this->manager->addPermission($item);
    }

    private function createController(): RbacAssignmentController
    {
        return new RbacAssignmentController(
            $this->assignmentsStorage,
            new AuditLogService(VoytiConfigFactory::create()),
            $this->createCurrentUser($this->actor),
            $this->responseFactory,
            $this->itemsStorage,
            $this->manager,
            $this->createTranslator(),
        );
    }

    private function lastAuditLog(): ?UserAuditLog
    {
        /** @var list<UserAuditLog> $logs */
        $logs = UserAuditLog::search()->all();

        // UserAuditLog::search() orders by created_at DESC, so the first row is the most recent.
        return $logs[0] ?? null;
    }
}
