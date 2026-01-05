<?php

/**
 * Publish Handler
 *
 * Handles object publishing and depublishing operations.
 * Controls object visibility and availability in the publication workflow.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Objects\Handlers
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use Psr\Log\LoggerInterface;
use DateTime;

/**
 * PublishHandler
 *
 * Responsible for managing object publication state.
 *
 * RESPONSIBILITIES:
 * - Publish objects with optional publication date
 * - Depublish objects with optional depublication date
 * - Check publication status
 * - Validate publication permissions
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Objects\Handlers
 */
class PublishHandler
{
    /**
     * Constructor
     *
     * @param ObjectEntityMapper $objectEntityMapper Object entity mapper
     * @param AuditTrailMapper   $auditTrailMapper   Audit trail mapper for logging actions
     * @param LoggerInterface    $logger             PSR-3 logger
     */
    public function __construct(
        private readonly ObjectEntityMapper $objectEntityMapper,
        private readonly AuditTrailMapper $auditTrailMapper,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Publish an object
     *
     * Sets the publication date to make the object publicly available.
     * If no date is provided, uses current date/time.
     *
     * @param string        $uuid          Object UUID
     * @param DateTime|null $date          Optional publication date (null = now)
     * @param bool          $_rbac         Apply RBAC filters
     * @param bool          $_multitenancy Apply multitenancy filters
     *
     * @return ObjectEntity Published object
     *
     * @throws \Exception If publish operation fails
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) RBAC/multitenancy flags follow established API patterns
     */
    public function publish(
        string $uuid,
        ?DateTime $date=null,
        bool $_rbac=true,
        bool $_multitenancy=true
    ): ObjectEntity {
        $this->logger->debug(
            message: '[PublishHandler] Publishing object',
            context: [
                'uuid'         => $uuid,
                'date'         => $date?->format('Y-m-d H:i:s'),
                'rbac'         => $_rbac,
                'multitenancy' => $_multitenancy,
            ]
        );

        try {
            // Fetch object before modification.
            $objectBefore = $this->objectEntityMapper->find(
                $uuid,
                _rbac: $_rbac,
                _multitenancy: $_multitenancy
            );

            // Clone the object to preserve the old state.
            $objectBeforeClone = clone $objectBefore;

            // Set publication date (now if not provided).
            $publicationDate = $date ?? new DateTime();
            $objectBefore->setPublished($publicationDate);

            // Clear depublication date if set.
            $objectBefore->setDepublished(null);

            // Save object.
            $object = $this->objectEntityMapper->update(entity: $objectBefore);

            // Record publish action in audit trail (with before/after states).
            try {
                $this->logger->debug('[PublishHandler] About to create audit trail for publish action');
                $auditTrail = $this->auditTrailMapper->createAuditTrail(
                    old: $objectBeforeClone,
                    new: $object,
                    action: 'publish'
                );
                $this->logger->debug('[PublishHandler] Audit trail created: '.$auditTrail->getId());
            } catch (\Exception $auditError) {
                $this->logger->warning('[PublishHandler] Failed to create audit trail: '.$auditError->getMessage());
            }

            $this->logger->info(
                message: '[PublishHandler] Object published successfully',
                context: [
                    'uuid'             => $uuid,
                    'publication_date' => $publicationDate->format('Y-m-d H:i:s'),
                ]
            );

            return $object;
        } catch (\Exception $e) {
            $this->logger->error(
                message: '[PublishHandler] Failed to publish object',
                context: [
                    'uuid'  => $uuid,
                    'error' => $e->getMessage(),
                ]
            );
            throw $e;
        }//end try
    }//end publish()

