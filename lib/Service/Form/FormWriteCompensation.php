<?php

/**
 * OpenRegister Form Write Compensation
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Form
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Form;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Undoes the writes a submit made before a later write was refused.
 *
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-submit-must-create-the-destination-in-one-request-and-return-its-reference
 */
final readonly class FormWriteCompensation {

	/**
	 * Constructor.
	 *
	 * @param ObjectOperationsInterface $objects Deletes an earlier write.
	 * @param LoggerInterface           $logger  Records each undo and each failed undo.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-submit-must-create-the-destination-in-one-request-and-return-its-reference
	 */
	public function __construct(
		private ObjectOperationsInterface $objects,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Delete the writes this submit made before a later one was refused, and record it.
	 *
	 * @param array<int, array{plan: array<string, mixed>, object: ObjectEntity}> $created The writes made.
	 * @param FormSubmitFailure                                                  $failure The write that was refused, and why.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-submit-must-create-the-destination-in-one-request-and-return-its-reference
	 */
	public function compensate(array $created, FormSubmitFailure $failure): void {
		foreach (array_reverse($created) as $done) {
			$this->undo(done: $done, failure: $failure);
		}
	}//end compensate()

	/**
	 * Delete one earlier write of a refused submit, and record it.
	 *
	 * @param array{plan: array<string, mixed>, object: ObjectEntity} $done    The write made.
	 * @param FormSubmitFailure                                       $failure The write that was refused, and why.
	 *
	 * @return void
	 */
	private function undo(array $done, FormSubmitFailure $failure): void {
		if ($this->deleted(done: $done, failedAt: $failure->failedAt) === false) {
			return;
		}

		$this->logger->warning(
			message: '[FormSubmitService] Compensated: deleted an earlier write of a refused submit',
			context: [
				'uuid' => (string)$done['object']->getUuid(),
				'write' => $done['plan']['as'],
				'failedAt' => $failure->failedAt,
				'reason' => $failure->reason->getMessage(),
			]
		);
	}//end undo()

	/**
	 * Delete one earlier write; a failure is logged, not raised, so the other writes are still undone.
	 *
	 * @param array{plan: array<string, mixed>, object: ObjectEntity} $done     The write made.
	 * @param string                                                  $failedAt The write that was refused.
	 *
	 * @return bool True when it was deleted.
	 */
	private function deleted(array $done, string $failedAt): bool {
		$uuid = (string)$done['object']->getUuid();
		try {
			$this->deleteWrite(done: $done, uuid: $uuid);
		} catch (Throwable $exception) {
			$this->logger->error(
				message: '[FormSubmitService] Compensation failed: an earlier write of a refused submit could not be deleted',
				context: ['uuid' => $uuid, 'failedAt' => $failedAt, 'exception' => $exception->getMessage()]
			);
			return false;
		}

		return true;
	}//end deleted()

	/**
	 * Delete one earlier write.
	 *
	 * Permanent: a submit that did not happen leaves no tombstone that holds a
	 * unique key against the resident's retry. The audit trail keeps both the
	 * create and this delete.
	 *
	 * @param array{plan: array<string, mixed>, object: ObjectEntity} $done The write made.
	 * @param string                                                  $uuid Its uuid.
	 *
	 * @return void
	 */
	private function deleteWrite(array $done, string $uuid): void {
		$this->objects->deleteObject(
			uuid: $uuid,
			register: $done['plan']['register'],
			schema: $done['plan']['schema'],
			_rbac: false,
			_multitenancy: false,
			permanent: true
		);
	}//end deleteWrite()
}//end class
