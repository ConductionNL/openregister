<?php

/**
 * FlowLinkService — Tier-2 flow (workflowengine) integration service.
 *
 * Composes the {@see FlowLinkMapper} with NC WorkflowEngine's `Manager`
 * to expose the admin-only Tier-2 surface:
 *
 *   - linkOperation(uuid, registerId, schemaId, operationId)
 *       — link an existing operation (admin-only)
 *   - unlinkOperation(uuid, operationId)
 *       — remove a link (admin-only)
 *   - getLinkedOperations(uuid)
 *       — list linked operations with cached `enabled` refreshed
 *       (everyone — read-only for non-admins)
 *   - getAvailableOperations()
 *       — picker source listing every configured operation
 *       (admins see all rows, non-admins receive an empty array)
 *
 * Admin-gating rationale: NC Flow operations are configured ONLY by
 * administrators in the global Workflow Settings UI. Letting a regular
 * user pin an operation to an OR object would let them surface an
 * admin's automation in a sidebar tab they can't audit. We therefore
 * gate every mutating call on `IGroupManager::isAdmin()` and degrade
 * the picker source for non-admins.
 *
 * WorkflowEngine is reached via the server container with graceful
 * degradation: when the app is missing or the Manager throws we fall
 * back to a direct read of the `flow_operations` table so the link row
 * still hydrates the sidebar with whatever was cached at link time
 * (ADR-019 AD-23). This mirrors the talk/polls Tier-2 services.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use DateTime;
use Exception;
use OCA\OpenRegister\Db\FlowLink;
use OCA\OpenRegister\Db\FlowLinkMapper;
use OCP\App\IAppManager;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * FlowLinkService.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Composes mapper + DB
 *     + IAppManager + IUserSession + IGroupManager + container +
 *     logger. Each dependency is required for one of the Tier-2 flows
 *     (link, unlink, list, picker, admin gating, graceful degradation).
 */
class FlowLinkService
{
    private const REQUIRED_APP = 'workflowengine';

    /**
     * Constructor.
     *
     * @param FlowLinkMapper     $flowLinkMapper Persistence for link rows.
     * @param IDBConnection      $db             Database connection.
     * @param ContainerInterface $container      Container for late-bound classes.
     * @param IAppManager        $appManager     NC app manager.
     * @param IUserSession       $userSession    Active session.
     * @param IGroupManager      $groupManager   Group manager (for admin checks).
     * @param LoggerInterface    $logger         Logger.
     */
    public function __construct(
        private readonly FlowLinkMapper $flowLinkMapper,
        private readonly IDBConnection $db,
        private readonly ContainerInterface $container,
        private readonly IAppManager $appManager,
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Whether NC WorkflowEngine is installed.
     *
     * @return bool
     */
    public function isFlowAvailable(): bool
    {
        return $this->appManager->isInstalled(self::REQUIRED_APP);
    }//end isFlowAvailable()

    /**
     * Whether the current user is an administrator.
     *
     * NC Flow operations are admin-configured globally, so we gate the
     * mutating service methods on this flag.
     *
     * @return bool
     *
     * @spec openspec/specs/generic-integrations/spec.md
     */
    public function isCurrentUserAdmin(): bool
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return false;
        }

