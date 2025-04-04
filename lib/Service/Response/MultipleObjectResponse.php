<?php
/**
 * OpenRegister MultipleObjectResponse Class
 *
 * Response class for multiple object operations with support for
 * pagination and bulk downloads.
 *
 * @category Response
 * @package  OCA\OpenRegister\Service\Response
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version   1.0.0
 *
 * @link      https://OpenRegister.app
 */

namespace OCA\OpenRegister\Service\Response;

use OCA\OpenRegister\Service\ObjectHandlers\GetObject;

/**
 * Response class for multiple object operations.
 */
class MultipleObjectResponse extends ObjectResponse
{
    private GetObject $getHandler;

    /**
     * Constructor.
     *
     * @param array     $objects    The array of objects
     * @param GetObject $getHandler The get handler for fetching related data
     */
    public function __construct(array $objects, GetObject $getHandler)
    {
        parent::__construct($objects);
        $this->getHandler = $getHandler;
    }

    /**
     * Get related objects for all objects in the collection.
     *
     * @return ObjectResponse
     */
    public function getRelations(): ObjectResponse
    {
        $allRelations = [];
        foreach ($this->data as $object) {
            $relations = $this->getHandler->findRelated($object);
            $allRelations = array_merge($allRelations, $relations);
        }
        return new ObjectResponse($allRelations);
    }

    /**
     * Get logs for all objects in the collection.
     *
     * @return ObjectResponse
     */
    public function getLogs(): ObjectResponse
    {
        $allLogs = [];
        foreach ($this->data as $object) {
            $logs = $this->getHandler->findLogs($object);
            $allLogs = array_merge($allLogs, $logs);
        }
        return new ObjectResponse($allLogs);
    }
} 