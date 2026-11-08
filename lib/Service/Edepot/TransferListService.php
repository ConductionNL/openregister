<?php

/**
 * OpenRegister Transfer List Service
 *
 * Manages transfer lists for e-Depot overbrenging workflow.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Edepot
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-support-transfer-list-management
 * @spec openspec/specs/edepot-transfer/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Edepot;

use DateTime;
use InvalidArgumentException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IAppConfig;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Service for managing e-Depot transfer lists.
 *
 * Transfer lists track which objects are pending, approved, or completed for
 * e-Depot transfer. They follow the same review-approve pattern as destruction lists.
 * Records persist through ObjectService via TransferRecordService, and carry the
 * per-connection output-format setting (zip default | bagit).
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/archival-transfer-hardening/specs/edepot-durable-retry/spec.md
 */
class TransferListService {

	/**
	 * Transfer list status constants.
	 */
	public const STATUS_IN_REVIEW = 'in_review';
	public const STATUS_APPROVED = 'approved';
	public const STATUS_REJECTED = 'rejected';
	public const STATUS_IN_PROGRESS = 'in_progress';
	public const STATUS_COMPLETED = 'completed';
	public const STATUS_PARTIALLY_FAILED = 'partially_failed';
	public const STATUS_FAILED = 'failed';

