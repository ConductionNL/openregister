<?php

/**
 * OpenRegister Transfer List Service
 *
 * Manages transfer lists for e-Depot overbrenging workflow.
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
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Edepot;

use DateTime;
use InvalidArgumentException;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCP\IAppConfig;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Service for managing e-Depot transfer lists.
 *
 * Transfer lists track which objects are pending, approved, or completed for
 * e-Depot transfer. They follow the same review-approve pattern as destruction lists.
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class TransferListService
{

    /**
     * Transfer list status constants.
     */
    public const STATUS_IN_REVIEW        = 'in_review';
    public const STATUS_APPROVED         = 'approved';
    public const STATUS_REJECTED         = 'rejected';
    public const STATUS_IN_PROGRESS      = 'in_progress';
    public const STATUS_COMPLETED        = 'completed';
    public const STATUS_PARTIALLY_FAILED = 'partially_failed';
    public const STATUS_FAILED           = 'failed';

    /**
     * Constructor.
     *
     * @param ObjectEntityMapper   $objectMapper        The object mapper.
     * @param AuditTrailMapper     $auditTrailMapper    The audit trail mapper.
     * @param IAppConfig           $appConfig           The app configuration.
     * @param INotificationManager $notificationManager The notification manager.
     * @param LoggerInterface      $logger              Logger.
     */
    public function __construct(
        private readonly ObjectEntityMapper $objectMapper,
        private readonly AuditTrailMapper $auditTrailMapper,
        private readonly IAppConfig $appConfig,
        private readonly INotificationManager $notificationManager,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

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
     */
    public function createTransferList(array $objects): array
    {
        if (empty($objects) === true) {
            throw new InvalidArgumentException('No objects provided for transfer list');
        }

        $objectReferences = [];
        foreach ($objects as $object) {
            $objectReferences[] = [
                'uuid'     => $object->getUuid(),
                'schema'   => $object->getSchema(),
                'register' => $object->getRegister(),
            ];
        }

        $transferList = [
            'uuid'             => (string) Uuid::v4(),
            'status'           => self::STATUS_IN_REVIEW,
            'objectReferences' => $objectReferences,
            'exclusions'       => [],
            'approvalMetadata' => null,
            'transferResult'   => null,
            'createdAt'        => (new DateTime())->format('c'),
            'objectCount'      => count($objectReferences),
        ];

        $this->logger->info(
            message: '[TransferListService] Created transfer list',
            context: [
                'uuid'        => $transferList['uuid'],
                'objectCount' => $transferList['objectCount'],
            ]
        );

        return $transferList;
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
     */
    public function approveTransferList(array $transferList, string $archivistId): array
    {
        if ($transferList['status'] !== self::STATUS_IN_REVIEW) {
            throw new InvalidArgumentException(
                "Cannot approve transfer list with status '{$transferList['status']}'; expected 'in_review'"
            );
        }

        $transferList['status']           = self::STATUS_APPROVED;
        $transferList['approvalMetadata'] = [
            'approvedBy' => $archivistId,
            'approvedAt' => (new DateTime())->format('c'),
        ];

        $this->logger->info(
            message: '[TransferListService] Transfer list approved',
            context: [
                'uuid'       => $transferList['uuid'],
                'approvedBy' => $archivistId,
            ]
        );

        return $transferList;
    }//end approveTransferList()

    /**
     * Reject a transfer list.
     *
     * @param array<string,mixed> $transferList The transfer list data.
     * @param string              $archivistId  The rejecting archivist user ID.
     * @param string              $reason       The reason for rejection.
     *
     * @return array<string,mixed> The updated transfer list data.
     *
     * @throws InvalidArgumentException If the list is not in review status.
     */
    public function rejectTransferList(array $transferList, string $archivistId, string $reason): array
    {
        if ($transferList['status'] !== self::STATUS_IN_REVIEW) {
            throw new InvalidArgumentException(
                "Cannot reject transfer list with status '{$transferList['status']}'; expected 'in_review'"
            );
        }

        if (empty($reason) === true) {
            throw new InvalidArgumentException('Rejection reason is required');
        }

        $transferList['status']           = self::STATUS_REJECTED;
        $transferList['approvalMetadata'] = [
            'rejectedBy'      => $archivistId,
            'rejectedAt'      => (new DateTime())->format('c'),
            'rejectionReason' => $reason,
        ];

        $this->logger->info(
            message: '[TransferListService] Transfer list rejected',
            context: [
                'uuid'       => $transferList['uuid'],
                'rejectedBy' => $archivistId,
                'reason'     => $reason,
            ]
        );

        return $transferList;
    }//end rejectTransferList()

    /**
     * Exclude objects from a transfer list.
     *
     * @param array<string,mixed> $transferList The transfer list data.
     * @param array<int, string>  $objectUuids  UUIDs of objects to exclude.
     * @param string              $reason       The reason for exclusion.
     *
     * @return array<string,mixed> The updated transfer list data.
     */
    public function excludeObjects(array $transferList, array $objectUuids, string $reason): array
    {
        if ($transferList['status'] !== self::STATUS_IN_REVIEW) {
            throw new InvalidArgumentException(
                "Cannot modify transfer list with status '{$transferList['status']}'"
            );
        }

        $excluded = ($transferList['exclusions'] ?? []);

        foreach ($objectUuids as $uuid) {
            $excluded[] = [
                'uuid'       => $uuid,
                'reason'     => $reason,
                'excludedAt' => (new DateTime())->format('c'),
            ];
        }

        $transferList['exclusions'] = $excluded;

        // Remove excluded UUIDs from the objectReferences.
        $transferList['objectReferences'] = array_values(
            array_filter(
                $transferList['objectReferences'],
                static function (array $ref) use ($objectUuids): bool {
                    return in_array($ref['uuid'], $objectUuids, true) === false;
                }
            )
        );

        $transferList['objectCount'] = count($transferList['objectReferences']);

        $this->logger->info(
            message: '[TransferListService] Objects excluded from transfer list',
            context: [
                'uuid'          => $transferList['uuid'],
                'excludedCount' => count($objectUuids),
                'reason'        => $reason,
            ]
        );

        return $transferList;
    }//end excludeObjects()

    /**
     * Get UUIDs of objects currently on active transfer lists.
     *
     * This retrieves UUIDs from transfer lists with status 'in_review' or 'approved'
     * to prevent duplicate inclusion in new transfer lists.
     *
     * @param array<int, array<string,mixed>> $activeTransferLists Active transfer list data.
     *
     * @return array<int, string> UUIDs of objects on active transfer lists.
     */
    public function getObjectsOnActiveTransferLists(array $activeTransferLists): array
    {
        $uuids = [];

        foreach ($activeTransferLists as $list) {
            $status = ($list['status'] ?? '');
            if ($status === self::STATUS_IN_REVIEW || $status === self::STATUS_APPROVED) {
                foreach (($list['objectReferences'] ?? []) as $ref) {
                    if (empty($ref['uuid']) === false) {
                        $uuids[] = $ref['uuid'];
                    }
                }
            }
        }

        return array_unique($uuids);
    }//end getObjectsOnActiveTransferLists()

    /**
     * Send notification to archivist users about a new transfer list.
     *
     * @param array<string,mixed> $transferList The transfer list data.
     *
     * @return void
     */
    public function notifyArchivists(array $transferList): void
    {
        try {
            $notification = $this->notificationManager->createNotification();
            $notification->setApp('openregister');
            $notification->setUser('admin');
            $notification->setDateTime(new DateTime());
            $notification->setObject('transfer_list', $transferList['uuid']);
            $notification->setSubject(
                    'edepot_transfer_list_created',
                    [
                        'uuid'        => $transferList['uuid'],
                        'objectCount' => $transferList['objectCount'],
                    ]
                    );
            $this->notificationManager->notify($notification);

            $this->logger->info(
                message: '[TransferListService] Notification sent for transfer list',
                context: ['uuid' => $transferList['uuid']]
            );
        } catch (\Exception $e) {
            $this->logger->warning(
                message: '[TransferListService] Failed to send notification',
                context: [
                    'uuid'  => $transferList['uuid'],
                    'error' => $e->getMessage(),
                ]
            );
        }//end try
    }//end notifyArchivists()
}//end class
