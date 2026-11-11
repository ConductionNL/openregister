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

		$run = new CalendarLinkBackfillRun();

		$this->userManager->callForSeenUsers(
			function ($user) use ($run): void {
				$this->backfillUser(user: $user, run: $run);
			}
		);

		$this->logger->info('BackfillCalendarLinksJob finished. Inserted=' . $run->inserted . ', skipped=' . $run->skipped);
	}//end run()

	/**
	 * Backfill calendar link rows for a single user.
	 *
	 * @param mixed                   $user NC user object from callForSeenUsers.
	 * @param CalendarLinkBackfillRun $run  The run, counting inserted and skipped rows.
	 *
	 * @return void
	 */
	private function backfillUser(mixed $user, CalendarLinkBackfillRun $run): void {
		try {
			$this->userSession->setUser($user);
			$events = $this->calendarEventService->getEventsForObject(objectUuid: '');
		} catch (Throwable $e) {
			// No-op: callForSeenUsers iterates regardless of CalDAV state.
			return;
		}

		foreach ($events as $event) {
			$outcome = $this->backfillEntry(event: $event);
			$run->inserted += (int)($outcome === 'inserted');
			$run->skipped += (int)($outcome === 'skipped');
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

		return $this->backfillEvent(event: $event);
	}//end backfillEntry()

	/**
	 * Insert a calendar link row for one event, unless the row already exists.
	 *
	 * @param array<string,mixed> $event Raw event data array, carrying a non-empty `objectUuid` and `uid`.
	 *
	 * @return string|null 'inserted', 'skipped' (row exists), or null when the insert failed.
	 */
	private function backfillEvent(array $event): ?string {
		$objectUuid = (string)$event['objectUuid'];
		$eventUid   = (string)$event['uid'];
		if ($this->isAlreadyLinked(objectUuid: $objectUuid, eventUid: $eventUid) === true) {
			return 'skipped';
		}

		return $this->insertLink(event: $event, eventUid: $eventUid);
	}//end backfillEvent()

	/**
	 * Whether the object already has a link row for this event.
	 *
	 * @param string $objectUuid The object's UUID.
	 * @param string $eventUid   The event's UID.
	 *
	 * @return bool
	 */
	private function isAlreadyLinked(string $objectUuid, string $eventUid): bool {
		try {
			$this->linkMapper->findByObjectAndEvent(
				objectUuid: $objectUuid,
				eventUid: $eventUid
			);
			return true;
		} catch (DoesNotExistException $e) {
			// No row yet — insert it.
			return false;
		}
	}//end isAlreadyLinked()

	/**
	 * Insert the link row for one event, logging a failure.
	 *
	 * @param array<string,mixed> $event    Raw event data array.
	 * @param string              $eventUid The event's UID.
	 *
	 * @return string|null `inserted`, or null when the insert failed.
	 */
	private function insertLink(array $event, string $eventUid): ?string {
		try {
			$link = $this->buildLink(event: $event);
			$this->linkMapper->insert(entity: $link);
			return 'inserted';
		} catch (Throwable $e) {
			$this->logger->warning(
				'BackfillCalendarLinksJob: failed to insert link for event ' . $eventUid . ': ' . $e->getMessage()
			);
			return null;
		}//end try
	}//end insertLink()

	/**
	 * Build the link row for one event.
	 *
	 * @param array<string,mixed> $event Raw event data array, carrying a non-empty `objectUuid` and `uid`.
	 *
	 * @return CalendarLink The link, not yet persisted.
	 */
	private function buildLink(array $event): CalendarLink {
		$link = new CalendarLink();
		self::placeLink(link: $link, event: $event);
		self::describeEvent(link: $link, event: $event);

		$this->applyEventDates(link: $link, event: $event);

		$link->setLinkedBy('system:backfill');
		$link->setLinkedAt(new DateTime());
		$link->setTaggedWithXor(true);

		return $link;
	}//end buildLink()

	/**
	 * Set where the link belongs: the object, its register and schema, and the calendar.
	 *
	 * @param CalendarLink        $link  The link being built.
	 * @param array<string,mixed> $event Raw event data array.
	 *
	 * @return void
	 */
	private static function placeLink(CalendarLink $link, array $event): void {
		$link->setObjectUuid((string)$event['objectUuid']);
		$link->setRegisterId((int)($event['registerId'] ?? 0));
		$link->setSchemaId((int)($event['schemaId'] ?? 0));
		$link->setCalendarUri('');

		$calendarId = null;
		if (isset($event['calendarId']) === true) {
			$calendarId = (int)$event['calendarId'];
		}

		$link->setCalendarId($calendarId);
	}//end placeLink()

	/**
	 * Set what the link points at: the event's UID and URI, summary and location.
	 *
	 * @param CalendarLink        $link  The link being built.
	 * @param array<string,mixed> $event Raw event data array.
	 *
	 * @return void
	 */
	private static function describeEvent(CalendarLink $link, array $event): void {
		$link->setEventUid((string)$event['uid']);
		$link->setEventUri((string)($event['id'] ?? ''));
		$link->setSummary(self::optionalString(event: $event, key: 'summary'));
		$link->setLocation(self::optionalString(event: $event, key: 'location'));
	}//end describeEvent()

	/**
	 * An event field as a string; null when it is not set.
	 *
	 * @param array<string,mixed> $event Raw event data array.
	 * @param string              $key   The field.
	 *
	 * @return string|null
	 */
	private static function optionalString(array $event, string $key): ?string {
		if (isset($event[$key]) === true) {
			return (string)$event[$key];
		}

		return null;
	}//end optionalString()

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
