<?php

/**
 * OpenRegister Legal Hold Service
 *
 * Manages legal holds (bevriezing) on register objects, preventing destruction
 * regardless of archival dates. Supports WOB/WOO requests and regulatory investigations.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Archival
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/archival-destruction-workflow/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Archival;

use DateTime;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\BackgroundJob\IJobList;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Service for managing legal holds on register objects.
 *
 * Legal holds prevent destruction of objects regardless of their archiefactiedatum.
 * Holds are stored in the object's retention.legalHold field.
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Legal holds require coordination with multiple services
 */
class LegalHoldService implements LegalHoldServiceInterface {

	/**
	 * Constructor.
	 *
	 * @param LegalHoldRecords $records The held objects, their audit trail and the retention row scanner.
	 * @param IUserSession $userSession User session service.
	 * @param IJobList $jobList Background job list for bulk operations.
	 * @param LoggerInterface $logger Logger for error and info messages.
	 */
	public function __construct(
		private readonly LegalHoldRecords $records,
		private readonly IUserSession $userSession,
		private readonly IJobList $jobList,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Place a legal hold on an object.
	 *
	 * @param ObjectEntity $object The object to place a hold on.
	 * @param string $reason The reason for the legal hold (e.g. WOO-verzoek reference).
	 * @param string|null $ownerKey The matter placing it, e.g. `filinq:legalHoldCase:<uuid>`; null for a manual hold.
	 *
	 * @return ObjectEntity The updated object with legal hold applied.
	 *
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 */
	public function placeHold(ObjectEntity $object, string $reason, ?string $ownerKey = null): ObjectEntity {
		return $this->place(object: $object, action: $this->action(ownerKey: $ownerKey, reason: $reason));
	}//end placeHold()

	/**
	 * Place one matter's hold on an object and store it.
	 *
	 * @param ObjectEntity $object The object to place a hold on.
	 * @param HoldAction   $action The matter placing it, why, and who places it now.
	 *
	 * @return ObjectEntity The updated object with legal hold applied.
	 */
	private function place(ObjectEntity $object, HoldAction $action): ObjectEntity {
		// One hold per matter (#4172): a second matter adds its own hold
		// instead of overwriting the first one's reason.
		$retention = (new LegalHoldLedger())->placeAction(retention: ($object->getRetention() ?? []), action: $action);
		$object->setRetention($retention);
		$this->records->objectMapper->update($object);

		$facts = ['reason' => $action->reason, 'ownerKey' => $action->ownerKey, 'placedBy' => $action->stamp->userId];
		$this->logger->info(message: '[LegalHoldService] Legal hold placed on object', context: $this->logContext(object: $object, facts: $facts));

		return $object;
	}//end place()

	/**
	 * A hold action by the current user, now.
	 *
	 * @param string|null $ownerKey The matter acting; null for a manual hold, or to release every hold.
	 * @param string      $reason   Why.
	 *
	 * @return HoldAction The action.
	 */
	private function action(?string $ownerKey, string $reason): HoldAction {
		$stamp = new HoldStamp(userId: $this->getCurrentUserId(), now: (new DateTime())->format('c'));
		return new HoldAction(ownerKey: $ownerKey, reason: $reason, stamp: $stamp);
	}//end action()

	/**
	 * Release a legal hold on an object.
	 *
	 * @param ObjectEntity $object The object to release the hold from.
	 * @param string $reason The reason for releasing the hold.
	 * @param string|null $ownerKey The matter releasing its own hold; null lifts every hold.
	 *
	 * @return ObjectEntity The updated object with legal hold released.
	 *
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 */
	public function releaseHold(ObjectEntity $object, string $reason, ?string $ownerKey = null): ObjectEntity {
		return $this->release(object: $object, action: $this->action(ownerKey: $ownerKey, reason: $reason));
	}//end releaseHold()

	/**
	 * Release one matter's hold, or every hold, on an object and store it.
	 *
	 * @param ObjectEntity $object The object to release the hold from.
	 * @param HoldAction   $action The matter releasing its own hold (null lifts every hold), why, and who releases it now.
	 *
	 * @return ObjectEntity The updated object with legal hold released.
	 */
	private function release(ObjectEntity $object, HoldAction $action): ObjectEntity {
		// A matter lifts only its own hold (#4172); naming no matter lifts
		// every hold, as a release always did.
		$retention = (new LegalHoldLedger())->releaseAction(retention: ($object->getRetention() ?? []), action: $action);
		$object->setRetention($retention);
		$this->records->objectMapper->update($object);

		$facts = ['releaseReason' => $action->reason, 'ownerKey' => $action->ownerKey, 'releasedBy' => $action->stamp->userId];
		$this->logger->info(message: '[LegalHoldService] Legal hold released on object', context: $this->logContext(object: $object, facts: $facts));

		return $object;
	}//end release()

	/**
	 * The log context of a hold change on one object.
	 *
	 * @param ObjectEntity         $object The object.
	 * @param array<string, mixed> $facts  What changed, and who changed it.
	 *
	 * @return array<string, mixed> The context.
	 */
	private function logContext(ObjectEntity $object, array $facts): array {
		return array_merge(['file' => __FILE__, 'line' => __LINE__, 'objectId' => $object->getUuid()], $facts);
	}//end logContext()

	/**
	 * Check if an object has an active legal hold.
	 *
	 * @param ObjectEntity $object The object to check.
	 *
	 * @return bool True if the object has an active legal hold.
	 *
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 */
	public function hasActiveHold(ObjectEntity $object): bool {
		return $object->hasActiveLegalHold();
	}//end hasActiveHold()

	/**
	 * Check if an object has an active legal hold using its retention array directly.
	 *
	 * @param array<string, mixed> $retention The object's retention data.
	 *
	 * @return bool True if the retention data indicates an active legal hold.
	 *
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 */
	public function hasActiveHoldFromRetention(array $retention): bool {
		$legalHold = $retention['legalHold'] ?? [];

		return ($legalHold['active'] ?? false) === true;
	}//end hasActiveHoldFromRetention()

	/**
	 * Schedule a bulk legal hold operation on all objects in a schema.
	 *
	 * @param int $schemaId The schema ID to apply holds to.
	 * @param int $registerId The register ID.
	 * @param string $reason The reason for the bulk legal hold.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 */
	public function bulkPlaceHold(int $schemaId, int $registerId, string $reason): void {
		$this->queueBulkHold(job: ['schemaId' => $schemaId, 'registerId' => $registerId, 'reason' => $reason, 'placedBy' => $this->getCurrentUserId()]);
	}//end bulkPlaceHold()

	/**
	 * Queue the bulk legal hold job, and say so in the log.
	 *
	 * @param array<string, mixed> $job The job's arguments.
	 *
	 * @return void
	 */
	private function queueBulkHold(array $job): void {
		$this->jobList->add(\OCA\OpenRegister\BackgroundJob\JobHandles::BULK_LEGAL_HOLD, $job);

		$this->logger->info(
			message: '[LegalHoldService] Bulk legal hold job queued',
			context: array_merge(['file' => __FILE__, 'line' => __LINE__], $job)
		);
	}//end queueBulkHold()

	/**
	 * The records that carry an active legal hold, with their active holds.
	 *
	 * Walks every retention source (the magic tables and the legacy blob
	 * table) and keeps the records whose hold is active; a released hold is
	 * not listed. A scan stopped at its cap says so in `truncated`.
	 *
	 * @return array{results: array<int, array<string, mixed>>, total: int, truncated: bool}
	 *
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 */
	public function listActiveHolds(): array {
		if ($this->records->scanner === null) {
			return ['results' => [], 'total' => 0, 'truncated' => false];
		}

		$scan = $this->records->scanner->scan(accept: static fn (ObjectEntity $object): bool => $object->hasActiveLegalHold());

		$results = array_map(fn (ObjectEntity $object): array => $this->heldRecord(object: $object), $scan['objects']);

		return ['results' => $results, 'total' => count($results), 'truncated' => $scan['truncated']];
	}//end listActiveHolds()

	/**
	 * One held record, as the list of held records shows it.
	 *
	 * @param ObjectEntity $object The held record.
	 *
	 * @return array<string, mixed> The record and its active holds.
	 */
	private function heldRecord(ObjectEntity $object): array {
		return [
			'id'       => $object->getUuid(),
			'register' => $object->getRegister(),
			'schema'   => $object->getSchema(),
			'name'     => $object->getName(),
			'holds'    => (new LegalHoldLedger())->activeHolds(retention: ($object->getRetention() ?? [])),
		];
	}//end heldRecord()

	/**
	 * Get the current authenticated user ID.
	 *
	 * @return string The user ID or 'system' if no user is authenticated.
	 *
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 */
	private function getCurrentUserId(): string {
		return ($this->userSession->getUser()?->getUID() ?? 'system');
	}//end getCurrentUserId()
}//end class
