<?php

/**
 * OpenRegister Legal Hold Service
 *
 * Manages legal holds (bevriezing) on register objects, preventing destruction
 * regardless of archival dates. Supports WOB/WOO requests and regulatory investigations.
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
 * @spec openspec/changes/retrofit-2026-04-30-annotate-openregister/tasks.md#task-8
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Archival;

use DateTime;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\AuditTrailMapper;
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
class LegalHoldService
{

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
     * @param MagicMapper      $objectMapper     Object entity data mapper.
     * @param AuditTrailMapper $auditTrailMapper Audit trail mapper for logging.
     * @param IUserSession     $userSession      User session service.
     * @param IJobList         $jobList          Background job list for bulk operations.
     * @param LoggerInterface  $logger           Logger for error and info messages.
     */
    public function __construct(
        MagicMapper $objectMapper,
        AuditTrailMapper $auditTrailMapper,
        IUserSession $userSession,
        IJobList $jobList,
        LoggerInterface $logger
    ) {
        $this->objectMapper     = $objectMapper;
        $this->auditTrailMapper = $auditTrailMapper;
        $this->userSession      = $userSession;
        $this->jobList          = $jobList;
        $this->logger           = $logger;
    }//end __construct()

    /**
     * Place a legal hold on an object.
     *
     * @param ObjectEntity $object The object to place a hold on.
     * @param string       $reason The reason for the legal hold (e.g. WOO-verzoek reference).
     *
     * @return ObjectEntity The updated object with legal hold applied.
     *
     * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-5
     * @spec openspec/changes/retrofit-2026-04-30-annotate-openregister/tasks.md#task-8
     */
    public function placeHold(ObjectEntity $object, string $reason): ObjectEntity
    {
        $userId    = $this->getCurrentUserId();
        $retention = $object->getRetention() ?? [];

        $holdData = [
            'active'     => true,
            'reason'     => $reason,
            'placedBy'   => $userId,
            'placedDate' => (new DateTime())->format('c'),
            'history'    => $retention['legalHold']['history'] ?? [],
        ];

        $retention['legalHold'] = $holdData;
        $object->setRetention($retention);

        $this->objectMapper->update($object);

        $this->logger->info(
            message: '[LegalHoldService] Legal hold placed on object',
            context: [
                'file'     => __FILE__,
                'line'     => __LINE__,
                'objectId' => $object->getUuid(),
                'reason'   => $reason,
                'placedBy' => $userId,
            ]
        );

        return $object;
    }//end placeHold()

    /**
     * Release a legal hold on an object.
     *
     * @param ObjectEntity $object The object to release the hold from.
     * @param string       $reason The reason for releasing the hold.
     *
     * @return ObjectEntity The updated object with legal hold released.
     *
     * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-5
     * @spec openspec/changes/retrofit-2026-04-30-annotate-openregister/tasks.md#task-8
     */
    public function releaseHold(ObjectEntity $object, string $reason): ObjectEntity
    {
        $userId    = $this->getCurrentUserId();
        $retention = $object->getRetention() ?? [];
        $legalHold = $retention['legalHold'] ?? [];

        // Preserve the current hold in history.
        $history   = $legalHold['history'] ?? [];
        $history[] = [
            'active'        => true,
            'reason'        => $legalHold['reason'] ?? 'unknown',
            'placedBy'      => $legalHold['placedBy'] ?? 'unknown',
            'placedDate'    => $legalHold['placedDate'] ?? null,
            'releasedBy'    => $userId,
            'releasedDate'  => (new DateTime())->format('c'),
            'releaseReason' => $reason,
        ];

        $retention['legalHold'] = [
            'active'     => false,
            'reason'     => $legalHold['reason'] ?? null,
            'placedBy'   => $legalHold['placedBy'] ?? null,
            'placedDate' => $legalHold['placedDate'] ?? null,
            'history'    => $history,
        ];

        $object->setRetention($retention);
        $this->objectMapper->update($object);

        $this->logger->info(
            message: '[LegalHoldService] Legal hold released on object',
            context: [
                'file'          => __FILE__,
                'line'          => __LINE__,
                'objectId'      => $object->getUuid(),
                'releaseReason' => $reason,
                'releasedBy'    => $userId,
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
     * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-5
     */
    public function hasActiveHold(ObjectEntity $object): bool
    {
        $retention = $object->getRetention() ?? [];
        $legalHold = $retention['legalHold'] ?? [];

        return ($legalHold['active'] ?? false) === true;
    }//end hasActiveHold()

    /**
     * Check if an object has an active legal hold using its retention array directly.
     *
     * @param array<string, mixed> $retention The object's retention data.
     *
     * @return bool True if the retention data indicates an active legal hold.
     *
     * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-5
     */
    public function hasActiveHoldFromRetention(array $retention): bool
    {
        $legalHold = $retention['legalHold'] ?? [];

        return ($legalHold['active'] ?? false) === true;
    }//end hasActiveHoldFromRetention()

    /**
     * Schedule a bulk legal hold operation on all objects in a schema.
     *
     * @param int    $schemaId   The schema ID to apply holds to.
     * @param int    $registerId The register ID.
     * @param string $reason     The reason for the bulk legal hold.
     *
     * @return void
     *
     * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-5
     * @spec openspec/changes/retrofit-2026-04-30-annotate-openregister/tasks.md#task-8
     */
    public function bulkPlaceHold(int $schemaId, int $registerId, string $reason): void
    {
        $userId = $this->getCurrentUserId();

        $this->jobList->add(
            \OCA\OpenRegister\BackgroundJob\BulkLegalHoldJob::class,
            [
                'schemaId'   => $schemaId,
                'registerId' => $registerId,
                'reason'     => $reason,
                'placedBy'   => $userId,
            ]
        );

        $this->logger->info(
            message: '[LegalHoldService] Bulk legal hold job queued',
            context: [
                'file'       => __FILE__,
                'line'       => __LINE__,
                'schemaId'   => $schemaId,
                'registerId' => $registerId,
                'reason'     => $reason,
                'placedBy'   => $userId,
            ]
        );
    }//end bulkPlaceHold()

    /**
     * Get the current authenticated user ID.
     *
     * @return string The user ID or 'system' if no user is authenticated.
     *
     * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-5
     */
    private function getCurrentUserId(): string
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return 'system';
        }

        return $user->getUID();
    }//end getCurrentUserId()
}//end class
