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

use DateTime;
use Exception;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Archival\AppraisalInterface;
use OCA\OpenRegister\Service\Archival\RecordStateInterface;
use OCA\OpenRegister\Service\Object\SaveObjectWriterInterface;
use OCA\OpenRegister\Service\RetentionServiceInterface;
use OCA\OpenRegister\Service\Settings\ArchivalSettingsReaderInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IGroupManager;
use OCP\Notification\IManager as INotificationManager;
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
	 * App config key for tracking notified objects.
	 */
	private const NOTIFIED_KEY = 'retention_notified_objects';

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
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
	 *
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 */
	protected function run($argument): void {
		$logger = $this->container->get(LoggerInterface::class);
		$logger->info('[DestructionCheckJob] Starting destruction check');

		try {
			$retentionService = $this->container->get(RetentionServiceInterface::class);
			$settingsHandler = $this->container->get(ArchivalSettingsReaderInterface::class);
			$settings = $settingsHandler->getArchivalSettingsOnly();

			if (empty($settings['destructionListRegister']) === true
				|| empty($settings['destructionListSchema']) === true
			) {
				$logger->info('[DestructionCheckJob] Destruction list register/schema not configured, skipping');
				return;
			}

			// Step 1: Send pre-destruction notifications.
			$this->sendPreDestructionNotifications(
				settings: $settings,
				logger: $logger
			);

			// Step 2: Find eligible objects and create destruction list.
			$excludeUuids = $retentionService->getObjectsOnPendingDestructionLists();
			$eligible = $retentionService->findEligibleForDestruction($excludeUuids);

			if (empty($eligible) === true) {
				$logger->info('[DestructionCheckJob] No objects eligible for destruction');
				return;
			}

			$logger->info('[DestructionCheckJob] Found ' . count($eligible) . ' objects eligible');

			// Step 3: Create destruction list as register object.
			$listData = $retentionService->createDestructionList($eligible);

			if ($listData === null) {
				$logger->warning('[DestructionCheckJob] Failed to create destruction list');
				return;
			}

			$saveObject = $this->container->get(SaveObjectWriterInterface::class);
			$savedList = $saveObject->saveObject(
				$settings['destructionListRegister'],
				$settings['destructionListSchema'],
				$listData,
				null,
				false,
				false,
				true,
				true
			);

			$logger->info(
				'[DestructionCheckJob] Created destruction list, UUID: ' . $savedList->getUuid()
			);

			// Step 4: Notify archivaris group.
			$this->sendReviewNotification(listUuid: $savedList->getUuid(), objectCount: count($eligible));
		} catch (Exception $e) {
			$logger->error('[DestructionCheckJob] Error: ' . $e->getMessage(), ['exception' => $e]);
		}//end try
	}//end run()

	/**
	 * Send pre-destruction notifications for approaching objects.
	 *
	 * @param array $settings Archival settings
	 * @param LoggerInterface $logger Logger
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 * @SuppressWarnings(PHPMD.NPathComplexity)
	 *
	 * @spec openspec/specs/retention-management/spec.md#requirement-the-system-must-send-pre-destruction-notifications
	 */
	private function sendPreDestructionNotifications(
		array $settings,
		LoggerInterface $logger,
	): void {
		$leadDays = (int)($settings['notificationLeadDays'] ?? 30);
		$threshold = (new DateTime())->modify("+{$leadDays} days")->format('Y-m-d');
		$today = (new DateTime())->format('Y-m-d');

		try {
			$retentionService = $this->container->get(RetentionServiceInterface::class);

			$appConfig = $this->container->get(\OCP\IAppConfig::class);
			$notifiedJson = $appConfig->getValueString('openregister', self::NOTIFIED_KEY, '[]');
			$notified = json_decode($notifiedJson, true) ?? [];
			// Track which already-notified UUIDs are still inside the pre-destruction window
			// this run; anything not re-seen has passed its date (or moved out) and is pruned
			// below so the appconfig blob stays bounded over time (OPS-7).
			$stillRelevant = [];
			$newNotified = [];
			$newCount = 0;

			// 🔴 THE SCAN READS THE MAGIC TABLES AS WELL AS THE LEGACY BLOB
			// TABLE. This loop used to select straight from
			// `openregister_objects`, which `BlobMigrationJob` drains into the
			// per-schema magic tables every five minutes, so on any migrated
			// install it read zero rows and no pre-destruction warning was ever
			// sent. {@see RetentionService::scanObjectsWithRetention()} visits
			// both stores, pages them, and caps one run.
			$scan = $retentionService->scanObjectsWithRetention(
				accept: static function (ObjectEntity $object) use ($today, $threshold): bool {
					$retention = ($object->getRetention() ?? []);
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
				}
			);

			foreach ($scan['objects'] as $object) {
				$retention = ($object->getRetention() ?? []);
				$uuid = $object->getUuid();

				// Object is still inside the notification window — keep it in the
				// notified set even if we already alerted on it earlier.
				if (in_array($uuid, $notified, true) === true) {
					$stillRelevant[$uuid] = true;
					continue;
				}

				if ((($retention['legalHold'] ?? [])['active'] ?? false) === true) {
					continue;
				}

				$this->sendObjectNotification(object: $object, logger: $logger);

				$newNotified[$uuid] = true;
				$newCount++;
			}//end foreach

			// Rebuild the persisted set from only the still-in-window UUIDs plus the freshly
			// notified ones, dropping any whose destruction date has passed or moved away.
			// A TRUNCATED RUN PRUNES NOTHING: it did not see every in-window object, so
			// treating what it missed as "no longer in the window" would re-notify those
			// records on a later run.
			$rebuilt = array_keys($stillRelevant + $newNotified);
			if ($scan['truncated'] === true) {
				$rebuilt = array_values(array_unique(array_merge($notified, array_keys($newNotified))));
			}

			if ($newCount > 0 || count($rebuilt) !== count($notified)) {
				$appConfig->setValueString('openregister', self::NOTIFIED_KEY, json_encode($rebuilt));
				$logger->info('[DestructionCheckJob] Sent ' . $newCount . ' pre-destruction notifications');
			}
		} catch (Exception $e) {
			$logger->warning('[DestructionCheckJob] Notification error: ' . $e->getMessage());
		}//end try
	}//end sendPreDestructionNotifications()

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
		$retention = ($object->getRetention() ?? []);
		$uuid = $object->getUuid();
		$parameters = [
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

		try {
			$notificationManager = $this->container->get(INotificationManager::class);
			$groupManager = $this->container->get(IGroupManager::class);

			$group = $groupManager->get('archivaris');
			if ($group === null) {
				return;
			}

			foreach ($group->getUsers() as $user) {
				$notification = $notificationManager->createNotification();
				$notification->setApp('openregister')
					->setUser($user->getUID())
					->setDateTime(new DateTime())
					->setObject('retention', $uuid)
					->setSubject('retention_approaching', $parameters);
				$notificationManager->notify($notification);
			}
		} catch (Exception $e) {
			$logger->warning('[DestructionCheckJob] Notification send error: ' . $e->getMessage());
		}//end try
	}//end sendObjectNotification()

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
			$notificationManager = $this->container->get(INotificationManager::class);
			$groupManager = $this->container->get(IGroupManager::class);

			$group = $groupManager->get('archivaris');
			if ($group === null) {
				return;
			}

			foreach ($group->getUsers() as $user) {
				$notification = $notificationManager->createNotification();
				$notification->setApp('openregister')
					->setUser($user->getUID())
					->setDateTime(new DateTime())
					->setObject('destruction_list', $listUuid)
					->setSubject(
						'destruction_list_review',
						[
							'listUuid' => $listUuid,
							'objectCount' => $objectCount,
						]
					);
				$notificationManager->notify($notification);
			}
		} catch (Exception $e) {
			$this->container->get(LoggerInterface::class)->warning('[DestructionCheckJob] Review notification error: ' . $e->getMessage());
		}//end try
	}//end sendReviewNotification()
}//end class
