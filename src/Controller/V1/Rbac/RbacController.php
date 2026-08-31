<?php

declare(strict_types=1);

namespace YiiRocks\Voyti\Api\StatelessClient\Controller\V1\Rbac;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use YiiRocks\Voyti\Service\AuditLogService;
use YiiRocks\Voyti\Validator\Rbac\ItemsValidator;
use Yiisoft\DataResponse\ResponseFactory\DataResponseFactoryInterface;
use Yiisoft\Http\Status;
use Yiisoft\Input\Http\Attribute\Parameter\Body;
use Yiisoft\Rbac\Item;
use Yiisoft\Rbac\ItemsStorageInterface;
use Yiisoft\Rbac\ManagerInterface;
use Yiisoft\Rbac\Permission;
use Yiisoft\Rbac\Role;
use Yiisoft\Router\HydratorAttribute\RouteArgument;
use Yiisoft\Translator\TranslatorInterface;
use Yiisoft\User\CurrentUser;

/**
 * Generic CRUD for RBAC roles and permissions for the SPA API: every action takes an `$itemType`
 * ('role'|'permission') and branches internally, mirroring core's HTML `Admin/Rbac/RbacController`.
 * Manages an item's name/description/rule and child items via {@see ManagerInterface}, the same
 * collaborator the HTML controller uses. Per-user assignment management (the HTML controller's
 * `assignedUsers` field) is intentionally out of scope for this first version - it is a separate
 * sub-resource, not a property of the item itself, and can be added as its own endpoint later
 * without changing this one's contract.
 */
