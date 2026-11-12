<?php

/**
 * CalendarProvider — exposes CalDAV VEVENTs linked to an OpenRegister
 * object via the IntegrationProvider contract.
 *
 * The integration's backend already shipped as CalendarEventService —
 * X-OPENREGISTER-* properties on each VEVENT identify the owning object,
 * the Calendar app's CalDAV backend owns persistence. This provider just
 * surfaces that service through the registry contract so the
 * `CnObjectSidebar` and dashboard widgets can render a Meetings tab
 * without per-app glue.
 *
 * Storage strategy is `link-table` for the registry's dispatch purposes
 * even though the link is actually stored as a CalDAV custom property —
 * the registry only cares whether the link is local (link-table /
 * magic-column) versus remote (external / query-time) for routing.
 *
 * Create/update flows continue to go through the dedicated
 * CalendarEventsController; this provider's `list()` is what the
 * registry-driven sidebar uses for rendering, with `delete()` wired so
 * the unified inline unlink works out of the box.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration\Providers
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/integration-calendar/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration\Providers;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- self-documenting IntegrationProvider metadata getters mirror the contract in the interface.

use OCA\OpenRegister\Kernel\Contracts\ObjectRef;
use OCA\OpenRegister\Service\CalendarEvent\CalendarObjectAddress;
use OCA\OpenRegister\Service\Integration\AbstractIntegrationProvider;
use OCP\App\IAppManager;
use OCP\IL10N;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Calendar (CalDAV / Meetings) integration provider.
 */
class CalendarProvider extends AbstractIntegrationProvider {
	use LinkTableAppDescriptor;

	/**
	 * NC app id that must be installed/enabled for this integration.
	 *
	 * @var string
	 */
	private const REQUIRED_APP = 'calendar';

