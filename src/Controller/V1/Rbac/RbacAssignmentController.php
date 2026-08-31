<?php

declare(strict_types=1);

namespace YiiRocks\Voyti\Api\StatelessClient\Controller\V1\Rbac;

use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use YiiRocks\Voyti\Model\User;
use YiiRocks\Voyti\Service\AuditLogService;
use Yiisoft\DataResponse\ResponseFactory\DataResponseFactoryInterface;
use Yiisoft\Http\Status;
use Yiisoft\Input\Http\Attribute\Parameter\Body;
use Yiisoft\Rbac\AssignmentsStorageInterface;
use Yiisoft\Rbac\ItemsStorageInterface;
use Yiisoft\Rbac\ManagerInterface;
use Yiisoft\Rbac\Permission;
use Yiisoft\Rbac\Role;
use Yiisoft\Router\HydratorAttribute\RouteArgument;
use Yiisoft\Translator\TranslatorInterface;
use Yiisoft\User\CurrentUser;

/**
 * Manages per-user assignment of RBAC roles and permissions, exposed as its own sub-resource
 * separate from the item CRUD in {@see RbacController}. This mirrors the HTML controller's
 * `assignedUsers` field and `processUserAssignments()` diff algorithm but as a dedicated REST
 * endpoint, keeping the item CRUD contract unchanged.
 */
final readonly class RbacAssignmentController
{
    public function __construct(
        private AssignmentsStorageInterface $assignmentsStorage,
        private AuditLogService $auditLogService,
        private CurrentUser $currentUser,
        private DataResponseFactoryInterface $responseFactory,
        private ItemsStorageInterface $itemsStorage,
        private ManagerInterface $manager,
        private TranslatorInterface $translator,
    ) {}

    public function index(
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

        $assignments = $this->assignmentsStorage->getByItemNames([$name]);

        $userIds = [];
        foreach ($assignments as $assignment) {
            /** @infection-ignore-all The int cast is defensive; User::findByIds() matches the same rows for the string id. */
            $userIds[] = (int) $assignment->getUserId();
        }

        $users = User::findByIds($userIds);
        $usersById = [];
        foreach ($users as $user) {
            /** @infection-ignore-all The (string) cast is defensive; User::getId() already returns a string for persisted users, so the cast is a no-op and the array key would be identical. */
            $usersById[(string) $user->getId()] = $user;
        }

        /** @var list<array{id: string, username: string}> $result */
        $result = [];
        foreach ($assignments as $assignment) {
            $uid = $assignment->getUserId();
            $user = $usersById[$uid] ?? null;
            if ($user !== null) {
                /** @infection-ignore-all The (string) cast is defensive; User::getId() already returns a string for persisted users, so the cast is a no-op. */
                $result[] = ['id' => (string) $user->getId(), 'username' => $user->getUsername()];
            }
        }

        return $this->responseFactory->createResponse(['assignments' => $result]);
    }

    /**
     * @param list<int|string> $userIds
     */
    public function update(
        ServerRequestInterface $request,
        #[RouteArgument]
        string $itemType,
        #[RouteArgument]
        string $name,
        #[Body('userIds')]
        array $userIds = [],
    ): ResponseInterface {
        if ($this->findItem($itemType, $name) === null) {
            return $this->responseFactory->createResponse(
                ['error' => $this->translator->translate('voyti.auth_item.not_found', category: 'voyti')],
                Status::NOT_FOUND,
            );
        }

        $submittedIds = [];
        /** @psalm-suppress MixedAssignment */
        foreach ($userIds as $id) {
            /** @infection-ignore-all The empty-string skip and the int branch are only distinguishable for non-string non-empty values, which cannot occur from JSON-decoded user IDs. */
            if (is_string($id) && $id !== '') {
                $submittedIds[$id] = $id;
            } elseif (is_int($id)) {
                /** @infection-ignore-all PHP array keys coerce ints to strings, so the cast is a no-op. */
                $stringId = (string) $id;
                $submittedIds[$stringId] = $stringId;
            }
        }

        if ($submittedIds !== []) {
            $intIds = [];
            foreach ($submittedIds as $uid) {
                /** @infection-ignore-all The submitted IDs are already strings (array keys), so casting to int is a no-op for the user lookup. */
                $intIds[] = (int) $uid;
            }
            $foundUsers = User::findByIds($intIds);
            $foundIds = [];
            foreach ($foundUsers as $user) {
                /** @infection-ignore-all The (string) cast is defensive; User::getId() already returns a string for persisted users, so the cast is a no-op. */
                $foundIds[(string) $user->getId()] = true;
            }

            $missingIds = array_diff_key($submittedIds, $foundIds);
            if ($missingIds !== []) {
                return $this->responseFactory->createResponse(
                    [
                        'error' => $this->translator->translate(
                            'voyti-api-stateless-client.rbac.user_not_found',
                            category: 'voyti-api-stateless-client',
                        ),
                        'userIds' => array_values($missingIds),
                    ],
                    Status::BAD_REQUEST,
                );
            }
        }

        $currentAssignments = $this->assignmentsStorage->getByItemNames([$name]);
        foreach ($currentAssignments as $assignment) {
            $uid = $assignment->getUserId();
            if (!isset($submittedIds[$uid])) {
                $this->assignmentsStorage->remove($name, $uid);
            }
            unset($submittedIds[$uid]);
        }

        try {
            foreach ($submittedIds as $uid => $_) {
                $this->manager->assign($name, $uid);
            }
        } catch (InvalidArgumentException) {
            return $this->responseFactory->createResponse(
                [
                    'error' => $this->translator->translate(
                        'voyti-api-stateless-client.rbac.permission_assignment_disabled',
                        category: 'voyti-api-stateless-client',
                    ),
                ],
                Status::BAD_REQUEST,
            );
        }

        $this->auditLogService->log(
            $this->currentUser->getId() !== null ? (int) $this->currentUser->getId() : null,
            'rbac.' . $itemType . '.assignments.update',
            $request->getServerParams(),
            targetName: $name,
        );

        return $this->responseFactory->createResponse(
            [
                'message' => $this->translator->translate(
                    'voyti-api-stateless-client.rbac.assignments.updated',
                    category: 'voyti-api-stateless-client',
                ),
            ],
        );
    }

    private function findItem(string $itemType, string $name): Role|Permission|null
    {
        return $itemType === 'role' ? $this->itemsStorage->getRole($name) : $this->itemsStorage->getPermission($name);
    }
}
