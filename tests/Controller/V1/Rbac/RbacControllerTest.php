<?php

declare(strict_types=1);

namespace YiiRocks\Voyti\Api\StatelessClient\tests\Controller\V1\Rbac;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use YiiRocks\Voyti\Api\StatelessClient\Controller\V1\Rbac\RbacController;
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
use YiiRocks\Voyti\Validator\Rbac\ItemsValidator;
use Yiisoft\DataResponse\ResponseFactory\DataResponseFactoryInterface;
use Yiisoft\Http\Status;
use Yiisoft\Rbac\ItemsStorageInterface;
use Yiisoft\Rbac\Manager;
use Yiisoft\Rbac\ManagerInterface;
use Yiisoft\Rbac\Permission;
use Yiisoft\Rbac\Role;

#[AllowMockObjectsWithoutExpectations]
final class RbacControllerTest extends DatabaseTestCase
{
    use CurrentUserTrait;
    use ExpectsResponseTrait;
    use UserFactoryTrait;

    private User $actor;
    private ItemsStorageInterface $itemsStorage;
    private ManagerInterface $manager;
    private DataResponseFactoryInterface&MockObject $responseFactory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->itemsStorage = new SimpleItemsStorage();
        $this->manager = new Manager($this->itemsStorage, new SimpleAssignmentsStorage());
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
    public function testCreate(string $itemType): void
    {
        // Invalid names: empty, trailing punctuation, leading punctuation
        $response = $this->expectResponse(['error' => 'Invalid or missing name.'], Status::BAD_REQUEST);
        self::assertSame($response, $this->createController()->create(new ServerRequest('POST', '/'), $itemType, name: ''));
        $response = $this->expectResponse(['error' => 'Invalid or missing name.'], Status::BAD_REQUEST);
        self::assertSame($response, $this->createController()->create(new ServerRequest('POST', '/'), $itemType, name: 'abcde!'));
        $response = $this->expectResponse(['error' => 'Invalid or missing name.'], Status::BAD_REQUEST);
        self::assertSame($response, $this->createController()->create(new ServerRequest('POST', '/'), $itemType, name: '!abcde'));

        // Success, with a child, description, rule, and a multibyte (unicode) name
        $this->addItem($itemType, 'child-item');
        $response = $this->expectResponse(
            ['name' => 'usér', 'description' => 'An editor', 'message' => 'Authorization item has been created'],
            Status::CREATED,
        );
        self::assertSame(
            $response,
            $this->createController()->create(
                new ServerRequest('POST', '/'),
                $itemType,
                name: 'usér',
                description: 'An editor',
                rule: 'some-rule',
                children: ['child-item'],
            ),
        );
        self::assertContains('child-item', array_keys($this->itemsStorage->getDirectChildren('usér')));
        self::assertSame('some-rule', $this->findItemRuleName($itemType, 'usér'));
        $log = $this->lastAuditLog();
        self::assertSame((int) $this->actor->getId(), $log?->getActorUserId());
        self::assertSame('rbac.' . $itemType . '.create', $log?->getAction());
        self::assertSame('usér', $log?->getTargetName());

        // Duplicate name
        $response = $this->expectResponse(['error' => 'An item with this name already exists.'], Status::BAD_REQUEST);
        self::assertSame($response, $this->createController()->create(new ServerRequest('POST', '/'), $itemType, name: 'usér'));

        // Non-existent child
        $response = $this->expectResponse(
            ['error' => 'Invalid children.', 'errors' => ["Authorization item 'missing' does not exist."]],
            Status::BAD_REQUEST,
        );
        self::assertSame(
            $response,
            $this->createController()->create(new ServerRequest('POST', '/'), $itemType, name: 'another', children: ['missing']),
        );
    }

    #[DataProvider('itemTypeProvider')]
    public function testDelete(string $itemType): void
    {
        // Not found
        $response = $this->expectResponse(['error' => 'Authorization item not found'], Status::NOT_FOUND);
        self::assertSame($response, $this->createController()->delete(new ServerRequest('DELETE', '/'), $itemType, 'missing'));

        // Success
        $this->addItem($itemType, 'deletable');
        $response = $this->expectResponse(['message' => 'Authorization item has been removed'], Status::OK);
        self::assertSame($response, $this->createController()->delete(new ServerRequest('DELETE', '/'), $itemType, 'deletable'));
        self::assertFalse($this->itemsStorage->exists('deletable'));
        $log = $this->lastAuditLog();
        self::assertSame((int) $this->actor->getId(), $log?->getActorUserId());
        self::assertSame('rbac.' . $itemType . '.delete', $log?->getAction());
        self::assertSame('deletable', $log?->getTargetName());
    }

    #[DataProvider('itemTypeProvider')]
    public function testIndex(string $itemType): void
    {
        $this->addItem($itemType, 'parent-item', 'Parent');
        $this->addItem($itemType, 'child-item', 'Child');
        $this->manager->addChild('parent-item', 'child-item');

        $response = $this->expectResponse([
            'items' => [
                ['name' => 'parent-item', 'description' => 'Parent', 'rule' => null, 'children' => ['child-item']],
                ['name' => 'child-item', 'description' => 'Child', 'rule' => null, 'children' => []],
            ],
        ], Status::OK);

        self::assertSame($response, $this->createController()->index($itemType));
    }

