<?php
/**
 * OpenRegister SingleObjectResponse Class
 *
 * Response class for single object operations with support for
 * relations and logs retrieval.
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

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectHandlers\GetObject;

/**
 * Response class for single object operations.
 */
class SingleObjectResponse extends ObjectResponse
{
    private GetObject $getHandler;
    private ObjectEntity $object;

    /**
     * Constructor.
     *
     * @param ObjectEntity $object     The object entity
     * @param GetObject   $getHandler The get handler for fetching related data
     */
    public function __construct(ObjectEntity $object, GetObject $getHandler)
    {
        parent::__construct($object);
        $this->object = $object;
        $this->getHandler = $getHandler;
    }

    /**
     * Get related objects.
     *
     * @return ObjectResponse
     */
    public function getRelations(): ObjectResponse
    {
        // Implementation for fetching related objects
        $relations = $this->getHandler->findRelated($this->object);
        return new ObjectResponse($relations);
    }

    /**
     * Get object logs.
     *
     * @return ObjectResponse
     */
    public function getLogs(): ObjectResponse
    {
        // Implementation for fetching object logs
        $logs = $this->getHandler->findLogs($this->object);
        return new ObjectResponse($logs);
    }

    /**
     * Get the object entity.
     *
     * @return ObjectEntity
     */
    public function getObject(): ObjectEntity
    {
        return $this->object;
    }
} 