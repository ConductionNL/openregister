<?php

/**
 * What actually happens to a record once its reviewer has answered.
 *
 * The decision itself is written by {@see DestructionReviewService}, which
 * touches nothing but the list. This class is the other half: a retention moves
 * the record's own archiefactiedatum, and a transfer hands the record to the
 * e-Depot transfer path. A destruction does nothing here on purpose — the
 * record stays on the list and is destroyed by the approved list's execution
 * job, which is where the verklaring van vernietiging is produced.
 *
 * 🔴 THE OUTCOME IS APPLIED BEFORE THE DECISION IS RECORDED, NOT AFTER. A
 * transfer whose list could not be created must not leave a decision history
 * saying the record was handed over. So this runs first, and its failure
 * refuses the answer; the reverse order would produce exactly the record an
 * auditor cannot trust.
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
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/retention-management/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Archival;

use DateTimeImmutable;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Service\Edepot\TransferListService;
use RuntimeException;
use Throwable;

/**
 * Applies a review answer to the record it is about.
 *
 * @psalm-suppress UnusedClass
 */
class ReviewOutcomeService {

	/**
	 * Constructor.
	 *
	 * @param MagicMapper         $objectMapper        The record the decision is about.
	 * @param TransferListService $transferListService The e-Depot transfer path a transfer hands to.
	 */
	public function __construct(
		private readonly MagicMapper $objectMapper,
		private readonly TransferListService $transferListService,
	) {
	}//end __construct()

	/**
	 * Carry out a review answer against the record it is about.
	 *
	 * @param string      $answer    One of DestructionReviewService::ANSWERS.
	 * @param string      $entryUuid The uuid of the record.
	 * @param string      $reason    Why the reviewer answered this way, recorded on the record too.
	 * @param string|null $newDate   The new archiefactiedatum, for a retention.
	 * @param string|null $reviewer  Who answered, written onto the record's own outcome for a transfer.
	 *
	 * @return string|null The uuid of the transfer list a transfer created, null for the other answers.
	 *
	 * @throws RuntimeException When the record cannot be read, moved or handed over.
	 *
	 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/retention-management/spec.md
	 */
	public function apply(
		string $answer,
		string $entryUuid,
		string $reason,
		?string $newDate = null,
		?string $reviewer = null,
	): ?string {
		if ($answer === DestructionReviewService::ANSWER_DESTROY) {
			// Nothing to do to the record yet. It stays on the list, and the
			// list's approval is what queues DestructionExecutionJob.
			return null;
		}

		if ($answer === DestructionReviewService::ANSWER_RETAIN) {
			$this->retain(entryUuid: $entryUuid, reason: $reason, newDate: (string)$newDate);
			return null;
		}

		return $this->transfer(entryUuid: $entryUuid, reason: $reason, reviewer: $reviewer);
	}//end apply()

	/**
	 * Move a record's archiefactiedatum, and say on the record why it moved.
	 *
	 * @param string $entryUuid The uuid of the record.
	 * @param string $reason    Why the reviewer kept it.
	 * @param string $newDate   The new archiefactiedatum.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the record cannot be read or written.
	 */
	private function retain(string $entryUuid, string $reason, string $newDate): void {
		try {
			$object = $this->objectMapper->find($entryUuid, null, null, false, false, false);
		} catch (Throwable $e) {
			throw new RuntimeException(
				sprintf('Cannot retain %s: the record could not be read (%s)', $entryUuid, $e->getMessage())
			);
		}

		$retention = ($object->getRetention() ?? []);
		if (is_array($retention) === false) {
			$retention = [];
		}

		// The date it had before a reviewer moved it, kept once. A second
		// retention must not overwrite the date the selectielijst produced.
		if (($retention['originalArchiefactiedatum'] ?? null) === null
			&& ($retention['archiefactiedatum'] ?? null) !== null
		) {
			$retention['originalArchiefactiedatum'] = $retention['archiefactiedatum'];
		}

		$retention['archiefactiedatum'] = $newDate;

		$history = ($retention['retentionHistory'] ?? []);
		if (is_array($history) === false) {
			$history = [];
		}

		$history[] = [
			'date' => (new DateTimeImmutable())->format('c'),
			'reason' => $reason,
			'newArchiefactiedatum' => $newDate,
		];
		$retention['retentionHistory'] = $history;

		$object->setRetention($retention);

		try {
			$this->objectMapper->update($object);
		} catch (Throwable $e) {
			throw new RuntimeException(
				sprintf('Cannot retain %s: the new date could not be written (%s)', $entryUuid, $e->getMessage())
			);
		}
	}//end retain()

	/**
	 * Hand a record to the e-Depot transfer path.
	 *
	 * @param string      $entryUuid The uuid of the record.
	 * @param string      $reason    Why the reviewer handed it over.
	 * @param string|null $reviewer  Who decided, written onto the record's own outcome.
	 *
	 * @return string The uuid of the transfer list the record was put on.
	 *
	 * @throws RuntimeException When the record cannot be read or the list cannot be made.
	 */
	private function transfer(string $entryUuid, string $reason, ?string $reviewer): string {
		try {
			$object = $this->objectMapper->find($entryUuid, null, null, false, false, false);
		} catch (Throwable $e) {
			throw new RuntimeException(
				sprintf('Cannot transfer %s: the record could not be read (%s)', $entryUuid, $e->getMessage())
			);
		}

		try {
			$transferList = $this->transferListService->createTransferList(objects: [$object]);
		} catch (Throwable $e) {
			throw new RuntimeException(
				sprintf('Cannot transfer %s: the transfer list could not be made (%s)', $entryUuid, $e->getMessage())
			);
		}

		$uuid = ($transferList['uuid'] ?? null);
		if (is_string($uuid) === false || $uuid === '') {
			throw new RuntimeException(
				sprintf('Cannot transfer %s: the transfer list came back without a uuid', $entryUuid)
			);
		}

		// 🔴 THE RECORD SAYS WHAT HAPPENED TO IT, not only the list. A transfer
		// recorded on the destruction list alone leaves the dossier itself
		// unable to answer "were you handed over, and by whom", which is the
		// question an auditor puts to the object and not to the worklist.
		$retention = ($object->getRetention() ?? []);
		if (is_array($retention) === false) {
			$retention = [];
		}

		$retention['outcome'] = [
			'kind' => 'transfer',
			'at' => (new DateTimeImmutable())->format('c'),
			'by' => $reviewer,
			'reason' => $reason,
			'transferListUuid' => $uuid,
		];

		$object->setRetention($retention);

		try {
			$this->objectMapper->update($object);
		} catch (Throwable $e) {
			throw new RuntimeException(
				sprintf('Cannot transfer %s: the outcome could not be written (%s)', $entryUuid, $e->getMessage())
			);
		}

		return $uuid;
	}//end transfer()
}//end class