    #[DataProvider('itemTypeProvider')]
    public function testUpdate(string $itemType): void
    {
        $this->addItem($itemType, 'original', 'Old description');
        $this->addItem($itemType, 'available-child');
        $this->addItem($itemType, 'stale-child');
        $this->manager->addChild('original', 'stale-child');

        // Not found
        $response = $this->expectResponse(['error' => 'Authorization item not found'], Status::NOT_FOUND);
        self::assertSame($response, $this->createController()->update(new ServerRequest('PATCH', '/'), $itemType, 'missing'));

        // Success: rename, change description, attach a child
        $response = $this->expectResponse(
            ['name' => 'renamed', 'description' => 'New description', 'message' => 'Authorization item has been updated'],
            Status::OK,
        );
        self::assertSame(
            $response,
            $this->createController()->update(
                new ServerRequest('PATCH', '/'),
                $itemType,
                'original',
                newName: 'renamed',
                description: 'New description',
                children: ['available-child'],
            ),
        );
        self::assertFalse($this->itemsStorage->exists('original'));
        $renamedChildren = array_keys($this->itemsStorage->getDirectChildren('renamed'));
        self::assertContains('available-child', $renamedChildren);
        self::assertNotContains('stale-child', $renamedChildren);
        $log = $this->lastAuditLog();
        self::assertSame((int) $this->actor->getId(), $log?->getActorUserId());
        self::assertSame('rbac.' . $itemType . '.update', $log?->getAction());
        self::assertSame('renamed', $log?->getTargetName());
        self::assertSame(['previousName' => 'original'], json_decode((string) $log?->getContext(), true));

        // Setting a rule
        $response = $this->expectResponse(
            ['name' => 'renamed', 'description' => 'New description', 'message' => 'Authorization item has been updated'],
            Status::OK,
        );
        self::assertSame(
            $response,
            $this->createController()->update(new ServerRequest('PATCH', '/'), $itemType, 'renamed', description: 'New description', rule: 'some-rule'),
        );
        self::assertSame('some-rule', $this->findItemRuleName($itemType, 'renamed'));

        // Clearing the rule
        $response = $this->expectResponse(
            ['name' => 'renamed', 'description' => 'New description', 'message' => 'Authorization item has been updated'],
            Status::OK,
        );
        self::assertSame(
            $response,
            $this->createController()->update(new ServerRequest('PATCH', '/'), $itemType, 'renamed', description: 'New description'),
        );
        self::assertNull($this->findItemRuleName($itemType, 'renamed'));

        // Renaming to an existing name conflicts
        $this->addItem($itemType, 'taken');
        $response = $this->expectResponse(['error' => 'An item with this name already exists.'], Status::BAD_REQUEST);
        self::assertSame(
            $response,
            $this->createController()->update(new ServerRequest('PATCH', '/'), $itemType, 'renamed', newName: 'taken'),
        );

        // Invalid new name
        $response = $this->expectResponse(['error' => 'Invalid name.'], Status::BAD_REQUEST);
        self::assertSame(
            $response,
            $this->createController()->update(new ServerRequest('PATCH', '/'), $itemType, 'renamed', newName: '!'),
        );

        // Non-existent child on update
        $response = $this->expectResponse(
            ['error' => 'Invalid children.', 'errors' => ["Authorization item 'missing' does not exist."]],
            Status::BAD_REQUEST,
        );
        self::assertSame(
            $response,
            $this->createController()->update(new ServerRequest('PATCH', '/'), $itemType, 'renamed', children: ['missing']),
        );
    }

    private function addItem(string $itemType, string $name, string $description = ''): void
    {
        $item = $itemType === 'role' ? new Role($name) : new Permission($name);
        $item = $item->withDescription($description);
        $itemType === 'role' ? $this->manager->addRole($item) : $this->manager->addPermission($item);
    }

    private function createController(): RbacController
    {
        return new RbacController(
            new AuditLogService(VoytiConfigFactory::create()),
            $this->createCurrentUser($this->actor),
            $this->itemsStorage,
            new ItemsValidator($this->itemsStorage),
            $this->manager,
            $this->responseFactory,
            $this->createTranslator(),
        );
    }

    private function findItemRuleName(string $itemType, string $name): ?string
    {
        $item = $itemType === 'role' ? $this->itemsStorage->getRole($name) : $this->itemsStorage->getPermission($name);

        return $item?->getRuleName();
    }

    private function lastAuditLog(): ?UserAuditLog
    {
        /** @var list<UserAuditLog> $logs */
        $logs = UserAuditLog::search()->all();

        // UserAuditLog::search() orders by created_at DESC, so the first row is the most recent.
        return $logs[0] ?? null;
    }
}
