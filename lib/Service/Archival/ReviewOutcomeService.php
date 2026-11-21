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
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Edepot\TransferListService;
use RuntimeException;
use Throwable;

/**
 * Applies a review answer to the record it is about.
 *
 * @psalm-suppress UnusedClass
 */
class ReviewOutcomeService implements ReviewOutcomeServiceInterface {

	/**
	 * Constructor.
	 *
	 * @param MagicMapperInterface         $objectMapper        The record the decision is about.
	 * @param TransferListService $transferListService The e-Depot transfer path a transfer hands to.
	 */
	public function __construct(
		private readonly MagicMapperInterface $objectMapper,
		private readonly TransferListService $transferListService,
	) {
	}//end __construct()

	/**
	 * Carry out a review answer against the record it is about.
	 *
	 * @param string         $entryUuid The uuid of the record.
	 * @param ReviewerAnswer $given     The answer (one of DestructionReviewService::ANSWERS); the reason, recorded
	 *                                  on the record too; the new archiefactiedatum, for a retention; and the
	 *                                  reviewer, written onto the record's own outcome for a transfer.
	 *
	 * @return string|null The uuid of the transfer list a transfer created, null for the other answers.
	 *
	 * @throws RuntimeException When the record cannot be read, moved or handed over.
	 *
	 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/retention-management/spec.md
	 */
	public function apply(string $entryUuid, ReviewerAnswer $given): ?string {
		$keptHere = [DestructionReviewService::ANSWER_DESTROY, DestructionReviewService::ANSWER_RETAIN];
		if (in_array($given->answer, $keptHere, true) === false) {
			return $this->transfer(entryUuid: $entryUuid, given: $given);
		}

		$this->retainIfAsked(entryUuid: $entryUuid, given: $given);

		return null;
	}//end apply()

	/**
	 * Carry out a retention; a destroy does nothing to the record yet.
	 *
	 * A destroyed entry stays on the list, and the list's approval is what
	 * queues DestructionExecutionJob.
	 *
	 * @param string         $entryUuid The uuid of the record.
	 * @param ReviewerAnswer $given     Destroy or retain, why, and the new archiefactiedatum for a retention.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the record cannot be read or written.
	 */
	private function retainIfAsked(string $entryUuid, ReviewerAnswer $given): void {
		if ($given->answer === DestructionReviewService::ANSWER_RETAIN) {
			$this->retain(entryUuid: $entryUuid, given: $given);
		}
	}//end retainIfAsked()

	/**
	 * Move a record's archiefactiedatum, and say on the record why it moved.
	 *
	 * @param string         $entryUuid The uuid of the record.
	 * @param ReviewerAnswer $given     Why the reviewer kept it, and the new archiefactiedatum.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the record cannot be read or written.
	 */
	private function retain(string $entryUuid, ReviewerAnswer $given): void {
		$write = new OutcomeWrite(entryUuid: $entryUuid, verb: 'retain', what: 'the new date');
		$object = $this->load(write: $write);

		$object->setRetention($this->retainedRetention(retention: $this->retentionOf(object: $object), given: $given));

		$this->write(object: $object, write: $write);
	}//end retain()

	/**
	 * A retention block with its archiefactiedatum moved by a reviewer, and the move recorded.
	 *
	 * @param array<string, mixed> $retention The record's retention block.
	 * @param ReviewerAnswer       $given     Why the reviewer kept it, and the new archiefactiedatum.
	 *
	 * @return array<string, mixed> The retention block.
	 */
	private function retainedRetention(array $retention, ReviewerAnswer $given): array {
		// The date it had before a reviewer moved it, kept once. A second
		// retention must not overwrite the date the selectielijst produced.
		if (($retention['originalArchiefactiedatum'] ?? null) === null
			&& ($retention['archiefactiedatum'] ?? null) !== null
		) {
			$retention['originalArchiefactiedatum'] = $retention['archiefactiedatum'];
		}

		$retention['archiefactiedatum'] = (string)$given->newDate;

		$history = ($retention['retentionHistory'] ?? []);
		if (is_array($history) === false) {
			$history = [];
		}

		$history[] = ['date' => (new DateTimeImmutable())->format('c'), 'reason' => $given->reason, 'newArchiefactiedatum' => (string)$given->newDate];
		$retention['retentionHistory'] = $history;

		return $retention;
	}//end retainedRetention()