        return $this->groupManager->isAdmin($user->getUID());
    }//end isCurrentUserAdmin()

    /**
     * Link an existing Flow operation to an OR object.
     *
     * Admin-only. Idempotent: a duplicate link raises a 409 Exception.
     *
     * @param string $objectUuid  Parent OR object uuid.
     * @param int    $registerId  OR register id.
     * @param int    $schemaId    OR schema id.
     * @param int    $operationId workflowengine operation id.
     *
     * @return FlowLink The persisted link row.
     *
     * @throws Exception On missing user, non-admin (403), missing operation (404),
     *                   duplicate (409), Flow unavailable (503).
     *
     * @spec openspec/specs/generic-integrations/spec.md
     */
    public function linkOperation(string $objectUuid, int $registerId, int $schemaId, int $operationId): FlowLink
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            throw new Exception('No user logged in');
        }

        if ($this->isCurrentUserAdmin() === false) {
            throw new Exception('Only administrators can link Flow operations', 403);
        }

        if ($this->isFlowAvailable() === false) {
            throw new Exception('NC Flow is not available', 503);
        }

        $existing = $this->flowLinkMapper->findByObjectAndOperation($objectUuid, $operationId);
        if ($existing !== null) {
            throw new Exception('Operation already linked to this object', 409);
        }

        $opRow = $this->fetchOperationRow(operationId: $operationId);
        if ($opRow === null) {
            throw new Exception('Flow operation not found', 404);
        }

        $link = new FlowLink();
        $link->setObjectUuid($objectUuid);
        $link->setRegisterId($registerId);
        $link->setSchemaId($schemaId);
        $link->setOperationId($operationId);
        $link->setOperationClass((string) ($opRow['class'] ?? ''));
        $link->setOperationName((string) ($opRow['name'] ?? ''));
        $link->setEntityType((string) ($opRow['entity'] ?? ''));
        $link->setEnabled(true);
        $link->setLinkedBy($user->getUID());
        $link->setLinkedAt(new DateTime());

        return $this->flowLinkMapper->insert($link);
    }//end linkOperation()

    /**
     * Unlink an operation from an object.
     *
     * Admin-only. Does NOT delete the operation itself — it stays in
     * NC Flow for other linked objects.
     *
     * @param string $objectUuid  Parent OR object uuid.
     * @param int    $operationId workflowengine operation id.
     *
     * @return void
     *
     * @throws Exception On non-admin (403) or no matching link (404).
     *
     * @spec openspec/specs/generic-integrations/spec.md
     */
    public function unlinkOperation(string $objectUuid, int $operationId): void
    {
        if ($this->isCurrentUserAdmin() === false) {
            throw new Exception('Only administrators can unlink Flow operations', 403);
        }

        $deleted = $this->flowLinkMapper->deleteByObjectAndOperation($objectUuid, $operationId);
        if ($deleted === 0) {
            throw new Exception('Flow link not found', 404);
        }
    }//end unlinkOperation()

    /**
     * Return the linked Flow operations for an object, refreshing the
     * cached `enabled` / `operation_name` / `entity_type` columns from
     * `flow_operations` on every call.
     *
     * Available to everyone (read-only) — non-admins still see which
     * automations are bound to an OR object even though they can't
     * change them.
     *
     * @param string $objectUuid Parent OR object uuid.
     *
     * @return array<int,array<string,mixed>>
     *
     * @spec openspec/specs/generic-integrations/spec.md
     */
    public function getLinkedOperations(string $objectUuid): array
    {
        $links     = $this->flowLinkMapper->findByObjectUuid($objectUuid);
        $available = $this->isFlowAvailable();

        $results = [];
        foreach ($links as $link) {
            $row = $link->jsonSerialize();

            if ($available === true) {
                $opRow = $this->fetchOperationRow(operationId: (int) $link->getOperationId());
                if ($opRow === null) {
                    // Operation deleted in NC Flow — flag the link as
                    // stale by setting enabled=false.
                    $row['enabled'] = false;
                }

                if ($opRow !== null) {
                    $row['operationName']  = (string) ($opRow['name'] ?? $row['operationName']);
                    $row['operationClass'] = (string) ($opRow['class'] ?? $row['operationClass']);
                    $row['entityType']     = (string) ($opRow['entity'] ?? $row['entityType']);
                    // The flow_operations row doesn't carry an `enabled`
                    // flag (operations are always "live" once configured);
                    // we just surface that the row still exists.
                    $row['enabled']   = true;
                    $row['events']    = $this->decodeJsonField(value: ($opRow['events'] ?? null));
                    $row['checks']    = $this->decodeJsonField(value: ($opRow['checks'] ?? null));
                    $row['operation'] = (string) ($opRow['operation'] ?? '');
                }
            }//end if

            $row['url'] = '/index.php/settings/admin/workflow#'.($row['operationId'] ?? '');
            $results[]  = $row;
        }//end foreach

        return $results;
    }//end getLinkedOperations()

    /**
     * Return Flow operations visible to the current user (picker source).
     *
     * Admin-only: non-admins receive an empty array (the picker UX is
     * gated behind admin status). Optional substring search against the
     * operation name.
     *
     * @param string|null $search Optional name-substring filter.
     *
     * @return array<int,array<string,mixed>>
     *
     * @spec openspec/specs/generic-integrations/spec.md
     */
    public function getAvailableOperations(?string $search=null): array
    {
        if ($this->isCurrentUserAdmin() === false) {
            return [];
        }

        if ($this->isFlowAvailable() === false) {
            return [];
        }

        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('id', 'class', 'name', 'entity', 'operation', 'events', 'checks')
                ->from('flow_operations')
                ->orderBy('id', 'DESC');

            if ($search !== null && $search !== '') {
                $qb->andWhere(
                    $qb->expr()->iLike('name', $qb->createNamedParameter('%'.$search.'%'))
                );
            }

            $rows = $qb->executeQuery()->fetchAll();
        } catch (Throwable $e) {
            $this->logger->warning('FlowLinkService::getAvailableOperations failed: '.$e->getMessage());
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id'        => (int) ($row['id'] ?? 0),
                'name'      => (string) ($row['name'] ?? ''),
                'class'     => (string) ($row['class'] ?? ''),
                'entity'    => (string) ($row['entity'] ?? ''),
                'operation' => (string) ($row['operation'] ?? ''),
                'events'    => $this->decodeJsonField(value: ($row['events'] ?? null)),
                'checks'    => $this->decodeJsonField(value: ($row['checks'] ?? null)),
                'enabled'   => true,
            ];
        }

        return $out;
    }//end getAvailableOperations()

    /**
     * Fetch a single operation row from `flow_operations`.
     *
     * @param int $operationId Operation id.
     *
     * @return array<string,mixed>|null
     */
    private function fetchOperationRow(int $operationId): ?array
    {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('id', 'class', 'name', 'entity', 'operation', 'events', 'checks')
                ->from('flow_operations')
                ->where($qb->expr()->eq('id', $qb->createNamedParameter($operationId, IQueryBuilder::PARAM_INT)));

            $row = $qb->executeQuery()->fetch();
            if ($row === false) {
                return null;
            }

            return $row;
        } catch (Throwable $e) {
            $this->logger->debug('fetchOperationRow failed: '.$e->getMessage());
            return null;
        }
    }//end fetchOperationRow()

    /**
     * Decode a JSON-serialised array column (`events`, `checks`).
     *
     * NC Flow stores these as JSON strings. We tolerate both null and
     * already-decoded arrays so callers can pass raw row values.
     *
     * @param mixed $value Raw column value.
     *
     * @return array<int,mixed>
     */
    private function decodeJsonField(mixed $value): array
    {
        if (is_array($value) === true) {
            return $value;
        }

        if (is_string($value) === false || $value === '') {
            return [];
        }

        try {
            $decoded = json_decode($value, true);
            if (is_array($decoded) === true) {
                return $decoded;
            }
        } catch (Throwable $e) {
            // Fall through.
        }

        return [];
    }//end decodeJsonField()
}//end class
