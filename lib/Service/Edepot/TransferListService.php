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
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
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
class TransferListService implements TransferListServiceInterface, TransferListRunInterface {

	// The transfer list statuses (STATUS_*) are declared on TransferListServiceInterface.

	/**
	 * Constructor.
	 *
	 * @param TransferStores       $stores              The transfer records, the app settings and the log.
	 * @param INotificationManager $notificationManager The notification manager.
	 */
	public function __construct(
		private readonly TransferStores $stores,
		private readonly INotificationManager $notificationManager,
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
			return $this->stores->records->saveTransferList($transferList);
		} catch (\Throwable $e) {
			$this->stores->logger->warning(
				message: '[TransferListService] Could not persist transfer list: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__, 'uuid' => ($transferList['uuid'] ?? '')]
			);
			return $transferList;
		}
	}//end persist()

	/**
	 * Log a review step.
	 *
	 * @param string              $message What happened.
	 * @param array<string,mixed> $context The log context.
	 *
	 * @return void
	 */
	private function logStep(string $message, array $context): void {
		$this->stores->logger->info(message: '[TransferListService] '.$message, context: $context);
	}//end logStep()

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

		$this->logStep(message: 'Created transfer list', context: ['uuid' => $transferList['uuid'], 'objectCount' => $transferList['objectCount']]);

		return $this->persist(transferList: $transferList);
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

		$this->logStep(message: 'Transfer list approved', context: ['uuid' => $transferList['uuid'], 'approvedBy' => $archivistId]);

		return $this->persist(transferList: $transferList);
	}//end approveTransferList()


	/**
	 * Reject a transfer list in review.
	 *
	 * @param array<string,mixed> $transferList The transfer list data.
	 * @param TransferRejection   $rejection    Who rejects it, and why.
	 *
	 * @return array<string,mixed> The updated transfer list data.
	 *
	 * @throws InvalidArgumentException If the list is not in review status, or no reason is given.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-support-transfer-list-management
	 */
	public function reject(array $transferList, TransferRejection $rejection): array {
		self::refuseUnlessInReview(transferList: $transferList, refusal: "Cannot reject transfer list with status '%s'; expected 'in_review'");
		if (empty($rejection->reason) === true) {
			throw new InvalidArgumentException('Rejection reason is required');
		}

		$transferList['status']           = self::STATUS_REJECTED;
		$transferList['approvalMetadata'] = self::rejectionMetadata(rejection: $rejection);
		$this->logStep(
			message: 'Transfer list rejected',
			context: ['uuid' => $transferList['uuid'], 'rejectedBy' => $rejection->archivistId, 'reason' => $rejection->reason]
		);

		return $this->persist(transferList: $transferList);
	}//end reject()

	/**
	 * Who rejected the list, when, and why.
	 *
	 * @param TransferRejection $rejection The rejection.
	 *
	 * @return array{rejectedBy: string, rejectedAt: string, rejectionReason: string} The metadata.
	 */
	private static function rejectionMetadata(TransferRejection $rejection): array {
		return [
			'rejectedBy' => $rejection->archivistId,
			'rejectedAt' => (new DateTime())->format('c'),
			'rejectionReason' => $rejection->reason,
		];
	}//end rejectionMetadata()


	/**
	 * Take objects off a transfer list in review.
	 *
	 * @param array<string,mixed> $transferList The transfer list data.
	 * @param TransferExclusion   $exclusion    The objects to exclude, and why.
	 *
	 * @return array<string,mixed> The updated transfer list data.
	 *
	 * @throws InvalidArgumentException If the list is not in review status.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-support-transfer-list-management
	 */
	public function exclude(array $transferList, TransferExclusion $exclusion): array {
		self::refuseUnlessInReview(transferList: $transferList, refusal: "Cannot modify transfer list with status '%s'");

		$transferList['exclusions']       = self::withExclusions(excluded: ($transferList['exclusions'] ?? []), exclusion: $exclusion);
		$transferList['objectReferences'] = self::withoutUuids(refs: $transferList['objectReferences'], objectUuids: $exclusion->objectUuids);
		$transferList['objectCount']      = count($transferList['objectReferences']);
		$this->logStep(
			message: 'Objects excluded from transfer list',
			context: ['uuid' => $transferList['uuid'], 'excludedCount' => count($exclusion->objectUuids), 'reason' => $exclusion->reason]
		);

		return $this->persist(transferList: $transferList);
	}//end exclude()

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
			$this->stores->logger->info(
				message: '[TransferListService] Notification sent for transfer list',
				context: ['uuid' => $transferList['uuid']]
			);
		} catch (\Exception $e) {
			$this->stores->logger->warning(
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
		$packageFormat = $this->stores->settings->getValueString('openregister', 'edepot_package_format', 'zip');
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
	 * @param array<int, mixed> $excluded  The existing exclusions.
	 * @param TransferExclusion $exclusion The uuids and the reason.
	 *
	 * @return array<int, mixed> The exclusions.
	 */
	private static function withExclusions(array $excluded, TransferExclusion $exclusion): array {
		foreach ($exclusion->objectUuids as $uuid) {
			$excluded[] = ['uuid' => $uuid, 'reason' => $exclusion->reason, 'excludedAt' => (new DateTime())->format('c')];
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