	/**
	 * Hand a record to the e-Depot transfer path.
	 *
	 * 🔴 THE RECORD SAYS WHAT HAPPENED TO IT, not only the list. A transfer
	 * recorded on the destruction list alone leaves the dossier itself
	 * unable to answer "were you handed over, and by whom", which is the
	 * question an auditor puts to the object and not to the worklist.
	 *
	 * @param string         $entryUuid The uuid of the record.
	 * @param ReviewerAnswer $given     Why the reviewer handed it over, and who decided, written onto the record's own outcome.
	 *
	 * @return string The uuid of the transfer list the record was put on.
	 *
	 * @throws RuntimeException When the record cannot be read or the list cannot be made.
	 */
	private function transfer(string $entryUuid, ReviewerAnswer $given): string {
		$write  = new OutcomeWrite(entryUuid: $entryUuid, verb: 'transfer', what: 'the outcome');
		$object = $this->load(write: $write);
		$uuid   = $this->transferListFor(object: $object, entryUuid: $entryUuid);

		$retention = $this->retentionOf(object: $object);
		$retention['outcome'] = $this->transferOutcome(given: $given, transferListUuid: $uuid);
		$object->setRetention($retention);

		$this->write(object: $object, write: $write);

		return $uuid;
	}//end transfer()

	/**
	 * The outcome a transfer records: now, by whom, why, and onto which transfer list.
	 *
	 * @param ReviewerAnswer $given            The reviewer and their reason.
	 * @param string         $transferListUuid The transfer list the entry went onto.
	 *
	 * @return array<string, string> The outcome.
	 */
	private function transferOutcome(ReviewerAnswer $given, string $transferListUuid): array {
		return [
			'kind' => 'transfer',
			'at' => (new DateTimeImmutable())->format('c'),
			'by' => $given->reviewer,
			'reason' => $given->reason,
			'transferListUuid' => $transferListUuid,
		];
	}//end transferOutcome()

	/**
	 * Put the record on a new transfer list.
	 *
	 * @param ObjectEntity $object    The record.
	 * @param string       $entryUuid The uuid of the record, for the message.
	 *
	 * @return string The uuid of the transfer list.
	 *
	 * @throws RuntimeException When the list cannot be made or has no uuid.
	 */
	private function transferListFor(ObjectEntity $object, string $entryUuid): string {
		try {
			$transferList = $this->transferListService->createTransferList(objects: [$object]);
		} catch (Throwable $e) {
			throw new RuntimeException(
				sprintf('Cannot transfer %s: the transfer list could not be made (%s)', $entryUuid, $e->getMessage())
			);
		}

		$uuid = ($transferList['uuid'] ?? null);
		if (is_string($uuid) === false || $uuid === '') {
			throw new RuntimeException(sprintf('Cannot transfer %s: the transfer list came back without a uuid', $entryUuid));
		}

		return $uuid;
	}//end transferListFor()

	/**
	 * The record a decision is about.
	 *
	 * @param OutcomeWrite $write The record's uuid and what was being done to it, for the message.
	 *
	 * @return ObjectEntity The record.
	 *
	 * @throws RuntimeException When the record cannot be read.
	 */
	private function load(OutcomeWrite $write): ObjectEntity {
		try {
			return $this->objectMapper->find($write->entryUuid, null, null, false, false, false);
		} catch (Throwable $e) {
			throw new RuntimeException(
				sprintf('Cannot %s %s: the record could not be read (%s)', $write->verb, $write->entryUuid, $e->getMessage())
			);
		}
	}//end load()

	/**
	 * Write the record back.
	 *
	 * @param ObjectEntity $object The record.
	 * @param OutcomeWrite $write  The record's uuid, what was being done to it and what was written, for the message.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the record cannot be written.
	 */
	private function write(ObjectEntity $object, OutcomeWrite $write): void {
		try {
			$this->objectMapper->update($object);
		} catch (Throwable $e) {
			throw new RuntimeException(
				sprintf('Cannot %s %s: %s could not be written (%s)', $write->verb, $write->entryUuid, $write->what, $e->getMessage())
			);
		}
	}//end write()

	/**
	 * The record's retention block, as an array.
	 *
	 * @param ObjectEntity $object The record.
	 *
	 * @return array<string, mixed> The retention block, empty when there is none.
	 */
	private function retentionOf(ObjectEntity $object): array {
		$retention = ($object->getRetention() ?? []);
		if (is_array($retention) === false) {
			return [];
		}

		return $retention;
	}//end retentionOf()
}//end class