	/**
	 * Constructor.
	 *
	 * @param TransferRecordService $transferRecordService Durable transfer-record persistence.
	 * @param IAppConfig            $appConfig             The app configuration.
	 * @param INotificationManager  $notificationManager   The notification manager.
	 * @param LoggerInterface       $logger                Logger.
	 */
	public function __construct(
		private readonly TransferRecordService $transferRecordService,
		private readonly IAppConfig $appConfig,
		private readonly INotificationManager $notificationManager,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Persist a transfer-list mutation to the durable e-Depot transfer record
	 * (best-effort — a persistence failure logs a warning and returns the
	 * in-memory list so callers keep working; the archivist-facing record is
	 * the durable source of truth once written).
	 *
	 * @param array<string,mixed> $transferList The transfer-list data.
	 *
	 * @return array<string,mixed> The persisted (or in-memory on failure) list.
	 *
	 * @spec openspec/changes/archival-transfer-hardening/specs/edepot-proof-of-transfer/spec.md
	 *   (Requirement: Durable transfer-list objects served over the API)
	 */
	private function persist(array $transferList): array {
		try {
			return $this->transferRecordService->saveTransferList($transferList);
		} catch (\Throwable $e) {
			$this->logger->warning(
				message: '[TransferListService] Could not persist transfer list: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__, 'uuid' => ($transferList['uuid'] ?? '')]
			);
			return $transferList;
		}
	}//end persist()

	/**
	 * Log a review step and persist the list.
	 *
	 * @param array<string,mixed> $transferList The transfer-list data.
	 * @param string              $message      What happened.
	 * @param array<string,mixed> $context      The log context.
	 *
	 * @return array<string,mixed> The persisted (or in-memory on failure) list.
	 */
	private function logAndPersist(array $transferList, string $message, array $context): array {
		$this->logger->info(message: '[TransferListService] '.$message, context: $context);

		return $this->persist(transferList: $transferList);
	}//end logAndPersist()

	/**
	 * Create a new transfer list from eligible objects.
	 *
	 * @param array<int, ObjectEntity> $objects The objects eligible for transfer.
	 *
	 * @return array{
	 *     uuid: string,
	 *     status: string,
	 *     objectReferences: array<int, array{uuid: string, schema: int|null, register: int|null}>,
	 *     createdAt: string,
	 *     objectCount: int
	 * } The transfer list data.
	 *
	 * @throws InvalidArgumentException If no objects provided.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-support-transfer-list-management
	 * @spec openspec/specs/edepot-transfer/spec.md
	 */
	public function createTransferList(array $objects): array {
		if (empty($objects) === true) {
			throw new InvalidArgumentException('No objects provided for transfer list');
		}

		$transferList = self::newList(objectReferences: self::referencesOf(objects: $objects), packageFormat: $this->packageFormat());

		return $this->logAndPersist(
			transferList: $transferList,
			message: 'Created transfer list',
			context: ['uuid' => $transferList['uuid'], 'objectCount' => $transferList['objectCount']]
		);
	}//end createTransferList()

	/**
	 * Approve a transfer list.
	 *
	 * @param array<string,mixed> $transferList The transfer list data.
	 * @param string              $archivistId  The approving archivist user ID.
	 *
	 * @return array<string,mixed> The updated transfer list data.
	 *
	 * @throws InvalidArgumentException If the list is not in review status.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-support-transfer-list-management
	 * @spec openspec/specs/edepot-transfer/spec.md
	 */
	public function approveTransferList(array $transferList, string $archivistId): array {
		self::refuseUnlessInReview(transferList: $transferList, refusal: "Cannot approve transfer list with status '%s'; expected 'in_review'");

		$transferList['status']           = self::STATUS_APPROVED;
		$transferList['approvalMetadata'] = [
			'approvedBy' => $archivistId,
			'approvedAt' => (new DateTime())->format('c'),
		];

		return $this->logAndPersist(
			transferList: $transferList,
			message: 'Transfer list approved',
			context: ['uuid' => $transferList['uuid'], 'approvedBy' => $archivistId]
		);
	}//end approveTransferList()

	/**
	 * Reject a transfer list.
	 *
	 * @param array<string,mixed> $transferList The transfer list data.
	 * @param string              $archivistId  The rejecting archivist user ID.
	 * @param string              $reason       The reason for rejection.
	 *
	 * @return array<string,mixed> The updated transfer list data.
	 *
	 * @throws InvalidArgumentException If the list is not in review status, or no reason is given.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-support-transfer-list-management
	 * @spec openspec/specs/edepot-transfer/spec.md
	 */
	public function rejectTransferList(array $transferList, string $archivistId, string $reason): array {
		self::refuseUnlessInReview(transferList: $transferList, refusal: "Cannot reject transfer list with status '%s'; expected 'in_review'");
		if (empty($reason) === true) {
			throw new InvalidArgumentException('Rejection reason is required');
		}

		$transferList['status']           = self::STATUS_REJECTED;
		$transferList['approvalMetadata'] = ['rejectedBy' => $archivistId, 'rejectedAt' => (new DateTime())->format('c'), 'rejectionReason' => $reason];

		return $this->logAndPersist(
			transferList: $transferList,
			message: 'Transfer list rejected',
			context: ['uuid' => $transferList['uuid'], 'rejectedBy' => $archivistId, 'reason' => $reason]
		);
	}//end rejectTransferList()

	/**
	 * Exclude objects from a transfer list.
	 *
	 * @param array<string,mixed> $transferList The transfer list data.
	 * @param array<int, string>  $objectUuids  UUIDs of objects to exclude.
	 * @param string              $reason       The reason for exclusion.
	 *
	 * @return array<string,mixed> The updated transfer list data.
	 *
	 * @throws InvalidArgumentException If the list is not in review status.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-support-transfer-list-management
	 */
	public function excludeObjects(array $transferList, array $objectUuids, string $reason): array {
		self::refuseUnlessInReview(transferList: $transferList, refusal: "Cannot modify transfer list with status '%s'");

		$transferList['exclusions']       = self::withExclusions(
			excluded: ($transferList['exclusions'] ?? []),
			objectUuids: $objectUuids,
			reason: $reason
		);
		$transferList['objectReferences'] = self::withoutUuids(refs: $transferList['objectReferences'], objectUuids: $objectUuids);
		$transferList['objectCount']      = count($transferList['objectReferences']);

		return $this->logAndPersist(
			transferList: $transferList,
			message: 'Objects excluded from transfer list',
			context: ['uuid' => $transferList['uuid'], 'excludedCount' => count($objectUuids), 'reason' => $reason]
		);
	}//end excludeObjects()

	/**
	 * Get UUIDs of objects currently on active transfer lists.
	 *
	 * This retrieves UUIDs from transfer lists with status 'in_review' or 'approved'
	 * to prevent the same object from being placed on multiple transfer lists.
	 *
	 * @param array<int, array<string,mixed>> $activeTransferLists Active transfer list data.
	 *
	 * @return array<int, string> UUIDs of objects on active transfer lists.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-support-transfer-list-management
	 */
	public function getObjectsOnActiveTransferLists(array $activeTransferLists): array {
		$uuids = [];
		foreach ($activeTransferLists as $list) {
			if (self::isActive(transferList: $list) === true) {
				$uuids = array_merge($uuids, self::referencedUuids(transferList: $list));
			}
		}

		return array_unique($uuids);
	}//end getObjectsOnActiveTransferLists()

	/**
	 * Send notification to archivist users about a new transfer list.
	 *
	 * A failure to notify is logged, never thrown.
	 *
	 * @param array<string,mixed> $transferList The transfer list data.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-support-transfer-list-management
	 */
	public function notifyArchivists(array $transferList): void {
		try {
			$this->notificationManager->notify($this->archivistNotification(transferList: $transferList));
			$this->logger->info(
				message: '[TransferListService] Notification sent for transfer list',
				context: ['uuid' => $transferList['uuid']]
			);
		} catch (\Exception $e) {
			$this->logger->warning(
				message: '[TransferListService] Failed to send notification',
				context: ['uuid' => $transferList['uuid'], 'error' => $e->getMessage()]
			);
		}
	}//end notifyArchivists()

	/**
	 * Refuse a review step on a list that is not in review.
	 *
	 * @param array<string,mixed> $transferList The transfer list data.
	 * @param string              $refusal      The refusal, with `%s` for the list's status.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException If the list is not in review status.
	 */
	private static function refuseUnlessInReview(array $transferList, string $refusal): void {
		if ($transferList['status'] !== self::STATUS_IN_REVIEW) {
			throw new InvalidArgumentException(sprintf($refusal, $transferList['status']));
		}
	}//end refuseUnlessInReview()

	/**
	 * The references a new list holds for its objects.
	 *
	 * @param array<int, ObjectEntity> $objects The objects.
	 *
	 * @return list<array{uuid: string|null, schema: mixed, register: mixed}> The references.
	 */
	private static function referencesOf(array $objects): array {
		$objectReferences = [];
		foreach ($objects as $object) {
			$objectReferences[] = [
				'uuid' => $object->getUuid(),
				'schema' => $object->getSchema(),
				'register' => $object->getRegister(),
			];
		}

		return $objectReferences;
	}//end referencesOf()

	/**
	 * The configured package format (zip default | bagit).
	 *
	 * @return string The format.
	 */
	private function packageFormat(): string {
		$packageFormat = $this->appConfig->getValueString('openregister', 'edepot_package_format', 'zip');
		if (in_array($packageFormat, ['zip', 'bagit'], true) === false) {
			return 'zip';
		}

		return $packageFormat;
	}//end packageFormat()

	/**
	 * A new list, in review.
	 *
	 * @param list<array<string, mixed>> $objectReferences The references.
	 * @param string                     $packageFormat    The package format.
	 *
	 * @return array<string, mixed> The list.
	 */
	private static function newList(array $objectReferences, string $packageFormat): array {
		return [
			'uuid' => (string)Uuid::v4(),
			'status' => self::STATUS_IN_REVIEW,
			'objectReferences' => $objectReferences,
			'exclusions' => [],
			'approvalMetadata' => null,
			'attempts' => [],
			'transferResult' => null,
			'packageFormat' => $packageFormat,
			'createdAt' => (new DateTime())->format('c'),
			'objectCount' => count($objectReferences),
		];
	}//end newList()

	/**
	 * The existing exclusions with one entry appended per uuid.
	 *
	 * @param array<int, mixed>  $excluded    The existing exclusions.
	 * @param array<int, string> $objectUuids The uuids.
	 * @param string             $reason      The reason.
	 *
	 * @return array<int, mixed> The exclusions.
	 */
	private static function withExclusions(array $excluded, array $objectUuids, string $reason): array {
		foreach ($objectUuids as $uuid) {
			$excluded[] = ['uuid' => $uuid, 'reason' => $reason, 'excludedAt' => (new DateTime())->format('c')];
		}

		return $excluded;
	}//end withExclusions()

	/**
	 * The references without the excluded uuids.
	 *
	 * @param array<int, array<string, mixed>> $refs        The references.
	 * @param array<int, string>               $objectUuids The excluded uuids.
	 *
	 * @return list<array<string, mixed>> The remaining references.
	 */
	private static function withoutUuids(array $refs, array $objectUuids): array {
		return array_values(
			array_filter(
				$refs,
				static fn (array $ref): bool => in_array($ref['uuid'], $objectUuids, true) === false
			)
		);
	}//end withoutUuids()

	/**
	 * Whether a list still holds its objects (in review or approved).
	 *
	 * @param array<string,mixed> $transferList The list.
	 *
	 * @return bool True when active.
	 */
	private static function isActive(array $transferList): bool {
		return in_array(($transferList['status'] ?? ''), [self::STATUS_IN_REVIEW, self::STATUS_APPROVED], true);
	}//end isActive()

	/**
	 * The non-empty uuids a list references.
	 *
	 * @param array<string,mixed> $transferList The list.
	 *
	 * @return list<mixed> The uuids.
	 */
	private static function referencedUuids(array $transferList): array {
		$uuids = [];
		foreach (($transferList['objectReferences'] ?? []) as $ref) {
			if (empty($ref['uuid']) === false) {
				$uuids[] = $ref['uuid'];
			}
		}

		return $uuids;
	}//end referencedUuids()

	/**
	 * The notification archivists get for a new list.
	 *
	 * @param array<string,mixed> $transferList The transfer list data.
	 *
	 * @return INotification The notification.
	 */
	private function archivistNotification(array $transferList): INotification {
		$notification = $this->notificationManager->createNotification();
		$notification->setApp('openregister');
		$notification->setUser('admin');
		$notification->setDateTime(new DateTime());
		$notification->setObject('transfer_list', $transferList['uuid']);
		$notification->setSubject(
			'edepot_transfer_list_created',
			['uuid' => $transferList['uuid'], 'objectCount' => $transferList['objectCount']]
		);

		return $notification;
	}//end archivistNotification()
}//end class
