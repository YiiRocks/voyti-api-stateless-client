<?php

declare(strict_types=1);

namespace YiiRocks\Voyti\Api\StatelessClient\Controller\V1\AuditLog;

use Psr\Http\Message\ResponseInterface;
use YiiRocks\Voyti\Model\UserAuditLog;
use Yiisoft\Data\Db\QueryDataReader;
use Yiisoft\Data\Paginator\OffsetPaginator;
use Yiisoft\DataResponse\ResponseFactory\DataResponseFactoryInterface;
use Yiisoft\Input\Http\Attribute\Parameter\Query;

/**
 * Admin, read-only listing of {@see UserAuditLog} entries for the SPA API, with the same
 * actor/target/action filters and pagination as core's HTML `Admin/AuditLog/AuditLogController`.
 * Audit log entries are only ever created internally by core's own event listeners, never via API,
 * so this controller has no write actions.
 */
final readonly class AuditLogController
{
    private const int PAGE_SIZE = 50;

    public function __construct(
        private DataResponseFactoryInterface $responseFactory,
    ) {}

    public function index(
        #[Query('actorUserId')]
        string $actorUserId = '',
        #[Query('targetUserId')]
        string $targetUserId = '',
        #[Query('action')]
        string $action = '',
        /**
         * @infection-ignore-all Mutating this default to 0 is behaviorally identical to 1: both are
         * floored to 1 by max(1, $page) below, so no test can observe the difference.
         */
        #[Query('page')]
        int $page = 1,
    ): ResponseInterface {
        $reader = new QueryDataReader(UserAuditLog::search([
            'actor_user_id' => $actorUserId,
            'target_user_id' => $targetUserId,
            'action' => $action,
        ]));

        $paginator = (new OffsetPaginator($reader))->withPageSize(self::PAGE_SIZE);
        $currentPage = min(max(1, $page), max(1, $paginator->getTotalPages()));
        $paginator = $paginator->withCurrentPage($currentPage);

        /** @infection-ignore-all — iterator keys are already 0-indexed, preserve_keys has no effect */
        /** @var list<UserAuditLog> $logs */
        $logs = iterator_to_array($paginator->read(), false);

        return $this->responseFactory->createResponse([
            'items' => array_map(
                static fn(UserAuditLog $log): array => [
                    'id' => $log->getId(),
                    'actorUserId' => $log->getActorUserId(),
                    'targetUserId' => $log->getTargetUserId(),
                    'targetName' => $log->getTargetName(),
                    'action' => $log->getAction(),
                    'context' => $log->getContext(),
                    'actorIp' => $log->getActorIp(),
                    'createdAt' => $log->getCreatedAt(),
                ],
                $logs,
            ),
            'totalCount' => $paginator->getTotalItems(),
            'currentPage' => $paginator->getCurrentPage(),
            'pageSize' => $paginator->getPageSize(),
            'totalPages' => $paginator->getTotalPages(),
        ]);
    }
}