	/**
	 * Constructor.
	 *
	 * @param CalendarProviderServices $calendar   The calendar event and link services.
	 * @param IAppManager              $appManager NC app manager.
	 * @param IL10N                    $l10n       Localisation.
	 * @param LoggerInterface          $logger     PSR-3 logger for surfacing CalDAV failures.
	 *
	 * @return void
	 */
	public function __construct(
		private CalendarProviderServices $calendar,
		private IAppManager $appManager,
		private IL10N $l10n,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Provider identity used by the IntegrationProvider registry.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/calendar-integration/spec.md
	 */
	public function getId(): string {
		return 'calendar';
	}//end getId()

	/**
	 * Localised label rendered on the sidebar tab.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/calendar-integration/spec.md
	 */
	public function getLabel(): string {
		return $this->l10n->t('Meetings');
	}//end getLabel()

	/**
	 * Icon name resolved by the frontend icon registry.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/calendar-integration/spec.md
	 */
	public function getIcon(): string {
		return 'Calendar';
	}//end getIcon()

	/**
	 * Sidebar grouping bucket.
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/calendar-integration/spec.md
	 */
	public function getGroup(): ?string {
		return 'comms';
	}//end getGroup()

	/**
	 * List VEVENTs linked to an OR object.
	 *
	 * Filters are accepted but currently ignored — CalendarEventService
	 * returns the full per-object set; pagination is a UI concern and
	 * the list is bounded by the user's own calendar size.
	 *
	 * Payload contract — each row carries the keys serialized by
	 * `CalendarEventService::veventToArray()`:
	 *   id (event uri), uid, calendarId, summary, dtstart (ATOM),
	 *   dtend (ATOM), location, description, attendees (array of
	 *   email strings), status, objectUuid, registerId, schemaId
	 *
	 * This shape matches every field `CnCalendarTab` (timeline rows +
	 * inline create form) and `CnCalendarCard` (all four surfaces —
	 * including the single-entity chip's status badge) consume. No
	 * widening required for Phase B-2.
	 *
	 * Known limitation: `CalendarEventService::findUserCalendar()`
	 * picks the *first* VEVENT-supporting calendar for the current
	 * user. Events written to a different calendar are not discovered.
	 * Multi-calendar awareness is tracked as a fleet-wide follow-up.
	 *
	 * @param ObjectRef $ref The owning object: register, schema and uuid.
	 * @param array<string,mixed> $filters Optional filters (currently ignored).
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @spec openspec/specs/calendar-integration/spec.md
	 */
	public function list(ObjectRef $ref, array $filters = []): array {
		return $this->linkedEventsOf(ref: $ref);
	}//end list()

	/**
	 * The object's linked events; none, logged, when the calendar cannot be read.
	 *
	 * @param ObjectRef $ref The owning object; register and schema only name it in the log line.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function linkedEventsOf(ObjectRef $ref): array {
		try {
			// Tier-2: UNION read across link-table + legacy X-OR-* scan.
			return $this->calendar->calendarLinkService->getLinkedEvents(objectUuid: $ref->id);
		} catch (Throwable $e) {
			// CalDAV failures (no user, no VEVENT calendar) degrade to
			// an empty list rather than breaking the tab — AD-23 covers
			// the user-facing contract (empty list, no thrown exception
			// for link-table providers) but does NOT mandate silent
			// failure: surface the cause so an empty Meetings tab can
			// be diagnosed from nextcloud.log instead of guessing.
			$scope = ['objectId' => $ref->id, 'register' => $ref->register, 'schema' => $ref->schema, 'exception' => $e];
			$this->logger->warning(
				'CalendarProvider::list() failed; degrading to empty list',
				['app' => 'openregister', 'provider' => self::class, 'method' => 'list'] + $scope
			);
			return [];
		}//end try
	}//end linkedEventsOf()

	/**
	 * Unlink a VEVENT.
	 *
	 * `entityId` is `"{calendarId}/{eventUri}"` (the canonical form the
	 * CalendarEventsController emits in its responses); both segments
	 * are required for CalDAV addressing.
	 *
	 * @param ObjectRef $ref The owning object: register, schema and uuid.
	 * @param string $entityId Composite `"calendarId/eventUri"`.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/calendar-integration/spec.md
	 */
	public function delete(ObjectRef $ref, string $entityId): void {
		$this->unlinkEventOf(objectId: $ref->id, entityId: $entityId);
	}//end delete()

	/**
	 * Unlink one of an object's events, in either entity-id shape.
	 *
	 * @param string $objectId Owning object uuid.
	 * @param string $entityId `{calendarId}/{eventUri}` (legacy) or a bare event uid.
	 *
	 * @return void
	 */
	private function unlinkEventOf(string $objectId, string $entityId): void {
		// New shape: simple eventUid (Tier-2 link-table). Legacy shape:
		// "{calendarId}/{eventUri}" (CalendarProvider Phase B-2). We accept
		// both — if the entityId contains a slash, treat as legacy and
		// strip X-OR-* on the VEVENT; otherwise treat as link-table unlink.
		$parts = explode('/', $entityId, 2);
		if (count($parts) === 2) {
			[$calendarId, $eventUri] = $parts;
			$event = new CalendarObjectAddress(calendarId: $calendarId, uri: $eventUri);
			$this->calendar->calendarEventService->requireEventOf(objectUuid: $objectId, event: $event);
			$this->calendar->calendarEventService->unlinkEvent(calendarId: $calendarId, eventUri: $eventUri);
			return;
		}

		// EntityId is a bare eventUid — Tier-2 link-only removal.
		$this->calendar->calendarLinkService->unlinkEvent(objectUuid: $objectId, eventUid: $entityId);
	}//end unlinkEventOf()

	/**
	 * Health descriptor.
	 *
	 * Calendar is "ok" whenever the app is installed; the CalDAV
	 * backend is shipped with NC core, so install-state is the only
	 * useful runtime signal at registry resolution time.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/specs/calendar-integration/spec.md
	 */
	public function health(): array {
		return $this->installedHealth(available: $this->appManager->isInstalled(self::REQUIRED_APP) === true, missing: 'NC Calendar app is not installed');
	}//end health()
}//end class
