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

use OCA\OpenRegister\Db\FlowLink;
use OCA\OpenRegister\Db\FlowLinkMapperInterface;
use OCA\OpenRegister\Service\Integration\AbstractIntegrationProvider;
use OCP\App\IAppManager;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IL10N;

class FlowProvider extends AbstractIntegrationProvider {

	use NcAppLinkRowsProvider;
	use FirstRowQuery;

	private const REQUIRED_APP = 'workflowengine';

	/**
	 * NC DB connection.
	 *
	 * @var IDBConnection
	 */
	private IDBConnection $db;

	/**
	 * NC app manager.
	 *
	 * @var IAppManager
	 */
	private IAppManager $appManager;

	/**
	 * Localisation.
	 *
	 * @var IL10N
	 */
	private IL10N $l10n;

	/**
	 * Constructor.
	 *
	 * @param LeafProviderPlatform    $platform       The DB connection, app manager and localisation.
	 * @param FlowLinkMapperInterface $flowLinkMapper Flow-link mapper (Tier-2 link table).
	 */
	public function __construct(
		LeafProviderPlatform $platform,
		private FlowLinkMapperInterface $flowLinkMapper,
	) {
		$this->db = $platform->db;
		$this->appManager = $platform->appManager;
		$this->l10n = $platform->l10n;
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

	/**
	 * The link mapper this provider reads.
	 *
	 * @return FlowLinkMapperInterface
	 */
	protected function linkMapper(): FlowLinkMapperInterface {
		return $this->flowLinkMapper;
	}//end linkMapper()

	/**
	 * One Flow link, hydrated with the current name/class/entity/events/checks
	 * from `oc_flow_operations`. When the operation has been deleted from NC Flow
	 * the cached link-row columns are used and the row carries `enabled=false`.
	 *
	 * @param FlowLink $link Link row from the mapper.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/specs/integration-flow/spec.md
	 */
	protected function rowFromLink(FlowLink $link): array {
		$operationId = (int)$link->getOperationId();
		$onRow = $this->fetchOperationRow(operationId: $operationId);

		return array_merge(
			$this->operationFields(link: $link, onRow: $onRow),
			[
				'enabled' => $onRow !== null,
				'url' => '/index.php/settings/admin/workflow#' . $operationId,
				'linkId' => $link->getId(),
				'data' => $onRow ?? [],
			]
		);
	}//end rowFromLink()

	/**
	 * The operation's id, title, class, entity, operation, events and checks:
	 * the live row first, the cached link columns second.
	 *
	 * @param FlowLink                 $link  Link row.
	 * @param array<string,mixed>|null $onRow Live `oc_flow_operations` row, if any.
	 *
	 * @return array<string,mixed>
	 */
	private function operationFields(FlowLink $link, ?array $onRow): array {
		return [
			'id' => (string)(int)$link->getOperationId(),
			'title' => (string)($onRow['name'] ?? $link->getOperationName() ?? ''),
			'class' => (string)($onRow['class'] ?? $link->getOperationClass() ?? ''),
			'entity' => (string)($onRow['entity'] ?? $link->getEntityType() ?? ''),
			'operation' => (string)($onRow['operation'] ?? ''),
			'events' => $this->decodeJsonField(value: $onRow['events'] ?? null),
			'checks' => $this->decodeJsonField(value: $onRow['checks'] ?? null),
		];
	}//end operationFields()

	/**
	 * Fetch an operation row from `oc_flow_operations`.
	 *
	 * @param int $operationId Operation id.
	 *
	 * @return array<string,mixed>|null
	 */
	private function fetchOperationRow(int $operationId): ?array {
		return $this->firstRowOf(
			query: function () use ($operationId): IQueryBuilder {
				$qb = $this->db->getQueryBuilder();
				return $qb->select('id', 'class', 'name', 'entity', 'operation', 'events', 'checks')
					->from('flow_operations')
					->where($qb->expr()->eq('id', $qb->createNamedParameter($operationId, IQueryBuilder::PARAM_INT)));
			}
		);
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

		$decoded = json_decode($value, true);
		if (is_array($decoded) === true) {
			return $decoded;
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
		return $this->installedHealth(available: $this->isEnabled(), missing: 'NC Flow (workflowengine) app is not installed');
	}//end health()
}//end class
