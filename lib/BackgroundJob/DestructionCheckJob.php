<?php

/**
 * OpenRegister Destruction Check Background Job
 *
 * Periodic background job that scans for objects eligible for destruction
 * and generates destruction lists for archivist review.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/archival-destruction-workflow/spec.md
 * @spec openspec/specs/retention-management/spec.md#requirement-the-system-must-send-pre-destruction-notifications
 * @spec openspec/specs/archival-destruction-workflow/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob;

use Closure;
use DateTime;
use Exception;
use OCA\OpenRegister\BackgroundJob\Support\DestructionCheckRun;
use OCA\OpenRegister\BackgroundJob\Support\PreDestructionPass;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Archival\AppraisalInterface;
use OCA\OpenRegister\Service\Archival\RecordStateInterface;
use OCA\OpenRegister\Service\Object\SaveObjectWriterInterface;
use OCA\OpenRegister\Service\RetentionServiceInterface;
use OCA\OpenRegister\Service\Settings\ArchivalSettingsReaderInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Periodic destruction check job.
 *
 * Scans for objects eligible for destruction and generates destruction lists.
 * Sends pre-destruction notifications for objects approaching their deadline.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class DestructionCheckJob extends TimedJob {

	/**
	 * Default interval: 24 hours (daily).
	 */
	private const DEFAULT_INTERVAL = 86400;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time Time factory for parent class
	 * @param ContainerInterface $container App container the job resolves its collaborators from at run time
	 *
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly ContainerInterface $container,
	) {
		parent::__construct(time: $time);

		try {
			$handler = $this->container->get(ArchivalSettingsReaderInterface::class);
			$settings = $handler->getArchivalSettingsOnly();
			$interval = (int)($settings['destructionCheckInterval'] ?? self::DEFAULT_INTERVAL);
		} catch (Exception $e) {
			$interval = self::DEFAULT_INTERVAL;
		}

		$this->setInterval(seconds: $interval);
	}//end __construct()

	/**
	 * Execute the destruction check job.
	 *
	 * @param mixed $argument Job arguments (unused for timed jobs)
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 */
	protected function run($argument): void {
		$logger = $this->container->get(LoggerInterface::class);
		$logger->info('[DestructionCheckJob] Starting destruction check');

		try {
			$this->checkForDestruction(logger: $logger);
		} catch (Exception $e) {
			$logger->error('[DestructionCheckJob] Error: ' . $e->getMessage(), ['exception' => $e]);
		}
	}//end run()

	/**
	 * Warn about approaching objects, then propose the eligible ones for destruction.
	 *
	 * @param LoggerInterface $logger The run's logger.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 */
	private function checkForDestruction(LoggerInterface $logger): void {
		$check = $this->startCheck(logger: $logger);
		if (self::isListTargetMissing(settings: $check->settings) === true) {
			$logger->info('[DestructionCheckJob] Destruction list register/schema not configured, skipping');
			return;
		}

		// Step 1: Send pre-destruction notifications.
		$this->sendPreDestructionNotifications(settings: $check->settings, logger: $logger);

		// Step 2: Find eligible objects and create destruction list.
		$eligible = $this->eligibleForDestruction(check: $check);
		if ($eligible !== []) {
			$this->proposeDestructionList(check: $check, eligible: $eligible);
		}
	}//end checkForDestruction()

	/**
	 * Resolve the retention service and read the archival settings for this run.
	 *
	 * @param LoggerInterface $logger The run's logger.
	 *
	 * @return DestructionCheckRun
	 */
	private function startCheck(LoggerInterface $logger): DestructionCheckRun {
		$check           = new DestructionCheckRun(logger: $logger, retention: $this->container->get(RetentionServiceInterface::class));
		$settingsHandler = $this->container->get(ArchivalSettingsReaderInterface::class);
		$check->settings = $settingsHandler->getArchivalSettingsOnly();
		return $check;
	}//end startCheck()

	/**
	 * Whether the register or schema destruction lists are saved into is not configured.
	 *
	 * @param array<string, mixed> $settings Archival settings.
	 *
	 * @return bool
	 */
	private static function isListTargetMissing(array $settings): bool {
		return empty($settings['destructionListRegister']) === true
			|| empty($settings['destructionListSchema']) === true;
	}//end isListTargetMissing()

	/**
	 * The objects eligible for destruction that are not on a pending list yet.
	 *
	 * @param DestructionCheckRun $check The run.
	 *
	 * @return array The eligible objects; empty when there are none.
	 */
	private function eligibleForDestruction(DestructionCheckRun $check): array {
		$excludeUuids = $check->retention->getObjectsOnPendingDestructionLists();
		$eligible     = $check->retention->findEligibleForDestruction($excludeUuids);

		if (empty($eligible) === true) {
			$check->logger->info('[DestructionCheckJob] No objects eligible for destruction');
			return [];
		}

		$check->logger->info('[DestructionCheckJob] Found ' . count($eligible) . ' objects eligible');
		return $eligible;
	}//end eligibleForDestruction()

	/**
	 * Save the eligible objects as one destruction list and ask the archivists to review it.
	 *
	 * @param DestructionCheckRun $check    The run.
	 * @param array               $eligible The eligible objects.
	 *
	 * @return void
	 */
	private function proposeDestructionList(DestructionCheckRun $check, array $eligible): void {
		// Step 3: Create destruction list as register object.
		$listData = $check->retention->createDestructionList($eligible);

		if ($listData === null) {
			$check->logger->warning('[DestructionCheckJob] Failed to create destruction list');
			return;
		}

		$savedList = $this->saveDestructionList(settings: $check->settings, listData: $listData);

		$check->logger->info(
			'[DestructionCheckJob] Created destruction list, UUID: ' . $savedList->getUuid()
		);

		// Step 4: Notify archivaris group.
		$this->sendReviewNotification(listUuid: $savedList->getUuid(), objectCount: count($eligible));
	}//end proposeDestructionList()

	/**
	 * Save a destruction list as an object in the configured register and schema.
	 *
	 * @param array<string, mixed> $settings Archival settings.
	 * @param array                $listData The list the retention service built.
	 *
	 * @return ObjectEntity The saved list.
	 */
	private function saveDestructionList(array $settings, array $listData): ObjectEntity {
		$saveObject = $this->container->get(SaveObjectWriterInterface::class);
		return $saveObject->saveObject(
			$settings['destructionListRegister'],
			$settings['destructionListSchema'],
			$listData,
			null,
			false,
			false,
			true,
			true
		);
	}//end saveDestructionList()

	/**
	 * Send pre-destruction notifications for approaching objects.
	 *
	 * @param array $settings Archival settings
	 * @param LoggerInterface $logger Logger
	 *
	 * @return void
	 *
	 * @spec openspec/specs/retention-management/spec.md#requirement-the-system-must-send-pre-destruction-notifications
	 */
	private function sendPreDestructionNotifications(
		array $settings,
		LoggerInterface $logger,
	): void {
		$accept = self::approachingWithin(leadDays: (int)($settings['notificationLeadDays'] ?? 30));

		try {
			$retentionService = $this->container->get(RetentionServiceInterface::class);
			$pass = new PreDestructionPass(appConfig: $this->container->get(\OCP\IAppConfig::class), logger: $logger);

			// 🔴 THE SCAN READS THE MAGIC TABLES AS WELL AS THE LEGACY BLOB
			// TABLE. This loop used to select straight from
			// `openregister_objects`, which `BlobMigrationJob` drains into the
			// per-schema magic tables every five minutes, so on any migrated
			// install it read zero rows and no pre-destruction warning was ever
			// sent. {@see RetentionService::scanObjectsWithRetention()} visits
			// both stores, pages them, and caps one run.
			$scan = $retentionService->scanObjectsWithRetention(accept: $accept);

			$this->notifyEach(objects: $scan['objects'], pass: $pass);
			$pass->save(truncated: ($scan['truncated'] === true));
		} catch (Exception $e) {
			$logger->warning('[DestructionCheckJob] Notification error: ' . $e->getMessage());
		}//end try
	}//end sendPreDestructionNotifications()

	/**
	 * The scan filter: live objects whose action date falls after today and within the lead days.
	 *
	 * @param int $leadDays How many days ahead the archivists are warned.
	 *
	 * @return Closure(ObjectEntity): bool
	 */
	private static function approachingWithin(int $leadDays): Closure {
		$threshold = (new DateTime())->modify("+{$leadDays} days")->format('Y-m-d');
		$today     = (new DateTime())->format('Y-m-d');

		return static function (ObjectEntity $object) use ($today, $threshold): bool {
			$retention  = ($object->getRetention() ?? []);
			$actionDate = ($retention['archiefactiedatum'] ?? null);

			// Every spelling that means live. Matching only the English
			// one would make every pre-existing record invisible to the
			// pre-destruction warning, so the first a records officer
			// would hear of a destruction is after it happened.
			$status = ($retention['archiefstatus'] ?? null);
			if ($actionDate === null || in_array($status, RecordStateInterface::ACTIVE_ALIASES, true) === false) {
				return false;
			}

			return ($actionDate > $today && $actionDate <= $threshold);
		};
	}//end approachingWithin()

	/**
	 * Warn about each approaching object the scan found.
	 *
	 * @param iterable<ObjectEntity> $objects The approaching objects.
	 * @param PreDestructionPass     $pass    The pass's notified set.
	 *
	 * @return void
	 */
	private function notifyEach(iterable $objects, PreDestructionPass $pass): void {
		foreach ($objects as $object) {
			$this->notifyOnce(object: $object, pass: $pass);
		}
	}//end notifyEach()

	/**
	 * Warn about one approaching object unless it was already warned about or is held.
	 *
	 * @param ObjectEntity       $object The approaching object.
	 * @param PreDestructionPass $pass   The pass's notified set.
	 *
	 * @return void
	 */
	private function notifyOnce(ObjectEntity $object, PreDestructionPass $pass): void {
		$retention = ($object->getRetention() ?? []);
		$uuid      = $object->getUuid();

		// Object is still inside the notification window — keep it in the
		// notified set even if we already alerted on it earlier.
		if ($pass->keepIfNotified(uuid: $uuid) === true) {
			return;
		}

		if ((($retention['legalHold'] ?? [])['active'] ?? false) === true) {
			return;
		}

		$this->sendObjectNotification(object: $object, logger: $pass->logger);
		$pass->markNotified(uuid: $uuid);
	}//end notifyOnce()

	/**
	 * Send a notification about one approaching object to every archivist.
	 *
	 * The subject is a destruction warning, or an e-Depot transfer for a
	 * permanently retained record. The title is the object's name, else its uuid.
	 *
	 * @param ObjectEntity $object The approaching object.
	 * @param LoggerInterface $logger Logger
	 *
	 * @return void
	 *
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 */
	private function sendObjectNotification(ObjectEntity $object, LoggerInterface $logger): void {
		$parameters = self::approachingParameters(object: $object);

		try {
			$this->notifyArchivists(describe: self::approachingNotice(uuid: $object->getUuid(), parameters: $parameters));
		} catch (Exception $e) {
			$logger->warning('[DestructionCheckJob] Notification send error: ' . $e->getMessage());
		}//end try
	}//end sendObjectNotification()

	/**
	 * The parameters an approaching object is announced with.
	 *
	 * @param ObjectEntity $object The approaching object.
	 *
	 * @return array<string, string>
	 */
	private static function approachingParameters(ObjectEntity $object): array {
		$retention = ($object->getRetention() ?? []);
		$uuid      = $object->getUuid();
		return [
			'subject' => self::subjectOf(retention: $retention),
			// 🔴 NOT `getTitle()`. `ObjectEntity` HAS NO `title`
			// PROPERTY, so Entity's magic accessor threw
			// "title is not a valid attribute" on the first object it
			// reached. Inside this try that aborted the entire
			// notification pass; in `createDestructionList()` the same
			// call took down the whole job. `name` is the property that
			// exists, and `getObjectArray()` already falls back to the
			// uuid the same way.
			'title' => self::titleOf(name: $object->getName(), uuid: $uuid),
			'actieDate' => (string)($retention['archiefactiedatum'] ?? ''),
			'classification' => (self::classificationOf(retention: $retention) ?? ''),
		];
	}//end approachingParameters()

	/**
	 * What an approaching-object notice is about.
	 *
	 * @param string|null          $uuid       The approaching object's uuid.
	 * @param array<string, mixed> $parameters The notice's parameters.
	 *
	 * @return Closure(INotification): void
	 */
	private static function approachingNotice(?string $uuid, array $parameters): Closure {
		return static function (INotification $notification) use ($uuid, $parameters): void {
			$notification->setObject('retention', $uuid)
				->setSubject('retention_approaching', $parameters);
		};
	}//end approachingNotice()

	/**
	 * The subject an approaching object is announced with.
	 *
	 * @param array<string, mixed> $retention The object's retention block.
	 *
	 * @return string An e-Depot transfer for a permanently retained record, else a destruction warning.
	 */
	private static function subjectOf(array $retention): string {
		if (in_array(($retention['archiefnominatie'] ?? null), AppraisalInterface::RETAIN_PERMANENTLY_ALIASES, true) === true) {
			return 'Object requires e-Depot transfer';
		}

		return 'Object approaching destruction date';
	}//end subjectOf()

	/**
	 * The title an approaching object is announced with.
	 *
	 * @param string|null $name The object's name.
	 * @param string $uuid The object's uuid.
	 *
	 * @return string The name, else the uuid.
	 */
	private static function titleOf(?string $name, string $uuid): string {
		return ($name ?? $uuid);
	}//end titleOf()

	/**
	 * The selectielijst category of an approaching object, typed as the payload requires.
	 *
	 * @param array<string, mixed> $retention The object's retention block.
	 *
	 * @return string|null The category, or null when none is recorded.
	 */
	private static function classificationOf(array $retention): ?string {
		return ($retention['classification'] ?? null);
	}//end classificationOf()

	/**
	 * Send review notification for new destruction list.
	 *
	 * A failure is logged as a warning on the app logger and never fails the run.
	 *
	 * @param string $listUuid Destruction list UUID
	 * @param int $objectCount Number of objects
	 *
	 * @return void
	 *
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 */
	private function sendReviewNotification(string $listUuid, int $objectCount): void {
		try {
			$this->notifyArchivists(describe: self::reviewNotice(listUuid: $listUuid, objectCount: $objectCount));
		} catch (Exception $e) {
			$this->container->get(LoggerInterface::class)->warning('[DestructionCheckJob] Review notification error: ' . $e->getMessage());
		}//end try
	}//end sendReviewNotification()

	/**
	 * What a destruction-list review notice is about.
	 *
	 * @param string $listUuid    Destruction list UUID.
	 * @param int    $objectCount Number of objects on the list.
	 *
	 * @return Closure(INotification): void
	 */
	private static function reviewNotice(string $listUuid, int $objectCount): Closure {
		return static function (INotification $notification) use ($listUuid, $objectCount): void {
			$notification->setObject('destruction_list', $listUuid)
				->setSubject(
					'destruction_list_review',
					[
						'listUuid' => $listUuid,
						'objectCount' => $objectCount,
					]
				);
		};
	}//end reviewNotice()
	/**
	 * Send one notification to every member of the archivaris group.
	 *
	 * Each notice is stamped with the app, the archivist and now; $describe sets
	 * what it is about. Nothing is sent when the group does not exist.
	 *
	 * @param Closure(INotification): void $describe Sets the notice's object and subject.
	 *
	 * @return void
	 */
	private function notifyArchivists(Closure $describe): void {
		$notificationManager = $this->container->get(INotificationManager::class);

		foreach ($this->archivists() as $user) {
			$notification = $notificationManager->createNotification();
			$notification->setApp('openregister')
				->setUser($user->getUID())
				->setDateTime(new DateTime());
			$describe($notification);
			$notificationManager->notify($notification);
		}
	}//end notifyArchivists()

	/**
	 * The members of the archivaris group; none when the group does not exist.
	 *
	 * @return iterable<IUser>
	 */
	private function archivists(): iterable {
		$groupManager = $this->container->get(IGroupManager::class);

		$group = $groupManager->get('archivaris');
		if ($group === null) {
			return [];
		}

		return $group->getUsers();
	}//end archivists()
}//end class
