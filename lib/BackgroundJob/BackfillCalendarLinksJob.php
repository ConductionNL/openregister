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
use OCA\OpenRegister\BackgroundJob\Support\BackfillCalendarLinksPlatform;
use OCA\OpenRegister\BackgroundJob\Support\BackfillCalendarLinksSources;
use OCA\OpenRegister\Db\CalendarLink;
use OCA\OpenRegister\Db\CalendarLinkMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectCalendarEventsInterface;
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
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Backfill job requires CalendarLinkMapper,
 *   CalendarEventService, IUserManager, IUserSession, IAppConfig, and LoggerInterface;
 *   each is needed for iterating users, reading CalDAV X-OR-* properties, and persisting links.
 */
class BackfillCalendarLinksJob extends QueuedJob {

	/**
	 * App-config flag — set to "yes" to enable this job.
	 *
	 * @var string
	 */
	private const CONFIG_FLAG = 'backfill_calendar_links';

	/**
	 * Link-table mapper.
	 *
	 * @var CalendarLinkMapperInterface
	 */
	private readonly CalendarLinkMapperInterface $linkMapper;

	/**
	 * Legacy X-OR-* CalDAV service.
	 *
	 * @var ObjectCalendarEventsInterface
	 */
	private readonly ObjectCalendarEventsInterface $calendarEventService;

	/**
	 * User manager.
	 *
	 * @var IUserManager
	 */
	private readonly IUserManager $userManager;

	/**
	 * User session.
	 *
	 * @var IUserSession
	 */
	private readonly IUserSession $userSession;

	/**
	 * App config (for the flag).
	 *
	 * @var IAppConfig
	 */
	private readonly IAppConfig $appConfig;

	/**
	 * Logger.
	 *
	 * @var LoggerInterface
	 */
	private readonly LoggerInterface $logger;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory                  $time     Time factory.
	 * @param BackfillCalendarLinksSources  $sources  Link table, CalDAV events, users.
	 * @param BackfillCalendarLinksPlatform $platform App config and logger.
	 *
	 * @return void
	 */
	public function __construct(
		ITimeFactory $time,
		BackfillCalendarLinksSources $sources,
		BackfillCalendarLinksPlatform $platform,
	) {
		$this->linkMapper = $sources->linkMapper;
		$this->calendarEventService = $sources->calendarEventService;
		$this->userManager = $sources->userManager;
		$this->userSession = $sources->userSession;
		$this->appConfig = $platform->appConfig;
		$this->logger = $platform->logger;
		parent::__construct(time: $time);
	}//end __construct()

	/**
	 * Run the backfill.
	 *
	 * @param mixed $argument Job arguments (unused).
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $argument is required by the OCP
	 *   QueuedJob contract; this job is flag-gated and uses no per-run arguments.
	 *
	 * @spec exclude Disabled-by-default one-time Tier-2 migration backfill, gated behind the
	 *       `backfill_calendar_links` app-config flag; not a production capability surface.
	 */
	protected function run($argument): void {
		// Guard: skip unless explicitly enabled. TODO: enable after Tier-2 settles.
		$enabled = $this->appConfig->getValueString('openregister', self::CONFIG_FLAG, 'no');
		if ($enabled !== 'yes') {
			$this->logger->info('BackfillCalendarLinksJob skipped (flag ' . self::CONFIG_FLAG . ' = ' . $enabled . ')');
			return;
		}

		$inserted = 0;
		$skipped = 0;

		$this->userManager->callForSeenUsers(
			function ($user) use (&$inserted, &$skipped): void {
				$this->backfillUser(user: $user, inserted: $inserted, skipped: $skipped);
			}
		);

		$this->logger->info('BackfillCalendarLinksJob finished. Inserted=' . $inserted . ', skipped=' . $skipped);
	}//end run()

