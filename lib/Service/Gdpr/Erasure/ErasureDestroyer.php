<?php

/**
 * ErasureDestroyer — destroys one approved-erasable object through the recorded
 * destruction.
 *
 * D-3: there is exactly one way to destroy an object, and this calls it. The
 * per-object refusals are asked first; only when neither refuses is the scope
 * destroyed, then the destruction recorded (naming the data subject request),
 * and only then the object row deleted. Each step that fails stops the steps
 * after it and lands the object in `refused` or `failed`.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\Gdpr\Erasure
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Gdpr\Erasure;

use OCA\OpenRegister\Db\ErasurePreview;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Deletion\DestructionRefusedException;
use Psr\Log\LoggerInterface;
use Throwable;
use OCA\OpenRegister\Service\Deletion\RecordDestructionCommand;

/**
 * Destroys one object for an approved erasure, recording it first.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Gdpr\Erasure
 *
 * @spec openspec/specs/gdpr-data-subject-rights/spec.md
 */
class ErasureDestroyer {
	/**
	 * Wire the destruction path.
	 *
	 * @param ErasureDestructionSteps $steps Schema resolution, destruction scope, the surviving record and the final delete.
	 * @param ErasureDestroyGuard     $guard        The destroy right and the retention clocks.
	 * @param LoggerInterface         $logger       PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ErasureDestructionSteps $steps,
		private readonly ErasureDestroyGuard $guard,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Destroy one object through the recorded destruction.
	 *
	 * Answers with the run-report bucket the object lands in and its entry:
	 * `destroyed`, `refused` (a right or clock said no, before any write) or
	 * `failed`.
	 *
	 * @param ObjectEntity   $object The object to destroy.
	 * @param ErasurePreview $record The preview being run.
	 *
	 * @return array{0: string, 1: array<string, mixed>} [bucket, entry].
	 *
	 * @spec openspec/specs/gdpr-data-subject-rights/spec.md
	 */
	public function destroy(ObjectEntity $object, ErasurePreview $record): array {
		$schema = $this->steps->schemas->schemaOf(object: $object);

		// THE RIGHT AND THE CLOCKS ARE ASKED AGAIN, PER OBJECT. An approval is
		// not a permission, and a hold placed after the approval has to win.
		$refusal = $this->guard->refusalFor(object: $object, schema: $schema);
		if ($refusal !== null) {
			return ['refused', $this->refused(uuid: (string)($object->getUuid() ?? ''), refusal: $refusal)];
		}

		return $this->destroyAndRecord(destruction: new ErasureDestruction(object: $object, schema: $schema, record: $record));
	}//end destroy()

	/**
	 * Destroy the scope, write the record that names the request, delete the row.
	 *
	 * @param ErasureDestruction $destruction The object, its schema and the preview being run.
	 *
	 * @return array{0: string, 1: array<string, mixed>} [bucket, entry].
	 */
	private function destroyAndRecord(ErasureDestruction $destruction): array {
		try {
			$scopeReport = $this->steps->scopeService->destroy(object: $destruction->object, schema: $destruction->schema);

			// THE DESTRUCTION RECORD NAMES THE REQUEST (D-3). Without this the
			// destruction log and the data subject request are two histories of
			// the same act that nobody can join.
			$this->steps->recorder->recordDestruction(
				command: new RecordDestructionCommand(
					object: $destruction->object,
					scope: $scopeReport,
					rule: ErasureRunner::RULE,
					context: $this->recordContext(record: $destruction->record)
				)
			);
		} catch (DestructionRefusedException $e) {
			return ['refused', $this->refused(uuid: $destruction->uuid(), refusal: $e)];
		} catch (Throwable $e) {
			return ['failed', ['uuid' => $destruction->uuid(), 'error' => $e->getMessage()]];
		}

		return $this->deleteRow(object: $destruction->object, scopeReport: $scopeReport);
	}//end destroyAndRecord()

	/**
	 * What the destruction record says about the erasure that caused it.
	 *
	 * @param ErasurePreview $record The preview being run.
	 *
	 * @return array<string, mixed> The record context.
	 */
	private function recordContext(ErasurePreview $record): array {
		return [
			'dataSubjectRequest' => $record->getRequestId(),
			'erasurePreview' => $record->getUuid(),
			'dataSubject' => $record->getSubject(),
			'approvedBy' => $record->getApprovedBy(),
			// The recovery window is waived by construction: an approved
			// erasure is a decision to destroy now, and the record says so
			// rather than leaving a reader to infer it.
			'windowWaived' => true,
		];
	}//end recordContext()

	/**
	 * Delete the object row after its destruction was recorded.
	 *
	 * @param ObjectEntity         $object      The object.
	 * @param array<string, mixed> $scopeReport What the scope destruction reported.
	 *
	 * @return array{0: string, 1: array<string, mixed>} [bucket, entry]: destroyed, or failed when the row stays.
	 */
	private function deleteRow(ObjectEntity $object, array $scopeReport): array {
		$uuid = (string)($object->getUuid() ?? '');
		try {
			$this->steps->objectMapper->delete($object);
		} catch (Throwable $e) {
			// The record is already written, so this leaves an over-recorded
			// destruction rather than an unrecorded one — the same trade the
			// delete window's own destroy endpoint makes.
			$this->logger->error(
				message: '[ErasureRunner] The destruction was recorded but the object could not be deleted',
				context: ['uuid' => $uuid, 'error' => $e->getMessage()]
			);
			return ['failed', ['uuid' => $uuid, 'error' => $e->getMessage()]];
		}

		return ['destroyed', ['uuid' => $uuid, 'scope' => ($scopeReport['destroyed'] ?? []), 'total' => ($scopeReport['total'] ?? 0)]];
	}//end deleteRow()

	/**
	 * One `refused` entry.
	 *
	 * @param string                      $uuid    The object uuid.
	 * @param DestructionRefusedException $refusal The refusal.
	 *
	 * @return array<string, string> The entry.
	 */
	private function refused(string $uuid, DestructionRefusedException $refusal): array {
		return [
			'uuid' => $uuid,
			'rule' => $refusal->getRule(),
			'message' => $refusal->getMessage(),
		];
	}//end refused()
}//end class
