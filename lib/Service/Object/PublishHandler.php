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
     * @param LoggerInterface    $logger             PSR-3 logger
     */
    public function __construct(
        private readonly ObjectEntityMapper $objectEntityMapper,
        private readonly LoggerInterface $logger
    ) {

    }//end __construct()


    /**
     * Publish an object
     *
     * Sets the publication date to make the object publicly available.
     * If no date is provided, uses current date/time.
     *
     * @param string        $uuid         Object UUID
     * @param DateTime|null $date         Optional publication date (null = now)
     * @param bool          $rbac         Apply RBAC filters
     * @param bool          $multitenancy Apply multitenancy filters
     *
     * @return ObjectEntity Published object
     *
     * @throws \Exception If publish operation fails
     */
    public function publish(
        string $uuid,
        ?DateTime $date=null,
        bool $rbac=true,
        bool $multitenancy=true
    ): ObjectEntity {
        $this->logger->debug(
            message: '[PublishHandler] Publishing object',
            context: [
                'uuid'         => $uuid,
                'date'         => $date?->format('Y-m-d H:i:s'),
                'rbac'         => $rbac,
                'multitenancy' => $multitenancy,
            ]
        );

        try {
            // Fetch object.
            $object = $this->objectEntityMapper->find(
                $uuid,
                _rbac: $rbac,
                _multitenancy: $multitenancy
            );

            // Set publication date (now if not provided).
            $publicationDate = $date ?? new DateTime();
            $object->setPublicationDate($publicationDate);

            // Clear depublication date if set.
            $object->setDepublicationDate(null);

            // Save object.
            $object = $this->objectEntityMapper->update(entity: $object);

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
     * @param string        $uuid         Object UUID
     * @param DateTime|null $date         Optional depublication date (null = now)
     * @param bool          $rbac         Apply RBAC filters
     * @param bool          $multitenancy Apply multitenancy filters
     *
     * @return ObjectEntity Depublished object
     *
     * @throws \Exception If depublish operation fails
     */
    public function depublish(
        string $uuid,
        ?DateTime $date=null,
        bool $rbac=true,
        bool $multitenancy=true
    ): ObjectEntity {
        $this->logger->debug(
            message: '[PublishHandler] Depublishing object',
            context: [
                'uuid'         => $uuid,
                'date'         => $date?->format('Y-m-d H:i:s'),
                'rbac'         => $rbac,
                'multitenancy' => $multitenancy,
            ]
        );

        try {
            // Fetch object.
            $object = $this->objectEntityMapper->find(
                $uuid,
                _rbac: $rbac,
                _multitenancy: $multitenancy
            );

            // Set depublication date (now if not provided).
            $depublicationDate = $date ?? new DateTime();
            $object->setDepublicationDate($depublicationDate);

            // Save object.
            $object = $this->objectEntityMapper->update(entity: $object);

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
     * @return array Publication status information
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
