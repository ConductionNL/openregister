<?php

/**
 * TimeProvider — exposes NC TimeManager entries (clients, tasks, time
 * entries) linked to an OpenRegister object via the Tier-2
 * `openregister_timetracker_links` table.
 *
 * Pre-Tier-2 the provider matched a `[or:{objectUuid}]` marker embedded
 * in the client's / task's `note` field (wave-2.4 three-kind design).
 * Tier-2 (this file) reads the dedicated link table instead — the marker
 * convention is retained as a backwards-compat fallback for entries that
 * pre-date the link table.
 *
 * The leaf slug is `time-tracker` (with a hyphen); the underlying NC app
 * id is `timemanager` (no hyphen).
 *
 * Each row carries a three-kind discriminator (`client` | `task` |
 * `time`) so the bespoke CnTimeTrackerTab can render kind chips,
 * durations and billable indicators.
 *
 * Storage strategy is `link-table` — the link rows live in OR; the
 * upstream `timemanager_*` tables are only read for the legacy marker
 * fallback.
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
 * @spec openspec/specs/integration-time-tracker/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration\Providers;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing

use OCA\OpenRegister\Db\TimeTrackerLink;
use OCA\OpenRegister\Db\TimeTrackerLinkMapperInterface;
use OCA\OpenRegister\Service\Integration\AbstractIntegrationProvider;
use OCP\App\IAppManager;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

class TimeProvider extends AbstractIntegrationProvider {
	use NcAppLinkTableProvider;
	use MarkerLookupTrait;

	/**
	 * Default backing NC time-tracking app id.
	 *
	 * The admin can override this via the `time-tracker.backend` app
	 * config key (per integration-time-tracker AD-1) to point the
	 * provider at a different time-tracking app that exposes a
	 * compatible adapter.
	 *
	 * @var string
	 */
	private const DEFAULT_BACKEND = 'timemanager';

	/**
	 * Config key (under app `openregister`) carrying the admin's
	 * chosen backing time-tracking app id. Default is `timemanager`.
	 *
	 * @var string
	 */
	public const BACKEND_CONFIG_KEY = 'time-tracker.backend';

	private const MARKER_PREFIX = '[or:';

	/**
	 * NC DB connection (well-known idiom; used for legacy marker fallback queries).
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
	 * @param LeafProviderPlatform           $platform   The DB connection, app manager and localisation.
	 * @param TimeTrackerLinkMapperInterface $linkMapper Time-tracker-link mapper (Tier-2 link table).
	 * @param IConfig                        $config     NC config (reads the time-tracker.backend admin override).
	 * @param LoggerInterface                $logger     Logger for the degraded marker-scan path.
	 *
	 * @SuppressWarnings(PHPMD.ShortVariable) $db is a well-known PHP idiom for a database connection parameter.
	 */
	public function __construct(
		LeafProviderPlatform $platform,
		private TimeTrackerLinkMapperInterface $linkMapper,
		private IConfig $config,
		private LoggerInterface $logger,
	) {
		$this->db = $platform->db;
		$this->appManager = $platform->appManager;
		$this->l10n = $platform->l10n;
	}//end __construct()

	/**
	 * Resolve the configured backing time-tracking app id.
	 *
	 * Reads the admin override from app-config (`time-tracker.backend`,
	 * default `timemanager`) every call — admin changes propagate
	 * without restarting the service.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/integration-time-tracker/spec.md
	 */
	private function backendAppId(): string {
		$value = $this->config->getAppValue('openregister', self::BACKEND_CONFIG_KEY, self::DEFAULT_BACKEND);
		if (is_string($value) === false || $value === '') {
			return self::DEFAULT_BACKEND;
		}

		return $value;
	}//end backendAppId()

	public function getId(): string {
		return 'time-tracker';
	}//end getId()

	public function getLabel(): string {
		return $this->l10n->t('Time tracker');
	}//end getLabel()

	public function getIcon(): string {
		return 'Clock';
	}//end getIcon()

	public function getGroup(): ?string {
		return 'workflow';
	}//end getGroup()

	public function getRequiredApp(): ?string {
		return $this->backendAppId();
	}//end getRequiredApp()

	public function isEnabled(): bool {
		return $this->appManager->isInstalled($this->backendAppId());
	}//end isEnabled()

	/**
	 * The link mapper this provider reads first.
	 *
	 * @return TimeTrackerLinkMapperInterface
	 */
	protected function linkMapper(): TimeTrackerLinkMapperInterface {
		return $this->linkMapper;
	}//end linkMapper()

	/**
	 * Convert a TimeTrackerLink row into the registry leaf-row shape (the
	 * three-kind shape the bespoke tab consumes).
	 *
	 * @param TimeTrackerLink $link Link row from the mapper.
	 *
	 * @return array<string,mixed>
	 */
	protected function rowFromLink(TimeTrackerLink $link): array {
		$entryType = (string)$link->getEntryType();
		$id = $this->entryIdOf(link: $link);

		return [
			'id' => $id,
			'kind' => $entryType,
			'type' => $entryType,
			'title' => (string)$link->getName(),
			'name' => (string)$link->getName(),
		] + $this->linkFields(link: $link) + [
			'url' => $this->entryDeepLink(entryType: $entryType, entryId: $id),
			'data' => $link->jsonSerialize(),
		];
	}//end rowFromLink()

	/**
	 * The link's cached client, task, time, duration, billable and start fields.
	 *
	 * @param TimeTrackerLink $link Link row.
	 *
	 * @return array<string,mixed>
	 */
	private function linkFields(TimeTrackerLink $link): array {
		return [
			'clientId' => $link->getClientId(),
			'taskId' => $link->getTaskId(),
			'timeId' => $link->getTimeId(),
			'duration' => $link->getDuration(),
			'billable' => $link->getBillable(),
			'startedAt' => $link->getStartedAt()?->format(\DateTime::ATOM),
		];
	}//end linkFields()

	/**
	 * The upstream entry id carried by the link, per entry type.
	 *
	 * @param TimeTrackerLink $link Link row.
	 *
	 * @return string
	 */
	private function entryIdOf(TimeTrackerLink $link): string {
		switch ((string)$link->getEntryType()) {
			case 'task':
				return (string)$link->getTaskId();
			case 'time':
				return (string)$link->getTimeId();
			default:
				return (string)$link->getClientId();
		}
	}//end entryIdOf()

	/**
	 * Build the NC TimeManager deep link for an entry.
	 *
	 * @param string $entryType Entry kind.
	 * @param string $entryId Upstream entry uuid.
	 *
	 * @return string
	 */
	private function entryDeepLink(string $entryType, string $entryId): string {
		switch ($entryType) {
			case 'task':
				return '/index.php/apps/timemanager/tasks/' . $entryId;
			case 'time':
				return '/index.php/apps/timemanager/times/' . $entryId;
			default:
				return '/index.php/apps/timemanager/clients/' . $entryId;
		}
	}//end entryDeepLink()

	/**
	 * Legacy marker scan: the clients, then the tasks, whose `note` carries the
	 * `[or:{uuid}]` marker, in the three-kind row shape.
	 *
	 * @param string $objectId The OR object uuid.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	protected function unlinkedRows(string $objectId): array {
		$marker = self::MARKER_PREFIX . $objectId . ']';
		$clients = $this->scanNotes(table: 'timemanager_client', marker: $marker);
		$tasks = $this->scanNotes(table: 'timemanager_task', marker: $marker);

		return array_merge(
			array_map(fn (array $row): array => $this->markerRow(row: $row, kind: 'client'), $clients),
			array_map(fn (array $row): array => $this->markerRow(row: $row, kind: 'task'), $tasks)
		);
	}//end unlinkedRows()

	/**
	 * The rows of a TimeManager table whose `note` carries the marker.
	 *
	 * @param string $table  Table without the `oc_` prefix.
	 * @param string $marker The `[or:{uuid}]` marker.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function scanNotes(string $table, string $marker): array {
		return $this->findByMarker(
			db: $this->db,
			table: $table,
			markerColumn: 'note',
			marker: $marker,
			extraColumns: ['uuid', 'name'],
			idColumn: 'id',
		);
	}//end scanNotes()

	/**
	 * One marker-scan row of a kind in the three-kind row shape.
	 *
	 * @param array<string,mixed> $row  The scanned row.
	 * @param string              $kind client or task.
	 *
	 * @return array<string,mixed>
	 */
	private function markerRow(array $row, string $kind): array {
		$uuid = (string)($row['uuid'] ?? $row['id'] ?? '');

		return [
			'id' => $uuid,
			'kind' => $kind,
			'type' => $kind,
			'title' => (string)($row['name'] ?? ''),
			'name' => (string)($row['name'] ?? ''),
			'url' => $this->entryDeepLink(entryType: $kind, entryId: $uuid),
			'data' => $row,
		];
	}//end markerRow()

	/**
	 * Provider health descriptor (enabled/disabled echo).
	 *
	 * @return array<string,mixed>
	 *
	 * @spec exclude Static enabled/disabled descriptor echoing isEnabled() — no standalone health behaviour; the
	 *              health/OCS contract is owned by pluggable-integration-registry task-2.
	 */
	public function health(): array {
		$available = $this->isEnabled();
		$backend = $this->backendAppId();
		$status = 'unavailable';
		$message = sprintf('Backing time-tracking app (%s) is not installed', $backend);
		if ($available === true) {
			$status = 'ok';
			$message = null;
		}

		return [
			'status' => $status,
			'authStatus' => 'configured',
			'message' => $message,
		];
	}//end health()
}//end class
