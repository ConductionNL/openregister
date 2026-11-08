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
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Deletion\DestructionRecorder;
use OCA\OpenRegister\Service\Deletion\DestructionRefusedException;
use OCA\OpenRegister\Service\Deletion\DestructionScopeService;
use Psr\Log\LoggerInterface;
use Throwable;

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
	 * @param ErasureSchemaResolver   $schemas      Schema resolution for the destruction scope.
	 * @param ErasureDestroyGuard     $guard        The destroy right and the retention clocks.
	 * @param DestructionScopeService $scopeService What goes with the object.
	 * @param DestructionRecorder     $recorder     The record that outlives the object.
	 * @param MagicMapperInterface    $objectMapper The final delete.
	 * @param LoggerInterface         $logger       PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ErasureSchemaResolver $schemas,
		private readonly ErasureDestroyGuard $guard,
		private readonly DestructionScopeService $scopeService,
		private readonly DestructionRecorder $recorder,
		private readonly MagicMapperInterface $objectMapper,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Destroy one object through the recorded destruction.
	 *
	 * @param ObjectEntity         $object  The object to destroy.
	 * @param ErasurePreview       $record  The preview being run.
	 * @param array<string, mixed> $outcome The outcome (mutated by reference).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/gdpr-data-subject-rights/spec.md
	 */
	public function destroy(ObjectEntity $object, ErasurePreview $record, array &$outcome): void {
		$uuid = (string)($object->getUuid() ?? '');
		$schema = $this->schemas->schemaOf(object: $object);

		// THE RIGHT AND THE CLOCKS ARE ASKED AGAIN, PER OBJECT. An approval is
		// not a permission, and a hold placed after the approval has to win.
		$refusal = $this->guard->refusalFor(object: $object, schema: $schema);
		if ($refusal !== null) {
			$outcome['refused'][] = $this->refused(uuid: $uuid, refusal: $refusal);
			return;
		}

		$scopeReport = $this->destroyAndRecord(object: $object, schema: $schema, record: $record, outcome: $outcome);
		if ($scopeReport === null || $this->deleteRow(object: $object, outcome: $outcome) === false) {
			return;
		}

		$outcome['destroyed'][] = ['uuid' => $uuid, 'scope' => ($scopeReport['destroyed'] ?? []), 'total' => ($scopeReport['total'] ?? 0)];
	}//end destroy()

	/**
	 * Destroy the scope and write the record that names the request.
	 *
	 * @param ObjectEntity         $object  The object.
	 * @param Schema|null          $schema  Its schema.
	 * @param ErasurePreview       $record  The preview being run.
	 * @param array<string, mixed> $outcome The outcome (mutated by reference on failure).
	 *
	 * @return array<string, mixed>|null The scope report, or null when it was refused or failed.
	 */
	private function destroyAndRecord(ObjectEntity $object, ?Schema $schema, ErasurePreview $record, array &$outcome): ?array {
		try {
			$scopeReport = $this->scopeService->destroy(object: $object, schema: $schema);

			// THE DESTRUCTION RECORD NAMES THE REQUEST (D-3). Without this the
			// destruction log and the data subject request are two histories of
			// the same act that nobody can join.
			$this->recorder->record(object: $object, scope: $scopeReport, rule: ErasureRunner::RULE, context: $this->recordContext(record: $record));
		} catch (DestructionRefusedException $e) {
			$outcome['refused'][] = $this->refused(uuid: (string)($object->getUuid() ?? ''), refusal: $e);
			return null;
		} catch (Throwable $e) {
			$outcome['failed'][] = ['uuid' => (string)($object->getUuid() ?? ''), 'error' => $e->getMessage()];
			return null;
		}

		return $scopeReport;
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
	 * @param ObjectEntity         $object  The object.
	 * @param array<string, mixed> $outcome The outcome (mutated by reference on failure).
	 *
	 * @return bool True when the row is gone.
	 */
	private function deleteRow(ObjectEntity $object, array &$outcome): bool {
		$uuid = (string)($object->getUuid() ?? '');
		try {
			$this->objectMapper->delete($object);
		} catch (Throwable $e) {
			// The record is already written, so this leaves an over-recorded
			// destruction rather than an unrecorded one — the same trade the
			// delete window's own destroy endpoint makes.
			$this->logger->error(
				message: '[ErasureRunner] The destruction was recorded but the object could not be deleted',
				context: ['uuid' => $uuid, 'error' => $e->getMessage()]
			);
			$outcome['failed'][] = ['uuid' => $uuid, 'error' => $e->getMessage()];
			return false;
		}

		return true;
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
