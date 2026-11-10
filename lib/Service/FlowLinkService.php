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
use OCP\App\IAppManager;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
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
class FlowLinkService implements FlowLinkServiceInterface {
	private const REQUIRED_APP = 'workflowengine';

	/**
	 * Constructor.
	 *
	 * @param FlowLinkPlatform $platform     Link-row mapper, database connection and app manager.
	 * @param IUserSession     $userSession  Active session.
	 * @param IGroupManager    $groupManager Group manager (for admin checks).
	 * @param LoggerInterface  $logger       Logger.
	 */
	public function __construct(
		private readonly FlowLinkPlatform $platform,
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
	public function isFlowAvailable(): bool {
		return $this->platform->appManager->isInstalled(self::REQUIRED_APP);
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
	public function isCurrentUserAdmin(): bool {
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
	 * @param LinkTarget $target      The parent OR object: its uuid, register id and schema id.
	 * @param int        $operationId workflowengine operation id.
	 *
	 * @return FlowLink The persisted link row.
	 *
	 * @throws Exception On missing user, non-admin (403), missing operation (404),
	 *                   duplicate (409), Flow unavailable (503).
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function linkOperation(LinkTarget $target, int $operationId): FlowLink {
		$user  = $this->requireLinkingAdmin();
		$onRow = $this->requireUnlinkedOperation(objectUuid: $target->objectUuid, operationId: $operationId);

		$link = new FlowLink();
		$link->setObjectUuid($target->objectUuid);
		$link->setRegisterId($target->registerId);
		$link->setSchemaId($target->schemaId());
		$link->setOperationId($operationId);
		$link->setOperationClass((string)($onRow['class'] ?? ''));
		$link->setOperationName((string)($onRow['name'] ?? ''));
		$link->setEntityType((string)($onRow['entity'] ?? ''));
		$link->setEnabled(true);
		$link->setLinkedBy($user->getUID());
		$link->setLinkedAt(new DateTime());

		return $this->platform->flowLinkMapper->insert($link);
	}//end linkOperation()

	/**
	 * The logged-in administrator, when Flow is available to link against.
	 *
	 * @return IUser The administrator.
	 *
	 * @throws Exception On missing user, non-admin (403) or Flow unavailable (503).
	 */
	private function requireLinkingAdmin(): IUser {
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

		return $user;
	}//end requireLinkingAdmin()

	/**
	 * The Flow row of an operation not yet linked to the object.
	 *
	 * @param string $objectUuid  Parent OR object uuid.
	 * @param int    $operationId workflowengine operation id.
	 *
	 * @return array<string,mixed> The operation row.
	 *
	 * @throws Exception On duplicate (409) or missing operation (404).
	 */
	private function requireUnlinkedOperation(string $objectUuid, int $operationId): array {
		$existing = $this->platform->flowLinkMapper->findByObjectAndOperation($objectUuid, $operationId);
		if ($existing !== null) {
			throw new Exception('Operation already linked to this object', 409);
		}

		$onRow = $this->fetchOperationRow(operationId: $operationId);
		if ($onRow === null) {
			throw new Exception('Flow operation not found', 404);
		}

		return $onRow;
	}//end requireUnlinkedOperation()

	/**
	 * Unlink an operation from an object.
	 *
	 * Admin-only. Does NOT delete the operation itself — it stays in
	 * NC Flow for other linked objects.
	 *
	 * @param string $objectUuid Parent OR object uuid.
	 * @param int $operationId workflowengine operation id.
	 *
	 * @return void
	 *
	 * @throws Exception On non-admin (403) or no matching link (404).
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function unlinkOperation(string $objectUuid, int $operationId): void {
		if ($this->isCurrentUserAdmin() === false) {
			throw new Exception('Only administrators can unlink Flow operations', 403);
		}

		$deleted = $this->platform->flowLinkMapper->deleteByObjectAndOperation($objectUuid, $operationId);
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
	public function getLinkedOperations(string $objectUuid): array {
		$links = $this->platform->flowLinkMapper->findByObjectUuid($objectUuid);
		$available = $this->isFlowAvailable();

		$results = [];
		foreach ($links as $link) {
			$row = $link->jsonSerialize();

			if ($available === true) {
				$onRow = $this->fetchOperationRow(operationId: (int)$link->getOperationId());
				if ($onRow === null) {
					// Operation deleted in NC Flow — flag the link as
					// stale by setting enabled=false.
					$row['enabled'] = false;
				}

				if ($onRow !== null) {
					$row['operationName'] = (string)($onRow['name'] ?? $row['operationName']);
					$row['operationClass'] = (string)($onRow['class'] ?? $row['operationClass']);
					$row['entityType'] = (string)($onRow['entity'] ?? $row['entityType']);
					// The flow_operations row doesn't carry an `enabled`
					// flag (operations are always "live" once configured);
					// we just surface that the row still exists.
					$row['enabled'] = true;
					$row['events'] = $this->decodeJsonField(value: ($onRow['events'] ?? null));
					$row['checks'] = $this->decodeJsonField(value: ($onRow['checks'] ?? null));
					$row['operation'] = (string)($onRow['operation'] ?? '');
				}
			}//end if

			$row['url'] = '/index.php/settings/admin/workflow#' . ($row['operationId'] ?? '');
			$results[] = $row;
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
	public function getAvailableOperations(?string $search = null): array {
		if ($this->isCurrentUserAdmin() === false) {
			return [];
		}

		if ($this->isFlowAvailable() === false) {
			return [];
		}

		return $this->pickerRows(rows: $this->operationRows(search: ($search ?? '')));
	}//end getAvailableOperations()

	/**
	 * The picker entries of `flow_operations` rows.
	 *
	 * @param array<int,array<string,mixed>> $rows The operation rows.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function pickerRows(array $rows): array {
		$out = [];
		foreach ($rows as $row) {
			$out[] = $this->pickerRow(row: $row);
		}

		return $out;
	}//end pickerRows()

	/**
	 * The `flow_operations` rows, newest first, optionally filtered on name.
	 *
	 * @param string $search Name-substring filter; '' for none.
	 *
	 * @return array<int,array<string,mixed>> The rows; [] when the query failed.
	 */
	private function operationRows(string $search): array {
		try {
			$qb = $this->platform->db->getQueryBuilder();
			$qb->select('id', 'class', 'name', 'entity', 'operation', 'events', 'checks')
				->from('flow_operations')
				->orderBy('id', 'DESC');

			if ($search !== '') {
				$qb->andWhere(
					$qb->expr()->iLike('name', $qb->createNamedParameter('%' . $search . '%'))
				);
			}

			return $qb->executeQuery()->fetchAll();
		} catch (Throwable $e) {
			$this->logger->warning('FlowLinkService::getAvailableOperations failed: ' . $e->getMessage());
			return [];
		}
	}//end operationRows()

	/**
	 * One picker entry of a `flow_operations` row.
	 *
	 * @param array<string,mixed> $row The operation row.
	 *
	 * @return array<string,mixed>
	 */
	private function pickerRow(array $row): array {
		return [
			'id' => (int)($row['id'] ?? 0),
			'name' => (string)($row['name'] ?? ''),
			'class' => (string)($row['class'] ?? ''),
			'entity' => (string)($row['entity'] ?? ''),
			'operation' => (string)($row['operation'] ?? ''),
			'events' => $this->decodeJsonField(value: ($row['events'] ?? null)),
			'checks' => $this->decodeJsonField(value: ($row['checks'] ?? null)),
			'enabled' => true,
		];
	}//end pickerRow()

	/**
	 * Fetch a single operation row from `flow_operations`.
	 *
	 * @param int $operationId Operation id.
	 *
	 * @return array<string,mixed>|null
	 */
	private function fetchOperationRow(int $operationId): ?array {
		try {
			$qb = $this->platform->db->getQueryBuilder();
			$qb->select('id', 'class', 'name', 'entity', 'operation', 'events', 'checks')
				->from('flow_operations')
				->where($qb->expr()->eq('id', $qb->createNamedParameter($operationId, IQueryBuilder::PARAM_INT)));

			$row = $qb->executeQuery()->fetch();
			if ($row === false) {
				return null;
			}

			return $row;
		} catch (Throwable $e) {
			$this->logger->debug('fetchOperationRow failed: ' . $e->getMessage());
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
	private function decodeJsonField(mixed $value): array {
		if (is_array($value) === true) {
			return $value;
		}

		if (is_string($value) === false || $value === '') {
			return [];
		}

		return $this->decodeJsonText(text: $value);
	}//end decodeJsonField()

	/**
	 * Decode JSON text to an array; [] when it is not a JSON array or object.
	 *
	 * @param string $text The JSON text.
	 *
	 * @return array<int|string,mixed>
	 */
	private function decodeJsonText(string $text): array {
		$decoded = json_decode($text, true);
		if (is_array($decoded) === true) {
			return $decoded;
		}

		return [];
	}//end decodeJsonText()
}//end class
