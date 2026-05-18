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
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration\Providers
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/integration-calendar/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration\Providers;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- self-documenting IntegrationProvider metadata getters mirror the contract in the interface.

use OCA\OpenRegister\Service\CalendarEventService;
use OCA\OpenRegister\Service\Integration\AbstractIntegrationProvider;
use OCP\App\IAppManager;
use OCP\IL10N;
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
     * @param CalendarEventService $calendarEventService Backing service.
     * @param IAppManager          $appManager           NC app manager.
     * @param IL10N                $l10n                 Localisation.
     *
     * @return void
     */
    public function __construct(
        private CalendarEventService $calendarEventService,
        private IAppManager $appManager,
        private IL10N $l10n,
    ) {
    }//end __construct()

    public function getId(): string
    {
        return 'calendar';
    }//end getId()

    public function getLabel(): string
    {
        return $this->l10n->t('Meetings');
    }//end getLabel()

    public function getIcon(): string
    {
        return 'Calendar';
    }//end getIcon()

    public function getGroup(): ?string
    {
        return 'comms';
    }//end getGroup()

    public function getRequiredApp(): ?string
    {
        return self::REQUIRED_APP;
    }//end getRequiredApp()

    public function getStorageStrategy(): string
    {
        return 'link-table';
    }//end getStorageStrategy()

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
     * @param string              $register Register slug or numeric id (unused — CalDAV scope is per-user).
     * @param string              $schema   Schema slug or numeric id (unused).
     * @param string              $objectId Object uuid.
     * @param array<string,mixed> $filters  Optional filters (currently ignored).
     *
     * @return array<int,array<string,mixed>>
     */
    public function list(string $register, string $schema, string $objectId, array $filters=[]): array
    {
        try {
            return $this->calendarEventService->getEventsForObject(objectUuid: $objectId);
        } catch (Throwable $e) {
            // CalDAV failures (no user, no VEVENT calendar) degrade to
            // an empty list rather than breaking the tab — AD-23.
            return [];
        }
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
     */
    public function delete(string $register, string $schema, string $objectId, string $entityId): void
    {
        $parts = explode('/', $entityId, 2);
        if (count($parts) !== 2) {
            throw new \InvalidArgumentException('Calendar entityId must be "calendarId/eventUri"');
        }

        [$calendarId, $eventUri] = $parts;
        $this->calendarEventService->unlinkEvent(calendarId: $calendarId, eventUri: $eventUri);
    }//end delete()

    /**
     * Health descriptor.
     *
     * Calendar is "ok" whenever the app is installed; the CalDAV
     * backend is shipped with NC core, so install-state is the only
     * useful runtime signal at registry resolution time.
     *
     * @return array<string,mixed>
     */
    public function health(): array
    {
        $installed = $this->appManager->isInstalled(self::REQUIRED_APP);
        return [
            'status'     => $installed === true ? 'ok' : 'unavailable',
            'authStatus' => 'configured',
            'message'    => $installed === true ? null : 'NC Calendar app is not installed',
        ];
    }//end health()
}//end class
