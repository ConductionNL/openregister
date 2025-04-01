<?php
/**
 * OpenRegister MongoDbService
 *
 * Service class for handling MongoDB operations in the OpenRegister application.
 *
 * This service provides methods for:
 * - CRUD operations on objects
 * - Aggregation operations on objects
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

namespace OCA\OpenRegister\Service;

use Adbar\Dot;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use Symfony\Component\Uid\Uuid;

/**
 * Service class for handling MongoDB operations
 *
 * This class provides methods for interacting with MongoDB through a REST API,
 * including CRUD operations, aggregations, and search functionality.
 * It handles configuration, connection management and data transformation.
 */
class MongoDbService
{
    /**
     * Default base configuration for MongoDB operations
     *
     * @var array
     */
    public const BASE_OBJECT = [
        'database'   => 'objects',
    // The default database name
        'collection' => 'json',
    // The default collection name
    ];


    /**
     * Gets a configured Guzzle HTTP client
     *
     * @param  array $config Configuration array containing connection details
     * @return Client Configured Guzzle client instance
     */
    public function getClient(array $config): Client
    {
        // Remove MongoDB specific config before creating Guzzle client
        $guzzleConf = $config;
        unset($guzzleConf['mongodbCluster']);

        return new Client($config);

    }//end getClient()


    /**
     * Save an object to MongoDB
     *
     * @param  array $data   The data object to be saved
     * @param  array $config MongoDB connection configuration
     * @return array The saved object with generated ID
     * @throws \GuzzleHttp\Exception\GuzzleException When API request fails
     */
    public function saveObject(array $data, array $config): array
    {
        // Initialize HTTP client
        $client = $this->getClient(config: $config);

        // Prepare object with base configuration and data
        $object = self::BASE_OBJECT;
        $object['dataSource'] = $config['mongodbCluster'];
        $object['document']   = $data;
        // Generate and set UUID for new document
        $object['document']['id'] = $object['document']['_id'] = Uuid::v4();

        // Insert document via API
        $result     = $client->post(
            uri: 'action/insertOne',
            options: ['json' => $object],
        );
        $resultData = json_decode(
            json: $result->getBody()->getContents(),
            associative: true
        );
        $id         = $resultData['insertedId'];

        // Return complete object by finding it with new ID
        return $this->findObject(filters: ['_id' => $id], config: $config);

    }//end saveObject()


    /**
     * Find multiple objects matching given filters
     *
     * @param  array $filters Query filters to match documents
     * @param  array $config  MongoDB connection configuration
     * @return array Array of matching documents
     * @throws \GuzzleHttp\Exception\GuzzleException When API request fails
     */
    public function findObjects(array $filters, array $config): array
    {
        $client = $this->getClient(config: $config);

        // Prepare query object
        $object = self::BASE_OBJECT;
        $object['dataSource'] = $config['mongodbCluster'];
        $object['filter']     = $filters;

        // @todo Fix mongodb sort
        // if (empty($sort) === false) {
        // $object['filter'][] = ['$sort' => $sort];
        // }        // Execute find query via API
        $returnData = $client->post(
            uri: 'action/find',
            options: ['json' => $object]
        );

        return json_decode(
            json: $returnData->getBody()->getContents(),
            associative: true
        );

    }//end findObjects()


    /**
     * Find a single object matching given filters
     *
     * @param  array $filters Query filters to match document
     * @param  array $config  MongoDB connection configuration
     * @return array The matched document
     * @throws \GuzzleHttp\Exception\GuzzleException When API request fails
     */
    public function findObject(array $filters, array $config): array
    {
        $client = $this->getClient(config: $config);

        // Prepare query object
        $object           = self::BASE_OBJECT;
        $object['filter'] = $filters;
        $object['dataSource'] = $config['mongodbCluster'];

        // Execute findOne query via API
        $returnData = $client->post(
            uri: 'action/findOne',
            options: ['json' => $object]
        );

        $result = json_decode(
            json: $returnData->getBody()->getContents(),
            associative: true
        );

        return $result['document'];

    }//end findObject()


    /**
     * Update an existing object in MongoDB
     *
     * @param  array $filters Query filters to match document for update
     * @param  array $update  Update operations to apply
     * @param  array $config  MongoDB connection configuration
     * @return array The updated document
     * @throws \GuzzleHttp\Exception\GuzzleException When API request fails
     */
    public function updateObject(array $filters, array $update, array $config): array
    {
        $client = $this->getClient(config: $config);

        // Convert update data to dot notation for nested updates
        $dotUpdate = new Dot($update);

        // Prepare update query
        $object           = self::BASE_OBJECT;
        $object['filter'] = $filters;
        $object['update']['$set'] = $update;
        $object['upsert']         = true;
        $object['dataSource']     = $config['mongodbCluster'];

        // Execute update via API
        $returnData = $client->post(
            uri: 'action/updateOne',
            options: ['json' => $object]
        );

        // Return updated document
        return $this->findObject($filters, $config);

    }//end updateObject()


    /**
     * Delete an object from MongoDB
     *
     * @param  array $filters Query filters to match document for deletion
     * @param  array $config  MongoDB connection configuration
     * @return array Empty array on successful deletion
     * @throws \GuzzleHttp\Exception\GuzzleException When API request fails
     */
    public function deleteObject(array $filters, array $config): array
    {
        $client = $this->getClient(config: $config);

        // Prepare delete query
        $object           = self::BASE_OBJECT;
        $object['filter'] = $filters;
        $object['dataSource'] = $config['mongodbCluster'];

        // Execute deletion via API
        $returnData = $client->post(
            uri: 'action/deleteOne',
            options: ['json' => $object]
        );

        return [];

    }//end deleteObject()


    /**
     * Perform aggregation operations on MongoDB collection
     *
     * @param  array $filters  Initial query filters
     * @param  array $pipeline Aggregation pipeline stages
     * @param  array $config   MongoDB connection configuration
     * @return array Aggregation results
     * @throws \GuzzleHttp\Exception\GuzzleException When API request fails
     */
    public function aggregateObjects(array $filters, array $pipeline, array $config):array
    {
        $client = $this->getClient(config: $config);

        // Prepare aggregation query
        $object           = self::BASE_OBJECT;
        $object['filter'] = $filters;
        $object['pipeline']   = $pipeline;
        $object['dataSource'] = $config['mongodbCluster'];

        // Execute aggregation via API
        $returnData = $client->post(
            uri: 'action/aggregate',
            options: ['json' => $object]
        );

        return json_decode(
            json: $returnData->getBody()->getContents(),
            associative: true
        );

    }//end aggregateObjects()


}//end class