    /**
     * Depublish an object
     *
     * Sets the depublication date to make the object unavailable.
     * If no date is provided, uses current date/time.
     *
     * @param string        $uuid          Object UUID
     * @param DateTime|null $date          Optional depublication date (null = now)
     * @param bool          $_rbac         Apply RBAC filters
     * @param bool          $_multitenancy Apply multitenancy filters
     *
     * @return ObjectEntity Depublished object
     *
     * @throws \Exception If depublish operation fails
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) RBAC/multitenancy flags follow established API patterns
     */
    public function depublish(
        string $uuid,
        ?DateTime $date=null,
        bool $_rbac=true,
        bool $_multitenancy=true
    ): ObjectEntity {
        $this->logger->debug(
            message: '[PublishHandler] Depublishing object',
            context: [
                'uuid'         => $uuid,
                'date'         => $date?->format('Y-m-d H:i:s'),
                'rbac'         => $_rbac,
                'multitenancy' => $_multitenancy,
            ]
        );

        try {
            // Fetch object before modification.
            $objectBefore = $this->objectEntityMapper->find(
                $uuid,
                _rbac: $_rbac,
                _multitenancy: $_multitenancy
            );

            // Clone the object to preserve the old state.
            $objectBeforeClone = clone $objectBefore;

            // Set depublication date (now if not provided).
            $depublicationDate = $date ?? new DateTime();
            $objectBefore->setDepublished($depublicationDate);

            // Clear publication date if set.
            $objectBefore->setPublished(null);
            $object = $this->objectEntityMapper->update(entity: $objectBefore);

            // Record depublish action in audit trail (with before/after states).
            $this->auditTrailMapper->createAuditTrail(old: $objectBeforeClone, new: $object, action: 'depublish');

            $this->logger->info(
                message: '[PublishHandler] Object depublished successfully',
                context: [
                    'uuid'               => $uuid,
                    'depublication_date' => $depublicationDate->format('Y-m-d H:i:s'),
                ]
            );

            return $object;
        } catch (\Exception $e) {
            $this->logger->error(
                message: '[PublishHandler] Failed to depublish object',
                context: [
                    'uuid'  => $uuid,
                    'error' => $e->getMessage(),
                ]
            );
            throw $e;
        }//end try
    }//end depublish()

    /**
     * Check if object is published
     *
     * An object is published if:
     * - It has a publication_date that is in the past
     * - It has no depublication_date OR depublication_date is in the future
     *
     * @param ObjectEntity $object Object to check
     *
     * @return bool True if published, false otherwise
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Publication status requires checking multiple date conditions
     */
    public function isPublished(ObjectEntity $object): bool
    {
        $now = new DateTime();
        $publicationDate   = $object->getPublicationDate();
        $depublicationDate = $object->getDepublicationDate();

        // Not published if no publication date.
        if ($publicationDate === null) {
            return false;
        }

        // Convert to DateTime if string.
        if (is_string($publicationDate) === true) {
            $publicationDate = new DateTime($publicationDate);
        }

        // Publication date must be in the past.
        if ($publicationDate > $now) {
            return false;
        }

        // Check depublication date if set.
        if ($depublicationDate !== null) {
            if (is_string($depublicationDate) === true) {
                $depublicationDate = new DateTime($depublicationDate);
            }

            // Depublished if depublication date is in the past.
            if ($depublicationDate <= $now) {
                return false;
            }
        }

        return true;
    }//end isPublished()

    /**
     * Get publication status information
     *
     * Returns detailed information about publication status.
     *
     * @param ObjectEntity $object Object to check
     *
     * @return (bool|mixed|null)[] Publication status information
     *
     * @psalm-return array{is_published: bool, publication_date: mixed|null,
     *     depublication_date: mixed|null, publication_scheduled: bool,
     *     depublication_scheduled: bool}
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Multiple date conversions and scheduling checks
     */
    public function getPublicationStatus(ObjectEntity $object): array
    {
        $now = new DateTime();
        $publicationDate   = $object->getPublicationDate();
        $depublicationDate = $object->getDepublicationDate();

        $status = [
            'is_published'            => $this->isPublished($object),
            'publication_date'        => $publicationDate?->format('Y-m-d H:i:s'),
            'depublication_date'      => $depublicationDate?->format('Y-m-d H:i:s'),
            'publication_scheduled'   => false,
            'depublication_scheduled' => false,
        ];

        // Check if publication is scheduled for future.
        if ($publicationDate !== null) {
            if (is_string($publicationDate) === true) {
                $publicationDate = new DateTime($publicationDate);
            }

            $status['publication_scheduled'] = $publicationDate > $now;
        }

        // Check if depublication is scheduled for future.
        if ($depublicationDate !== null) {
            if (is_string($depublicationDate) === true) {
                $depublicationDate = new DateTime($depublicationDate);
            }

            $status['depublication_scheduled'] = $depublicationDate > $now;
        }

        return $status;
    }//end getPublicationStatus()
}//end class
