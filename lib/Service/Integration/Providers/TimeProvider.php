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
use OCA\OpenRegister\Db\TimeTrackerLinkMapper;
use OCA\OpenRegister\Service\Integration\AbstractIntegrationProvider;
use OCP\App\IAppManager;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IL10N;
use Throwable;

class TimeProvider extends AbstractIntegrationProvider {
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
	 * Constructor.
	 *
	 * @param IDBConnection $db NC DB connection (well-known idiom; used for legacy marker fallback queries).
	 * @param IAppManager $appManager NC app manager.
	 * @param IL10N $l10n Localisation.
	 * @param TimeTrackerLinkMapper $linkMapper Time-tracker-link mapper (Tier-2 link table).
	 * @param IConfig $config NC config (reads the time-tracker.backend admin override).
	 *
	 * @SuppressWarnings(PHPMD.ShortVariable) $db is a well-known PHP idiom for a database connection parameter.
	 */
	public function __construct(
		private IDBConnection $db,
		private IAppManager $appManager,
		private IL10N $l10n,
		private TimeTrackerLinkMapper $linkMapper,
		private IConfig $config,
	) {
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

	public function getStorageStrategy(): string {
		return 'link-table';
	}//end getStorageStrategy()

	public function isEnabled(): bool {
		return $this->appManager->isInstalled($this->backendAppId());
	}//end isEnabled()

	/**
	 * List linked TimeManager entries for an OR object.
	 *
	 * Reads the Tier-2 link table first; if no link rows exist it falls
	 * back to the legacy `[or:{uuid}]` marker scan in
	 * `timemanager_client.note` + `timemanager_task.note` so entries that
	 * pre-date the link table still surface (preserving the wave-2.4
	 * three-kind row shape).
	 *
	 * @param string $register Register slug for the parent object.
	 * @param string $schema Schema slug for the parent object.
	 * @param string $objectId UUID of the OR object whose rows we want.
	 * @param array $filters Optional registry filters (unused).
	 *
	 * @return array<int,array<string,mixed>> List of registry leaf rows.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $register, $schema and $filters are required by the
	 * IntegrationProvider interface contract; this implementation routes by $objectId only.
	 *
	 * @spec openspec/specs/integration-time-tracker/spec.md
	 */
	public function list(string $register, string $schema, string $objectId, array $filters = []): array {
		if ($this->isEnabled() === false) {
			return [];
		}

		// Tier-2 path: read from the link table.
		try {
			$linkRows = $this->linkMapper->findByObjectUuid($objectId);
		} catch (Throwable $e) {
			$linkRows = [];
		}

		if (count($linkRows) > 0) {
			return array_map(
				fn (TimeTrackerLink $link): array => $this->rowFromLink(link: $link),
				$linkRows
			);
		}

		// Backwards-compat fallback: scan the legacy `[or:{uuid}]` marker
		// in `timemanager_client.note` + `timemanager_task.note`,
		// preserving the three-kind row shape.
		return $this->legacyMarkerRows(objectId: $objectId);
	}//end list()

	/**
	 * Convert a TimeTrackerLink row into the registry leaf-row shape (the
	 * three-kind shape the bespoke tab consumes).
	 *
	 * @param TimeTrackerLink $link Link row from the mapper.
	 *
	 * @return array<string,mixed>
	 */
	private function rowFromLink(TimeTrackerLink $link): array {
		$entryType = (string)$link->getEntryType();
		$id = $this->entryIdOf(link: $link);

		return [
			'id' => $id,
			'kind' => $entryType,
			'type' => $entryType,
			'title' => (string)$link->getName(),
			'name' => (string)$link->getName(),
			'clientId' => $link->getClientId(),
			'taskId' => $link->getTaskId(),
			'timeId' => $link->getTimeId(),
			'duration' => $link->getDuration(),
			'billable' => $link->getBillable(),
			'startedAt' => $link->getStartedAt()?->format(\DateTime::ATOM),
			'url' => $this->entryDeepLink(entryType: $entryType, entryId: $id),
			'data' => $link->jsonSerialize(),
		];
	}//end rowFromLink()

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
	 * Legacy marker scan: surface clients + tasks whose `note` carries
	 * the `[or:{uuid}]` marker, in the three-kind row shape.
	 *
	 * @param string $objectId The OR object uuid.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function legacyMarkerRows(string $objectId): array {
		$marker = self::MARKER_PREFIX . $objectId . ']';

		$clients = $this->findByMarker(
			db: $this->db,
			table: 'timemanager_client',
			markerColumn: 'note',
			marker: $marker,
			extraColumns: ['uuid', 'name'],
			idColumn: 'id',
		);

		$tasks = $this->findByMarker(
			db: $this->db,
			table: 'timemanager_task',
			markerColumn: 'note',
			marker: $marker,
			extraColumns: ['uuid', 'name'],
			idColumn: 'id',
		);

		$rows = [];
		foreach ($clients as $row) {
			$uuid = (string)($row['uuid'] ?? $row['id'] ?? '');
			$rows[] = [
				'id' => $uuid,
				'kind' => 'client',
				'type' => 'client',
				'title' => (string)($row['name'] ?? ''),
				'name' => (string)($row['name'] ?? ''),
				'url' => $this->entryDeepLink(entryType: 'client', entryId: $uuid),
				'data' => $row,
			];
		}

		foreach ($tasks as $row) {
			$uuid = (string)($row['uuid'] ?? $row['id'] ?? '');
			$rows[] = [
				'id' => $uuid,
				'kind' => 'task',
				'type' => 'task',
				'title' => (string)($row['name'] ?? ''),
				'name' => (string)($row['name'] ?? ''),
				'url' => $this->entryDeepLink(entryType: 'task', entryId: $uuid),
				'data' => $row,
			];
		}

		return $rows;
	}//end legacyMarkerRows()

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
