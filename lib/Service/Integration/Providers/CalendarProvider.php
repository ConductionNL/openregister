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

use InvalidArgumentException;
use OCA\OpenRegister\Service\CalendarEventService;
use OCA\OpenRegister\Service\CalendarLinkService;
use OCA\OpenRegister\Service\Integration\AbstractIntegrationProvider;
use OCP\App\IAppManager;
use OCP\IL10N;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Calendar (CalDAV / Meetings) integration provider.
 */
class CalendarProvider extends AbstractIntegrationProvider
{

    /**
     * NC app id that must be installed/enabled for this integration.
     *
     * @var string
     */
    private const REQUIRED_APP = 'calendar';

    /**
     * Constructor.
     *
     * @param CalendarEventService $calendarEventService Legacy X-OR-* CalDAV service.
     * @param CalendarLinkService  $calendarLinkService  Tier-2 link-table service (UNION read path).
     * @param IAppManager          $appManager           NC app manager.
     * @param IL10N                $l10n                 Localisation.
     * @param LoggerInterface      $logger               PSR-3 logger for surfacing CalDAV failures.
     *
     * @return void
     */
    public function __construct(
        private CalendarEventService $calendarEventService,
        private CalendarLinkService $calendarLinkService,
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
    public function getId(): string
    {
        return 'calendar';
    }//end getId()

    /**
     * Localised label rendered on the sidebar tab.
     *
     * @return string
     *
     * @spec openspec/specs/calendar-integration/spec.md
     */
    public function getLabel(): string
    {
        return $this->l10n->t('Meetings');
    }//end getLabel()

    /**
     * Icon name resolved by the frontend icon registry.
     *
     * @return string
     *
     * @spec openspec/specs/calendar-integration/spec.md
     */
    public function getIcon(): string
    {
        return 'Calendar';
    }//end getIcon()

    /**
     * Sidebar grouping bucket.
     *
     * @return string|null
     *
     * @spec openspec/specs/calendar-integration/spec.md
     */
    public function getGroup(): ?string
    {
        return 'comms';
    }//end getGroup()

    /**
     * Nextcloud app that must be installed for this integration.
     *
     * @return string|null
     *
     * @spec openspec/specs/calendar-integration/spec.md
     */
    public function getRequiredApp(): ?string
    {
        return self::REQUIRED_APP;
    }//end getRequiredApp()

    /**
     * Registry routing classification (not literal physical storage — see class docblock).
     *
     * @return string
     *
     * @spec openspec/specs/calendar-integration/spec.md
     */
    public function getStorageStrategy(): string
    {
        return 'link-table';
    }//end getStorageStrategy()

    /**
     * Whether the required NC app is installed.
     *
     * @return bool
     *
     * @spec openspec/specs/calendar-integration/spec.md
     */
    public function isEnabled(): bool
    {
        return $this->appManager->isInstalled(self::REQUIRED_APP);
    }//end isEnabled()

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
     * @param string              $register Register slug or numeric id (unused — CalDAV scope is per-user).
     * @param string              $schema   Schema slug or numeric id (unused).
     * @param string              $objectId Object uuid.
     * @param array<string,mixed> $filters  Optional filters (currently ignored).
     *
     * @return array<int,array<string,mixed>>
     *
     * @spec openspec/specs/calendar-integration/spec.md
     */
    public function list(string $register, string $schema, string $objectId, array $filters=[]): array
    {
        try {
            // Tier-2: UNION read across link-table + legacy X-OR-* scan.
            return $this->calendarLinkService->getLinkedEvents(objectUuid: $objectId);
        } catch (Throwable $e) {
            // CalDAV failures (no user, no VEVENT calendar) degrade to
            // an empty list rather than breaking the tab — AD-23 covers
            // the user-facing contract (empty list, no thrown exception
            // for link-table providers) but does NOT mandate silent
            // failure: surface the cause so an empty Meetings tab can
            // be diagnosed from nextcloud.log instead of guessing.
            $this->logger->warning(
                'CalendarProvider::list() failed; degrading to empty list',
                [
                    'app'       => 'openregister',
                    'provider'  => self::class,
                    'method'    => 'list',
                    'objectId'  => $objectId,
                    'register'  => $register,
                    'schema'    => $schema,
                    'exception' => $e,
                ]
            );
            return [];
        }//end try
    }//end list()

    /**
     * Unlink a VEVENT.
     *
     * `entityId` is `"{calendarId}/{eventUri}"` (the canonical form the
     * CalendarEventsController emits in its responses); both segments
     * are required for CalDAV addressing.
     *
     * @param string $register Register slug or numeric id (unused).
     * @param string $schema   Schema slug or numeric id (unused).
     * @param string $objectId Object uuid.
     * @param string $entityId Composite `"calendarId/eventUri"`.
     *
     * @return void
     *
     * @spec openspec/specs/calendar-integration/spec.md
     */
    public function delete(string $register, string $schema, string $objectId, string $entityId): void
    {
        // New shape: simple eventUid (Tier-2 link-table). Legacy shape:
        // "{calendarId}/{eventUri}" (CalendarProvider Phase B-2). We accept
        // both — if the entityId contains a slash, treat as legacy and
        // strip X-OR-* on the VEVENT; otherwise treat as link-table unlink.
        $parts = explode('/', $entityId, 2);
        if (count($parts) === 2) {
            [$calendarId, $eventUri] = $parts;
            $this->calendarEventService->unlinkEvent(calendarId: $calendarId, eventUri: $eventUri);
            return;
        }

        // EntityId is a bare eventUid — Tier-2 link-only removal.
        $this->calendarLinkService->unlinkEvent(objectUuid: $objectId, eventUid: $entityId);
    }//end delete()

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
    public function health(): array
    {
        $installed = $this->appManager->isInstalled(self::REQUIRED_APP);
        $status    = 'unavailable';
        $message   = 'NC Calendar app is not installed';
        if ($installed === true) {
            $status  = 'ok';
            $message = null;
        }

        return [
            'status'     => $status,
            'authStatus' => 'configured',
            'message'    => $message,
        ];
    }//end health()
}//end class
