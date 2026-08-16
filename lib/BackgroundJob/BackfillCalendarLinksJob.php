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
	 * Constructor.
	 *
	 * @param ITimeFactory $time Time factory.
	 * @param CalendarLinkMapper $linkMapper Link-table mapper.
	 * @param CalendarEventService $calendarEventService Legacy X-OR-* CalDAV service.
	 * @param IUserManager $userManager User manager.
	 * @param IUserSession $userSession User session.
	 * @param IAppConfig $appConfig App config (for flag).
	 * @param LoggerInterface $logger Logger.
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
			$objectUuid = (string)($event['objectUuid'] ?? '');
			$eventUid = (string)($event['uid'] ?? '');
			if ($objectUuid === '' || $eventUid === '') {
				continue;
			}

			$this->backfillEvent(
				event: $event,
				objectUuid: $objectUuid,
				eventUid: $eventUid,
				inserted: $inserted,
				skipped: $skipped
			);
		}//end foreach
	}//end backfillUser()

	/**
	 * Insert a calendar link row for one event, or increment $skipped if
	 * the row already exists.
	 *
	 * @param array<string,mixed> $event Raw event data array.
	 * @param string $objectUuid OR object uuid.
	 * @param string $eventUid CalDAV event UID.
	 * @param int $inserted Running insert count (passed by reference).
	 * @param int $skipped Running skip count (passed by reference).
	 *
	 * @return void
	 */
	private function backfillEvent(
		array $event,
		string $objectUuid,
		string $eventUid,
		int &$inserted,
		int &$skipped,
	): void {
		try {
			$this->linkMapper->findByObjectAndEvent(
				objectUuid: $objectUuid,
				eventUid: $eventUid
			);
			$skipped++;
			return;
		} catch (DoesNotExistException $e) {
			// No row yet — insert it below.
		}

		try {
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

			$this->linkMapper->insert(entity: $link);
			$inserted++;
		} catch (Throwable $e) {
			$this->logger->warning(
				'BackfillCalendarLinksJob: failed to insert link for event ' . $eventUid . ': ' . $e->getMessage()
			);
		}//end try
	}//end backfillEvent()

	/**
	 * Apply dtstart / dtend from the raw event array to a CalendarLink entity.
	 *
	 * @param CalendarLink $link The link entity to update.
	 * @param array<string,mixed> $event Raw event data array.
	 *
	 * @return void
	 */
	private function applyEventDates(CalendarLink $link, array $event): void {
		if (isset($event['dtstart']) === true && $event['dtstart'] !== null) {
			$link->setDtstart(new DateTime((string)$event['dtstart']));
		}

		if (isset($event['dtend']) === true && $event['dtend'] !== null) {
			$link->setDtend(new DateTime((string)$event['dtend']));
		}
	}//end applyEventDates()
}//end class
