<?php

/**
 * OpenRegister BackfillCalendarLinksJob
 *
 * Background job that scans the user's CalDAV calendars for VEVENTs
 * carrying X-OPENREGISTER-* properties and back-populates the
 * `openregister_calendar_links` Tier-2 link table.
 *
 * Disabled by default — out of scope for the Tier-2 rollout PR. Enable
 * via an app-config flag (`backfill_calendar_links`) once Tier-2 has
 * settled in production. Run manually via:
 *
 *   occ background-job:execute 'OCA\OpenRegister\BackgroundJob\BackfillCalendarLinksJob'
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob;

use DateTime;
use OCA\OpenRegister\Db\CalendarLink;
use OCA\OpenRegister\Db\CalendarLinkMapper;
use OCA\OpenRegister\Service\CalendarEventService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use OCP\IAppConfig;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Backfills the calendar link table from legacy X-OR-* CalDAV properties.
 *
 * TODO: enable via app-config flag after Tier-2 settles.
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class BackfillCalendarLinksJob extends QueuedJob
{

    /**
     * App-config flag — set to "yes" to enable this job.
     *
     * @var string
     */
    private const CONFIG_FLAG = 'backfill_calendar_links';

    /**
     * Constructor.
     *
     * @param ITimeFactory         $time                Time factory.
     * @param CalendarLinkMapper   $linkMapper          Link-table mapper.
     * @param CalendarEventService $calendarEventService Legacy X-OR-* CalDAV service.
     * @param IUserManager         $userManager         User manager.
     * @param IUserSession         $userSession         User session.
     * @param IAppConfig           $appConfig           App config (for flag).
     * @param LoggerInterface      $logger              Logger.
     *
     * @return void
     */
    public function __construct(
        ITimeFactory $time,
        private readonly CalendarLinkMapper $linkMapper,
        private readonly CalendarEventService $calendarEventService,
        private readonly IUserManager $userManager,
        private readonly IUserSession $userSession,
        private readonly IAppConfig $appConfig,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($time);
    }//end __construct()

    /**
     * Run the backfill.
     *
     * @param mixed $argument Job arguments (unused).
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     *
     * @spec exclude Disabled-by-default one-time Tier-2 migration backfill, gated behind the `backfill_calendar_links` app-config flag; not a production capability surface.
     */
    protected function run($argument): void
    {
        // Guard: skip unless explicitly enabled. TODO: enable after Tier-2 settles.
        $enabled = $this->appConfig->getValueString('openregister', self::CONFIG_FLAG, 'no');
        if ($enabled !== 'yes') {
            $this->logger->info('BackfillCalendarLinksJob skipped (flag '.self::CONFIG_FLAG.' = '.$enabled.')');
            return;
        }

        $inserted = 0;
        $skipped  = 0;

        $this->userManager->callForSeenUsers(function ($user) use (&$inserted, &$skipped): void {
            try {
                $this->userSession->setUser($user);
                $events = $this->calendarEventService->getEventsForObject(objectUuid: '');
            } catch (Throwable $e) {
                // No-op: callForSeenUsers iterates regardless of CalDAV state.
                return;
            }

            foreach ($events as $event) {
                $objectUuid = (string) ($event['objectUuid'] ?? '');
                $eventUid   = (string) ($event['uid'] ?? '');
                if ($objectUuid === '' || $eventUid === '') {
                    continue;
                }

                try {
                    $this->linkMapper->findByObjectAndEvent(
                        objectUuid: $objectUuid,
                        eventUid: $eventUid
                    );
                    $skipped++;
                    continue;
                } catch (DoesNotExistException $e) {
                    // No row yet — insert it below.
                }

                try {
                    $link = new CalendarLink();
                    $link->setObjectUuid($objectUuid);
                    $link->setRegisterId((int) ($event['registerId'] ?? 0));
                    $link->setSchemaId((int) ($event['schemaId'] ?? 0));
                    $link->setCalendarUri('');
                    $link->setCalendarId(isset($event['calendarId']) ? (int) $event['calendarId'] : null);
                    $link->setEventUid($eventUid);
                    $link->setEventUri((string) ($event['id'] ?? ''));
                    $link->setSummary(isset($event['summary']) ? (string) $event['summary'] : null);
                    $link->setLocation(isset($event['location']) ? (string) $event['location'] : null);
                    if (isset($event['dtstart']) === true && $event['dtstart'] !== null) {
                        $link->setDtstart(new DateTime((string) $event['dtstart']));
                    }
                    if (isset($event['dtend']) === true && $event['dtend'] !== null) {
                        $link->setDtend(new DateTime((string) $event['dtend']));
                    }
                    $link->setLinkedBy('system:backfill');
                    $link->setLinkedAt(new DateTime());
                    $link->setTaggedWithXor(true);

                    $this->linkMapper->insert(entity: $link);
                    $inserted++;
                } catch (Throwable $e) {
                    $this->logger->warning(
                        'BackfillCalendarLinksJob: failed to insert link for event '.$eventUid.': '.$e->getMessage()
                    );
                }
            }//end foreach
        });

        $this->logger->info('BackfillCalendarLinksJob finished. Inserted='.$inserted.', skipped='.$skipped);
    }//end run()
}//end class
