<?php

/**
 * FlowProvider — exposes NC Flow (workflowengine) operations linked
 * to an OR object via the IntegrationProvider contract.
 *
 * Tier-2: backed by the `openregister_flow_links` table via
 * {@see FlowLinkMapper}. Replaces the Tier-1 `[or:{uuid}]` name-marker
 * convention (which polluted the operation display name and broke on
 * renames) with a proper persistence layer.
 *
 * Reads each linked operation's current name/class/entity/events/checks
 * directly from `oc_flow_operations` so the sidebar tab always shows
 * the latest values, falling back to the cached link-row columns when
 * the operation has been deleted from NC Flow.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration\Providers
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/integration-flow/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration\Providers;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- self-documenting IntegrationProvider metadata getters mirror the contract in the interface.

use OCA\OpenRegister\Db\FlowLinkMapper;
use OCA\OpenRegister\Service\Integration\AbstractIntegrationProvider;
use OCP\App\IAppManager;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IL10N;
use Throwable;

class FlowProvider extends AbstractIntegrationProvider {

	private const REQUIRED_APP = 'workflowengine';

	public function __construct(
		private FlowLinkMapper $flowLinkMapper,
		private IDBConnection $db,
		private IAppManager $appManager,
		private IL10N $l10n,
	) {
	}//end __construct()

	public function getId(): string {
		return 'flow';
	}//end getId()

	public function getLabel(): string {
		return $this->l10n->t('Automation');
	}//end getLabel()

	public function getIcon(): string {
		return 'RobotOutline';
	}//end getIcon()

	public function getGroup(): ?string {
		return 'workflow';
	}//end getGroup()

	public function getRequiredApp(): ?string {
		return self::REQUIRED_APP;
	}//end getRequiredApp()

	public function getStorageStrategy(): string {
		return 'link-table';
	}//end getStorageStrategy()

	public function isEnabled(): bool {
		return $this->appManager->isInstalled(self::REQUIRED_APP);
	}//end isEnabled()

	/**
	 * List Flow operations linked to an OR object.
	 *
	 * Reads link rows from `openregister_flow_links`, then hydrates each
	 * row with the current name/class/entity/events/checks from
	 * `oc_flow_operations`. Falls back to the cached link-row columns
	 * when the operation has been deleted from NC Flow (the row still
	 * surfaces in the sidebar but is flagged with `enabled=false`).
	 *
	 * @param string $register Register slug or numeric id (unused).
	 * @param string $schema Schema slug or numeric id (unused).
	 * @param string $objectId Object uuid.
	 * @param array<string,mixed> $filters Optional filters (unused).
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @spec openspec/specs/integration-flow/spec.md
	 */
	public function list(string $register, string $schema, string $objectId, array $filters = []): array {
		if ($this->isEnabled() === false) {
			return [];
		}

		$links = $this->flowLinkMapper->findByObjectUuid($objectId);
		if ($links === []) {
			return [];
		}

		$out = [];
		foreach ($links as $link) {
			$operationId = (int)$link->getOperationId();
			$onRow = $this->fetchOperationRow(operationId: $operationId);

			$name = (string)($onRow['name'] ?? $link->getOperationName() ?? '');
			$class = (string)($onRow['class'] ?? $link->getOperationClass() ?? '');
			$entity = (string)($onRow['entity'] ?? $link->getEntityType() ?? '');
			$operation = (string)($onRow['operation'] ?? '');
			$events = $this->decodeJsonField(value: $onRow['events'] ?? null);
			$checks = $this->decodeJsonField(value: $onRow['checks'] ?? null);

			$out[] = [
				'id' => (string)$operationId,
				'title' => $name,
				'class' => $class,
				'entity' => $entity,
				'operation' => $operation,
				'events' => $events,
				'checks' => $checks,
				'enabled' => $onRow !== null,
				'url' => '/index.php/settings/admin/workflow#' . $operationId,
				'linkId' => $link->getId(),
				'data' => $onRow ?? [],
			];
		}//end foreach

		return $out;
	}//end list()

	/**
	 * Fetch an operation row from `oc_flow_operations`.
	 *
	 * @param int $operationId Operation id.
	 *
	 * @return array<string,mixed>|null
	 */
	private function fetchOperationRow(int $operationId): ?array {
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
			return null;
		}
	}//end fetchOperationRow()

	/**
	 * Decode a JSON-serialised array column (`events`, `checks`).
	 *
	 * NC Flow stores these as JSON strings. Tolerates already-decoded
	 * arrays so callers can pass raw row values from either source.
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

	/**
	 * Provider health descriptor (enabled/disabled echo).
	 *
	 * @return array<string,mixed>
	 *
	 * @spec exclude Static enabled/disabled descriptor echoing isEnabled() — no standalone health behaviour;
	 *              the health/OCS contract is owned by pluggable-integration-registry task-2.
	 */
	public function health(): array {
		$available = $this->isEnabled();
		$status = 'unavailable';
		if ($available === true) {
			$status = 'ok';
		}

		$message = 'NC Flow (workflowengine) app is not installed';
		if ($available === true) {
			$message = null;
		}

		return [
			'status' => $status,
			'authStatus' => 'configured',
			'message' => $message,
		];
	}//end health()
}//end class