	/**
	 * Backfill calendar link rows for a single user.
	 *
	 * @param mixed $user NC user object from callForSeenUsers.
	 * @param int $inserted Running count of inserted rows (passed by reference).
	 * @param int $skipped Running count of skipped rows (passed by reference).
	 *
	 * @return void
	 */
	private function backfillUser(mixed $user, int &$inserted, int &$skipped): void {
		try {
			$this->userSession->setUser($user);
			$events = $this->calendarEventService->getEventsForObject(objectUuid: '');
		} catch (Throwable $e) {
			// No-op: callForSeenUsers iterates regardless of CalDAV state.
			return;
		}

		foreach ($events as $event) {
			$outcome = $this->backfillEntry(event: $event);
			$inserted += (int)($outcome === 'inserted');
			$skipped += (int)($outcome === 'skipped');
		}
	}//end backfillUser()

	/**
	 * Backfill one event entry, ignoring entries without an object uuid or uid.
	 *
	 * @param mixed $event Raw event data array.
	 *
	 * @return string|null 'inserted', 'skipped', or null when nothing was counted.
	 */
	private function backfillEntry(mixed $event): ?string {
		$objectUuid = (string)($event['objectUuid'] ?? '');
		$eventUid = (string)($event['uid'] ?? '');
		if ($objectUuid === '' || $eventUid === '') {
			return null;
		}

		return $this->backfillEvent(event: $event, objectUuid: $objectUuid, eventUid: $eventUid);
	}//end backfillEntry()

	/**
	 * Insert a calendar link row for one event, unless the row already exists.
	 *
	 * @param array<string,mixed> $event      Raw event data array.
	 * @param string              $objectUuid OR object uuid.
	 * @param string              $eventUid   CalDAV event UID.
	 *
	 * @return string|null 'inserted', 'skipped' (row exists), or null when the insert failed.
	 */
	private function backfillEvent(array $event, string $objectUuid, string $eventUid): ?string {
		try {
			$this->linkMapper->findByObjectAndEvent(
				objectUuid: $objectUuid,
				eventUid: $eventUid
			);
			return 'skipped';
		} catch (DoesNotExistException $e) {
			// No row yet — insert it below.
		}

		try {
			$link = $this->buildLink(event: $event, objectUuid: $objectUuid, eventUid: $eventUid);
			$this->linkMapper->insert(entity: $link);
			return 'inserted';
		} catch (Throwable $e) {
			$this->logger->warning(
				'BackfillCalendarLinksJob: failed to insert link for event ' . $eventUid . ': ' . $e->getMessage()
			);
			return null;
		}//end try
	}//end backfillEvent()

	/**
	 * Build the link row for one event.
	 *
	 * @param array<string,mixed> $event      Raw event data array.
	 * @param string              $objectUuid OR object uuid.
	 * @param string              $eventUid   CalDAV event UID.
	 *
	 * @return CalendarLink The link, not yet persisted.
	 */
	private function buildLink(array $event, string $objectUuid, string $eventUid): CalendarLink {
		$link = new CalendarLink();
		$link->setObjectUuid($objectUuid);
		$link->setRegisterId((int)($event['registerId'] ?? 0));
		$link->setSchemaId((int)($event['schemaId'] ?? 0));
		$link->setCalendarUri('');

		$calendarId = null;
		if (isset($event['calendarId']) === true) {
			$calendarId = (int)$event['calendarId'];
		}

		$summary = null;
		if (isset($event['summary']) === true) {
			$summary = (string)$event['summary'];
		}

		$location = null;
		if (isset($event['location']) === true) {
			$location = (string)$event['location'];
		}

		$link->setCalendarId($calendarId);
		$link->setEventUid($eventUid);
		$link->setEventUri((string)($event['id'] ?? ''));
		$link->setSummary($summary);
		$link->setLocation($location);

		$this->applyEventDates(link: $link, event: $event);

		$link->setLinkedBy('system:backfill');
		$link->setLinkedAt(new DateTime());
		$link->setTaggedWithXor(true);

		return $link;
	}//end buildLink()

	/**
	 * Apply dtstart / dtend from the raw event array to a CalendarLink entity.
	 *
	 * @param CalendarLink $link The link entity to update.
	 * @param array<string,mixed> $event Raw event data array.
	 *
	 * @return void
	 */
	private function applyEventDates(CalendarLink $link, array $event): void {
		if (isset($event['dtstart']) === true) {
			$link->setDtstart(new DateTime((string)$event['dtstart']));
		}

		if (isset($event['dtend']) === true) {
			$link->setDtend(new DateTime((string)$event['dtend']));
		}
	}//end applyEventDates()
}//end class