final readonly class RbacController
{
    public function __construct(
        private AuditLogService $auditLogService,
        private CurrentUser $currentUser,
        private ItemsStorageInterface $itemsStorage,
        private ItemsValidator $itemsValidator,
        private ManagerInterface $manager,
        private DataResponseFactoryInterface $responseFactory,
        private TranslatorInterface $translator,
    ) {}

    /**
     * @param list<string> $children
     */
    public function create(
        ServerRequestInterface $request,
        #[RouteArgument]
        string $itemType,
        #[Body('name')]
        string $name = '',
        #[Body('description')]
        string $description = '',
        #[Body('rule')]
        string $rule = '',
        #[Body('children')]
        array $children = [],
    ): ResponseInterface {
        if (!$this->isValidName($name)) {
            return $this->responseFactory->createResponse(
                [
                    'error' => $this->translator->translate(
                        'voyti-api-stateless-client.rbac.invalid_or_missing_name',
                        category: 'voyti-api-stateless-client',
                    ),
                ],
                Status::BAD_REQUEST,
            );
        }

        if ($this->findItem($itemType, $name) !== null) {
            return $this->responseFactory->createResponse(
                [
                    'error' => $this->translator->translate(
                        'voyti-api-stateless-client.rbac.name_already_exists',
                        category: 'voyti-api-stateless-client',
                    ),
                ],
                Status::BAD_REQUEST,
            );
        }

        $childrenResult = $this->validateChildren($children);
        if ($childrenResult !== null) {
            return $childrenResult;
        }

        $item = $this->buildItem($itemType, $name, $description, $rule);
        $item instanceof Role ? $this->manager->addRole($item) : $this->manager->addPermission($item);

        foreach ($children as $childName) {
            $this->manager->addChild($name, $childName);
        }

        $this->auditLogService->log(
            $this->currentUser->getId() !== null ? (int) $this->currentUser->getId() : null,
            'rbac.' . $itemType . '.create',
            $request->getServerParams(),
            targetName: $name,
        );

        return $this->responseFactory->createResponse(
            [
                'name' => $name,
                'description' => $description,
                'message' => $this->translator->translate('voyti.auth_item.created', category: 'voyti'),
            ],
            Status::CREATED,
        );
    }

    public function delete(
        ServerRequestInterface $request,
        #[RouteArgument]
        string $itemType,
        #[RouteArgument]
        string $name,
    ): ResponseInterface {
        if ($this->findItem($itemType, $name) === null) {
            return $this->responseFactory->createResponse(
                ['error' => $this->translator->translate('voyti.auth_item.not_found', category: 'voyti')],
                Status::NOT_FOUND,
            );
        }

        /** @infection-ignore-all ManagerInterface::removeRole() and removePermission() both delegate to removeItem(), so which branch runs is unobservable. */
        $itemType === 'role' ? $this->manager->removeRole($name) : $this->manager->removePermission($name);

        $this->auditLogService->log(
            $this->currentUser->getId() !== null ? (int) $this->currentUser->getId() : null,
            'rbac.' . $itemType . '.delete',
            $request->getServerParams(),
            targetName: $name,
        );

        return $this->responseFactory->createResponse(
            ['message' => $this->translator->translate('voyti.auth_item.deleted', category: 'voyti')],
        );
    }

    public function index(#[RouteArgument] string $itemType): ResponseInterface
    {
        /** @var array<string, Item> $items */
        $items = $itemType === 'role' ? $this->itemsStorage->getRoles() : $this->itemsStorage->getPermissions();

        return $this->responseFactory->createResponse([
            'items' => array_map(
                fn(Item $item): array => [
                    'name' => $item->getName(),
                    'description' => $item->getDescription(),
                    'rule' => $item->getRuleName(),
                    'children' => array_keys($this->itemsStorage->getDirectChildren($item->getName())),
                ],
                /** @infection-ignore-all array_values only reindexes keys after the filter; consumers iterate by value. */
                array_values($items),
            ),
        ]);
    }

    /**
     * @param list<string> $children
     */
    public function update(
        ServerRequestInterface $request,
        #[RouteArgument]
        string $itemType,
        #[RouteArgument]
        string $name,
        #[Body('name')]
        string $newName = '',
        #[Body('description')]
        string $description = '',
        #[Body('rule')]
        string $rule = '',
        #[Body('children')]
        array $children = [],
    ): ResponseInterface {
        $existing = $this->findItem($itemType, $name);
        if ($existing === null) {
            return $this->responseFactory->createResponse(
                ['error' => $this->translator->translate('voyti.auth_item.not_found', category: 'voyti')],
                Status::NOT_FOUND,
            );
        }

        $resolvedName = $newName !== '' ? $newName : $name;
        if (!$this->isValidName($resolvedName)) {
            return $this->responseFactory->createResponse(
                [
                    'error' => $this->translator->translate(
                        'voyti-api-stateless-client.rbac.invalid_name',
                        category: 'voyti-api-stateless-client',
                    ),
                ],
                Status::BAD_REQUEST,
            );
        }

        if ($resolvedName !== $name && $this->findItem($itemType, $resolvedName) !== null) {
            return $this->responseFactory->createResponse(
                [
                    'error' => $this->translator->translate(
                        'voyti-api-stateless-client.rbac.name_already_exists',
                        category: 'voyti-api-stateless-client',
                    ),
                ],
                Status::BAD_REQUEST,
            );
        }

        $childrenResult = $this->validateChildren($children);
        if ($childrenResult !== null) {
            return $childrenResult;
        }

        $updated = $existing->withName($resolvedName)->withDescription($description);
        $updated = $rule !== '' ? $updated->withRuleName($rule) : $updated->withRuleName(null);
        $updated instanceof Role
            ? $this->manager->updateRole($name, $updated)
            : $this->manager->updatePermission($name, $updated);

        $this->manager->removeChildren($resolvedName);
        foreach ($children as $childName) {
            $this->manager->addChild($resolvedName, $childName);
        }

        $this->auditLogService->log(
            $this->currentUser->getId() !== null ? (int) $this->currentUser->getId() : null,
            'rbac.' . $itemType . '.update',
            $request->getServerParams(),
            targetName: $resolvedName,
            context: ['previousName' => $name],
        );

        return $this->responseFactory->createResponse(
            [
                'name' => $resolvedName,
                'description' => $description,
                'message' => $this->translator->translate('voyti.auth_item.updated', category: 'voyti'),
            ],
        );
    }

    private function buildItem(string $itemType, string $name, string $description, string $rule): Role|Permission
    {
        $item = $itemType === 'role' ? new Role($name) : new Permission($name);
        $item = $item->withDescription($description);

        return $rule !== '' ? $item->withRuleName($rule) : $item;
    }

    private function findItem(string $itemType, string $name): Role|Permission|null
    {
        return $itemType === 'role' ? $this->itemsStorage->getRole($name) : $this->itemsStorage->getPermission($name);
    }

    private function isValidName(string $name): bool
    {
        return $name !== '' && preg_match('/^\w[\w.:\-]+\w$/u', $name) === 1;
    }

    /**
     * @param list<string> $children
     */
    private function validateChildren(array $children): ?ResponseInterface
    {
        $result = $this->itemsValidator->validate($children);

        if (!$result->isValid()) {
            return $this->responseFactory->createResponse(
                [
                    'error' => $this->translator->translate(
                        'voyti-api-stateless-client.rbac.invalid_children',
                        category: 'voyti-api-stateless-client',
                    ),
                    'errors' => $result->getErrorMessages(),
                ],
                Status::BAD_REQUEST,
            );
        }

        return null;
    }
}
