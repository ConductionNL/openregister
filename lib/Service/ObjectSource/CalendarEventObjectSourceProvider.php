<?php

/**
 * CalendarEventObjectSourceProvider — serves the `nc-event` virtual schema's
 * objects live from the acting user's CalDAV VEVENTs (read-only).
 *
 * The authoritative record is the VEVENT; this provider projects each event as a
 * virtual ObjectEntity (uuid = event UID; object = {id, summary, startDate,
 * endDate, location}) and never writes back. It mirrors
 * {@see CalDavVtodoObjectSourceProvider} but for VEVENT instead of VTODO, and —
 * unlike the VTODO provider's link-scoped projection — surfaces the acting user's
 * WHOLE event directory (no X-OPENREGISTER link filter). Reuses
 * {@see \OCA\OpenRegister\Service\CalendarEventService}, which is already scoped to
 * the logged-in user's calendars, so another user's events are simply absent
 * (denied == not-found, no enumeration oracle).
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ObjectSource
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
 * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-5.1
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ObjectSource;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\CalendarEventService;
use OCP\App\IAppManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Read-only object-source provider backed by CalDAV VEVENTs.
 */
class CalendarEventObjectSourceProvider implements ObjectSourceProvider {

	use AnyAppInstalledCheck;
	use CountsThroughFindAll;

	/**
	 * Apps whose presence enables CalDAV VEVENT reads. VEVENTs live in the
	 * user's CalDAV calendars served by the CORE `dav` app — the `calendar`
	 * app is only an optional UI, so `dav` comes first with `calendar` as a
	 * positive signal too.
	 *
	 * @var list<string>
	 */
	private const ENABLING_APPS = ['dav', 'calendar'];

	/**
	 * The nullable object fields: field => VEVENT key.
	 *
	 * @var array<string, string>
	 */
	private const EVENT_FIELDS = [
		'startDate' => 'dtstart',
		'endDate' => 'dtend',
		'location' => 'location',
	];

	/**
	 * Constructor.
	 *
	 * @param CalendarEventService $calendarEventService The CalDAV VEVENT read/list service.
	 * @param IAppManager $appManager App availability checks.
	 * @param LoggerInterface $logger Logger for read failures.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly CalendarEventService $calendarEventService,
		private readonly IAppManager $appManager,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * {@inheritDoc}
	 *
	 * @return string The provider id.
	 *
	 * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-5.1
	 */
	public function getId(): string {
		return 'calendar-event-source';
	}//end getId()

	/**
	 * {@inheritDoc}
	 *
	 * @param Register $register The register the schema belongs to.
	 * @param Schema $schema The sourced schema.
	 * @param string $id The event UID (or calendar-object uri).
	 * @param array<string, mixed> $config The object-source config block (unused).
	 *
	 * @return ObjectEntity|null The virtual object, or null when absent/denied.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $config reserved for future scoping options.
	 *
	 * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-5.1
	 */
	public function find(Register $register, Schema $schema, string $id, array $config = []): ?ObjectEntity {
		foreach ($this->readEvents(limit: 1000, offset: 0) as $event) {
			if ($this->hasId(event: $event, id: $id) === true) {
				return $this->toObjectEntity(binding: new SourcedObjectBinding(register: $register, schema: $schema), event: $event);
			}
		}

		return null;
	}//end find()

	/**
	 * {@inheritDoc}
	 *
	 * Honours `limit` and `offset`; the event set is scoped by CalendarEventService
	 * to the acting user's own calendars.
	 *
	 * @param Register $register The register the schema belongs to.
	 * @param Schema $schema The sourced schema.
	 * @param array<string, mixed> $query Query (limit/offset).
	 * @param array<string, mixed> $config The object-source config block (unused).
	 *
	 * @return ObjectEntity[] The matching virtual objects.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $config reserved for future scoping options.
	 *
	 * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-5.1
	 */
	public function findAll(Register $register, Schema $schema, array $query = [], array $config = []): array {
		$limit = (int)($query['limit'] ?? 200);
		$offset = (int)($query['offset'] ?? 0);

		$binding = new SourcedObjectBinding(register: $register, schema: $schema);
		$objects = [];
		foreach ($this->readEvents(limit: $limit, offset: $offset) as $event) {
			$objects[] = $this->toObjectEntity(binding: $binding, event: $event);
		}

		return $objects;
	}//end findAll()

	/**
	 * Read VEVENT arrays for the acting user, failing closed to an empty list.
	 *
	 * @param int $limit Maximum events to read.
	 * @param int $offset Events to skip.
	 *
	 * @return array<int, array<string, mixed>> The event arrays.
	 *
	 * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-5.1
	 */
	private function readEvents(int $limit, int $offset): array {
		try {
			$result = $this->calendarEventService->getAllUserEvents(limit: $limit, offset: $offset);
			return array_values($result['results']);
		} catch (Throwable $e) {
			$this->logger->warning('[ObjectSource:calendar-event-source] could not read VEVENTs: ' . $e->getMessage());
			return [];
		}
	}//end readEvents()

	/**
	 * Whether a VEVENT answers to an id: its uid or its calendar-object uri.
	 *
	 * @param array<string, mixed> $event The VEVENT array.
	 * @param string               $id    The requested id.
	 *
	 * @return bool True when the event has that id.
	 */
	private function hasId(array $event, string $id): bool {
		return (string)($event['uid'] ?? '') === $id || (string)($event['id'] ?? '') === $id;
	}//end hasId()

	/**
	 * Map a CalendarEventService VEVENT array onto a non-persisted virtual ObjectEntity.
	 *
	 * @param SourcedObjectBinding $binding The bound register and schema.
	 * @param array<string, mixed> $event   The CalendarEventService VEVENT array.
	 *
	 * @return ObjectEntity The virtual object (never saved).
	 *
	 * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-5.1
	 */
	private function toObjectEntity(SourcedObjectBinding $binding, array $event): ObjectEntity {
		$uuid = (string)($event['uid'] ?? $event['id'] ?? '');
		return $binding->toEntity(uuid: $uuid, data: $this->eventData(event: $event, uuid: $uuid));
	}//end toObjectEntity()

	/**
	 * The object data of a VEVENT.
	 *
	 * @param array<string, mixed> $event The CalendarEventService VEVENT array.
	 * @param string               $uuid  The object uuid, echoed as `id`.
	 *
	 * @return array<string, mixed> The object data.
	 */
	private function eventData(array $event, string $uuid): array {
		$data = ['id' => $uuid, 'summary' => (string)($event['summary'] ?? '')];
		foreach (self::EVENT_FIELDS as $field => $key) {
			$data[$field] = ($event[$key] ?? null);
		}

		return $data;
	}//end eventData()
}//end class
