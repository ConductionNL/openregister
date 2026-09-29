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
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
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
class LegalHoldService {

	/**
	 * Object entity mapper.
	 *
	 * @var MagicMapper
	 */
	private MagicMapper $objectMapper;

	/**
	 * Audit trail mapper for logging hold operations.
	 *
	 * @var AuditTrailMapper
	 */
	private AuditTrailMapper $auditTrailMapper;

	/**
	 * User session for identifying who placed the hold.
	 *
	 * @var IUserSession
	 */
	private IUserSession $userSession;

	/**
	 * Background job list for bulk operations.
	 *
	 * @var IJobList
	 */
	private IJobList $jobList;

	/**
	 * Logger instance.
	 *
	 * @var LoggerInterface
	 */
	private LoggerInterface $logger;

	/**
	 * Constructor.
	 *
	 * @param MagicMapper $objectMapper Object entity data mapper.
	 * @param AuditTrailMapper $auditTrailMapper Audit trail mapper for logging.
	 * @param IUserSession $userSession User session service.
	 * @param IJobList $jobList Background job list for bulk operations.
	 * @param LoggerInterface $logger Logger for error and info messages.
	 */
	public function __construct(
		MagicMapper $objectMapper,
		AuditTrailMapper $auditTrailMapper,
		IUserSession $userSession,
		IJobList $jobList,
		LoggerInterface $logger,
	) {
		$this->objectMapper = $objectMapper;
		$this->auditTrailMapper = $auditTrailMapper;
		$this->userSession = $userSession;
		$this->jobList = $jobList;
		$this->logger = $logger;
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
		$userId = $this->getCurrentUserId();

		// One hold per matter (#4172): a second matter adds its own hold
		// instead of overwriting the first one's reason.
		$object->setRetention(
			(new LegalHoldLedger())->place(
				retention: ($object->getRetention() ?? []),
				reason: $reason,
				ownerKey: $ownerKey,
				userId: $userId,
				now: (new DateTime())->format('c')
			)
		);

		$this->objectMapper->update($object);

		$this->logger->info(
			message: '[LegalHoldService] Legal hold placed on object',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'objectId' => $object->getUuid(),
				'reason' => $reason,
				'ownerKey' => $ownerKey,
				'placedBy' => $userId,
			]
		);

		return $object;
	}//end placeHold()

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
		$userId = $this->getCurrentUserId();

		// A matter lifts only its own hold (#4172); naming no matter lifts
		// every hold, as a release always did.
		$object->setRetention(
			(new LegalHoldLedger())->release(
				retention: ($object->getRetention() ?? []),
				ownerKey: $ownerKey,
				releaseReason: $reason,
				userId: $userId,
				now: (new DateTime())->format('c')
			)
		);
		$this->objectMapper->update($object);

		$this->logger->info(
			message: '[LegalHoldService] Legal hold released on object',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'objectId' => $object->getUuid(),
				'releaseReason' => $reason,
				'ownerKey' => $ownerKey,
				'releasedBy' => $userId,
			]
		);

		return $object;
	}//end releaseHold()

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
		$userId = $this->getCurrentUserId();

		$this->jobList->add(
			\OCA\OpenRegister\BackgroundJob\BulkLegalHoldJob::class,
			[
				'schemaId' => $schemaId,
				'registerId' => $registerId,
				'reason' => $reason,
				'placedBy' => $userId,
			]
		);

		$this->logger->info(
			message: '[LegalHoldService] Bulk legal hold job queued',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'schemaId' => $schemaId,
				'registerId' => $registerId,
				'reason' => $reason,
				'placedBy' => $userId,
			]
		);
	}//end bulkPlaceHold()

	/**
	 * Get the current authenticated user ID.
	 *
	 * @return string The user ID or 'system' if no user is authenticated.
	 *
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 */
	private function getCurrentUserId(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return 'system';
		}

		return $user->getUID();
	}//end getCurrentUserId()
}//end class
