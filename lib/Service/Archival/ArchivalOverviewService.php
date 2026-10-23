<?php

/**
 * OpenRegister Archival Overview Service
 *
 * Gathers what an archivist works on into one answer: the destruction lists,
 * the records under a legal hold, the e-Depot transfers and the selection list
 * in force. It only reads; every change goes through the endpoints that exist
 * for it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Archival
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/archival-overview-page/specs/archival-destruction-workflow/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Archival;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Edepot\TransferListService;
use OCA\OpenRegister\Service\Edepot\TransferRecordService;
use OCA\OpenRegister\Service\RetentionService;
use OCA\OpenRegister\Service\Settings\ObjectRetentionHandler;
use OCP\IL10N;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads the archiving work for the Archival page.
 *
 * @spec openspec/specs/archival-destruction-workflow/spec.md
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) It reads five archival stores by design.
 */
class ArchivalOverviewService {

	/**
	 * The most held records one overview lists.
	 */
	private const MAX_HELD_RECORDS = 200;

	/**
	 * Constructor.
	 *
	 * @param DestructionListRepository  $lists           Finds the destruction lists.
	 * @param DestructionReviewService   $reviews         Reads the entries of a list.
	 * @param RetentionService           $retention       Scans the records that carry retention.
	 * @param TransferRecordService      $transfers       Reads the e-Depot transfer lists.
	 * @param SelectielijstImportService $selectielijst   Reads the stored selection lists.
	 * @param ObjectRetentionHandler     $settingsHandler Reads the archival settings.
	 * @param DestructionListCreator     $creator         Creates a list for the objects named.
	 * @param IUserManager               $userManager     Resolves display names.
	 * @param IL10N                      $l10n            Translates the status labels.
	 * @param LoggerInterface            $logger          Logger.
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) A DI constructor: one collaborator per archival store it reads.
	 */
	public function __construct(
		private readonly DestructionListRepository $lists,
		private readonly DestructionReviewService $reviews,
		private readonly RetentionService $retention,
		private readonly TransferRecordService $transfers,
		private readonly SelectielijstImportService $selectielijst,
		private readonly ObjectRetentionHandler $settingsHandler,
		private readonly DestructionListCreator $creator,
		private readonly IUserManager $userManager,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The whole overview.
	 *
	 * @return array<string, mixed> The counts, the rows per tab and the two fact cards.
	 *
	 * @spec openspec/changes/archival-overview-page/specs/archival-destruction-workflow/spec.md#requirement-the-archiving-work-has-one-overview-read-req-aov-001
	 */
	public function overview(): array {
		$configured = $this->lists->isConfigured();
		$lists = [];
		if ($configured === true) {
			$lists = $this->destructionLists();
		}

		$held = $this->legalHolds();
		$transfers = $this->transferLists();
		$selection = $this->selectionList();

		$awaiting = count(
			array_filter(
				$lists,
				static fn (array $list): bool => in_array(
					$list['status'],
					[DestructionService::STATUS_IN_REVIEW, DestructionService::STATUS_AWAITING_SECOND],
					true
				)
			)
		);

		$heldOffLists = 0;
		foreach ($lists as $list) {
			if ($list['status'] !== DestructionService::STATUS_COMPLETED) {
				$heldOffLists += $list['excludedCount'];
			}
		}

		return [
			'configured' => $configured,
			'counts' => [
				'destructionLists' => count($lists),
				'awaitingApproval' => $awaiting,
				'legalHolds' => count($held['rows']),
				'transfers' => count($transfers),
				'selectionListVersions' => $selection['versionCount'],
			],
			'destructionLists' => $lists,
			'heldOffLists' => $heldOffLists,
			'legalHolds' => $held['rows'],
			'legalHoldsTruncated' => $held['truncated'],
			'transfers' => $transfers,
			'edepot' => $this->edepotFacts(transfers: $transfers),
			'selectionList' => $selection['inUse'],
			'selectionListVersions' => $selection['versions'],
		];
	}//end overview()

	/**
	 * Draw up a destruction list now from every record the daily sweep would put on one.
	 *
	 * The same rule as the sweep: eligible today and not already on an open list.
	 *
	 * @return array{list: array<string, mixed>|null, refused: array<int, array<string, string>>} The new
	 *               list, or null when nothing may be destroyed now.
	 *
	 * @throws \InvalidArgumentException When no destruction list register is configured.
	 *
	 * @spec openspec/changes/archival-overview-page/specs/archival-destruction-workflow/spec.md#requirement-a-destruction-list-can-be-drawn-up-from-the-page-req-aov-003
	 */
	public function drawUpList(): array {
		$eligible = $this->retention->findEligibleForDestruction(
			excludeUuids: $this->retention->getObjectsOnPendingDestructionLists()
		);
		if ($eligible === []) {
			return ['list' => null, 'refused' => []];
		}

		return $this->creator->createFor(
			uuids: array_map(static fn (ObjectEntity $object): string => (string) $object->getUuid(), $eligible)
		);
	}//end drawUpList()

	/**
	 * The destruction lists as rows, newest first.
	 *
	 * @return array<int, array<string, mixed>> One row per list.
	 */
	private function destructionLists(): array {
		$rows = [];
		foreach ($this->lists->findLists() as $list) {
			$data = ($list->getObject() ?? []);
			$entryCount = count($this->reviews->entries(listData: $data));
			$excluded = ($data['excluded'] ?? []);
			if (is_array($excluded) === false) {
				$excluded = [];
			}

			$status = (string) ($data['status'] ?? '');
			$createdBy = ($data['createdBy'] ?? null);

			$rows[] = [
				'uuid' => $list->getUuid(),
				'title' => $this->listTitle(list: $list, data: $data),
				'summary' => $this->l10n->n('%n record', '%n records', $entryCount),
				'createdAt' => ($data['createdAt'] ?? null),
				'createdBy' => $createdBy,
				'createdByName' => $this->displayName(userId: $createdBy),
				'status' => $status,
				'statusLabel' => $this->listStatusLabel(status: $status),
				'entryCount' => $entryCount,
				'excludedCount' => count(array_filter($excluded, 'is_array')),
			];
		}

		usort(
			$rows,
			static fn (array $left, array $right): int => strcmp((string) $right['createdAt'], (string) $left['createdAt'])
		);

		return $rows;
	}//end destructionLists()

	/**
	 * A list's title: its own name, or the date it was drawn up.
	 *
	 * @param ObjectEntity         $list The list object.
	 * @param array<string, mixed> $data The list's data.
	 *
	 * @return string The title.
	 */
	private function listTitle(ObjectEntity $list, array $data): string {
		foreach ([($data['title'] ?? null), ($data['name'] ?? null), $list->getName()] as $candidate) {
			if (is_string($candidate) === true && trim($candidate) !== '' && $candidate !== $list->getUuid()) {
				return trim($candidate);
			}
		}

		$createdAt = substr((string) ($data['createdAt'] ?? ''), 0, 10);
		if ($createdAt === '') {
			return $this->l10n->t('Destruction list');
		}

		return $this->l10n->t('Destruction list of %s', [$createdAt]);
	}//end listTitle()

	/**
	 * The label a destruction list status is shown with.
	 *
	 * @param string $status The stored status.
	 *
	 * @return string The translated label, or the status itself when unknown.
	 */
	private function listStatusLabel(string $status): string {
		return match ($status) {
			DestructionService::STATUS_IN_REVIEW => $this->l10n->t('Awaiting approval'),
			DestructionService::STATUS_AWAITING_SECOND => $this->l10n->t('Awaiting second approval'),
			DestructionService::STATUS_APPROVED => $this->l10n->t('Approved'),
			DestructionService::STATUS_REJECTED => $this->l10n->t('Rejected'),
			DestructionService::STATUS_COMPLETED => $this->l10n->t('Destroyed'),
			default => $status,
		};
	}//end listStatusLabel()

	/**
	 * The records whose retention carries an active legal hold.
	 *
	 * @return array{rows: array<int, array<string, mixed>>, truncated: bool} The held records.
	 */
	private function legalHolds(): array {
		try {
			$scan = $this->retention->scanObjectsWithRetention(
				accept: static fn (ObjectEntity $object): bool => ((($object->getRetention() ?? [])['legalHold'] ?? [])['active'] ?? false) === true,
				maxMatches: self::MAX_HELD_RECORDS
			);
		} catch (Throwable $e) {
			$this->logger->warning('[ArchivalOverviewService] Could not scan for legal holds: ' . $e->getMessage());
			return ['rows' => [], 'truncated' => true];
		}

		$rows = [];
		foreach ($scan['objects'] as $object) {
			$hold = (($object->getRetention() ?? [])['legalHold'] ?? []);
			$placedBy = ($hold['placedBy'] ?? null);
			$rows[] = [
				'uuid' => $object->getUuid(),
				'title' => ($object->getName() ?? $object->getUuid()),
				'reason' => ($hold['reason'] ?? null),
				'placedBy' => $placedBy,
				'placedByName' => $this->displayName(userId: $placedBy),
				'placedDate' => ($hold['placedDate'] ?? null),
				'holdCount' => count(($hold['holds'] ?? [])),
			];
		}

		return ['rows' => $rows, 'truncated' => ($scan['truncated'] === true)];
	}//end legalHolds()

	/**
	 * The e-Depot transfer lists as rows, newest first.
	 *
	 * @return array<int, array<string, mixed>> One row per transfer list.
	 */
	private function transferLists(): array {
		$rows = [];
		foreach ($this->transfers->listTransferLists() as $list) {
			$status = (string) ($list['status'] ?? '');
			$result = ($list['transferResult'] ?? null);
			$completedAt = null;
			if (is_array($result) === true) {
				$completedAt = ($result['completedAt'] ?? null);
			}

			$attempts = ($list['attempts'] ?? null);
			$lastAttempt = null;
			if (is_array($attempts) === true && $attempts !== []) {
				$lastAttempt = end($attempts);
			}

			$rows[] = [
				'uuid' => ($list['uuid'] ?? ($list['id'] ?? null)),
				'status' => $status,
				'statusLabel' => $this->transferStatusLabel(status: $status),
				'recordCount' => (int) ($list['objectCount'] ?? count(($list['objectReferences'] ?? []))),
				'packageFormat' => ($list['packageFormat'] ?? null),
				'createdAt' => ($list['createdAt'] ?? null),
				'completedAt' => $completedAt,
				'lastAttempt' => $lastAttempt,
			];
		}

		usort(
			$rows,
			static fn (array $left, array $right): int => strcmp((string) $right['createdAt'], (string) $left['createdAt'])
		);

		return $rows;
	}//end transferLists()

	/**
	 * The label a transfer status is shown with.
	 *
	 * @param string $status The stored status.
	 *
	 * @return string The translated label, or the status itself when unknown.
	 */
	private function transferStatusLabel(string $status): string {
		return match ($status) {
			TransferListService::STATUS_IN_REVIEW => $this->l10n->t('Awaiting approval'),
			TransferListService::STATUS_APPROVED => $this->l10n->t('Approved'),
			TransferListService::STATUS_REJECTED => $this->l10n->t('Rejected'),
			TransferListService::STATUS_IN_PROGRESS => $this->l10n->t('Transferring'),
			TransferListService::STATUS_COMPLETED => $this->l10n->t('Transferred'),
			TransferListService::STATUS_PARTIALLY_FAILED => $this->l10n->t('Partly failed'),
			TransferListService::STATUS_FAILED => $this->l10n->t('Failed'),
			default => $status,
		};
	}//end transferStatusLabel()

	/**
	 * The e-Depot fact card: the last completed transfer and the last failure.
	 *
	 * @param array<int, array<string, mixed>> $transfers The transfer rows, newest first.
	 *
	 * @return array<string, mixed> The facts; null values when nothing was transferred yet.
	 */
	private function edepotFacts(array $transfers): array {
		$completed = null;
		$failed = null;
		foreach ($transfers as $row) {
			if ($completed === null && $row['status'] === TransferListService::STATUS_COMPLETED) {
				$completed = $row;
			}

			$isFailure = in_array(
				$row['status'],
				[TransferListService::STATUS_FAILED, TransferListService::STATUS_PARTIALLY_FAILED],
				true
			);
			if ($failed === null && $isFailure === true) {
				$failed = $row;
			}
		}

		$lastTransfer = null;
		if ($completed !== null) {
			$lastTransfer = $this->l10n->n(
				'%n record',
				'%n records',
				(int) $completed['recordCount']
			);
		}

		return [
			'lastTransferAt' => ($completed['completedAt'] ?? ($completed['createdAt'] ?? null)),
			'lastTransferRecords' => $lastTransfer,
			'packageFormat' => ($completed['packageFormat'] ?? null),
			'lastFailure' => ($failed['statusLabel'] ?? null),
			'lastFailureAt' => ($failed['lastAttempt']['timestamp'] ?? ($failed['createdAt'] ?? null)),
		];
	}//end edepotFacts()

	/**
	 * The selection lists stored here and the one in force.
	 *
	 * @return array{versionCount: int, versions: array<int, array<string, mixed>>, inUse: array<string, mixed>} The versions.
	 */
	private function selectionList(): array {
		try {
			$counts = $this->selectielijst->versions();
		} catch (Throwable $e) {
			$this->logger->warning('[ArchivalOverviewService] Could not read the selection lists: ' . $e->getMessage());
			$counts = [];
		}

		$inUse = null;
		try {
			$setting = ($this->settingsHandler->getArchivalSettingsOnly()['selectielijstVersion'] ?? null);
			if (is_string($setting) === true && trim($setting) !== '') {
				$inUse = trim($setting);
			}
		} catch (Throwable $e) {
			$this->logger->warning('[ArchivalOverviewService] Could not read the archival settings: ' . $e->getMessage());
		}

		$versions = [];
		foreach ($counts as $version => $categories) {
			$active = ((string) $version === $inUse);
			$statusLabel = $this->l10n->t('Stored');
			if ($active === true) {
				$statusLabel = $this->l10n->t('Active');
			}

			$versions[] = [
				'version' => (string) $version,
				'categoryCount' => (int) $categories,
				'categories' => $this->l10n->n('%n category', '%n categories', (int) $categories),
				'active' => $active,
				'statusLabel' => $statusLabel,
			];
		}

		$current = [
			'version' => $inUse,
			'categoryCount' => (int) ($counts[$inUse] ?? 0),
			'categories' => null,
			'statusLabel' => null,
		];
		if ($inUse !== null) {
			$current['categories'] = $this->l10n->n('%n category', '%n categories', $current['categoryCount']);
			$current['statusLabel'] = $this->l10n->t('Active');
		}

		return ['versionCount' => count($versions), 'versions' => $versions, 'inUse' => $current];
	}//end selectionList()

	/**
	 * A user's display name, falling back to the user id.
	 *
	 * @param mixed $userId The stored user id.
	 *
	 * @return string|null The name, or null when there is no user id.
	 */
	private function displayName(mixed $userId): ?string {
		if (is_string($userId) === false || $userId === '') {
			return null;
		}

		$user = $this->userManager->get($userId);
		if ($user === null) {
			return $userId;
		}

		return $user->getDisplayName();
	}//end displayName()
}//end class
