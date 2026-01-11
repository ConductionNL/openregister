<?php

/**
 * SolrSchemaManager
 *
 * Manages Solr schema operations.
 * Handles field types, field management, and schema synchronization.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\Index\Backends\Solr
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Index\Backends\Solr;

use Exception;
use Psr\Log\LoggerInterface;

/**
 * SolrSchemaManager
 *
 * Manages Solr schema and fields.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Index\Backends\Solr
 */
class SolrSchemaManager
{

    /**
     * HTTP client.
     *
     * @var SolrHttpClient
     */
    private readonly SolrHttpClient $httpClient;

    /**
     * Collection manager.
     *
     * @var SolrCollectionManager
     */
    private readonly SolrCollectionManager $collectionManager;

    /**
     * Logger.
     *
     * @var LoggerInterface
     */
    private readonly LoggerInterface $logger;

    /**
     * Constructor
     *
     * @param SolrHttpClient        $httpClient        HTTP client
     * @param SolrCollectionManager $collectionManager Collection manager
     * @param LoggerInterface       $logger            Logger
     *
     * @return void
     */
    public function __construct(
        SolrHttpClient $httpClient,
        SolrCollectionManager $collectionManager,
        LoggerInterface $logger
    ) {
        $this->httpClient        = $httpClient;
        $this->collectionManager = $collectionManager;
        $this->logger            = $logger;
    }//end __construct()

    /**
     * Get field types for a collection.
     *
     * @param string $collection Collection name
     *
     * @return array Field types indexed by name
     */
    public function getFieldTypes(string $collection): array
    {
        try {
            $url  = $this->httpClient->getEndpointUrl($collection).'/schema/fieldtypes?wt=json';
            $data = $this->httpClient->get($url);

            $fieldTypes = [];
            foreach (($data['fieldTypes'] ?? []) as $fieldType) {
                $name = $fieldType['name'] ?? null;
                if ($name !== null) {
                    $fieldTypes[$name] = $fieldType;
                }
            }

            $this->logger->debug(
                '[SolrSchemaManager] Retrieved field types',
                [
                    'collection' => $collection,
                    'count'      => count($fieldTypes),
                ]
            );

            return $fieldTypes;
        } catch (Exception $e) {
            $this->logger->error(
                '[SolrSchemaManager] Failed to get field types',
                [
                    'collection' => $collection,
                    'error'      => $e->getMessage(),
                ]
            );
            return [];
        }//end try
    }//end getFieldTypes()

    /**
     * Add a field type to a collection.
     *
     * @param string $collection Collection name
     * @param array  $fieldType  Field type definition
     *
     * @return bool True if successful
     */
    public function addFieldType(string $collection, array $fieldType): bool
    {
        try {
            $this->logger->info(
                '[SolrSchemaManager] Adding field type',
                [
                    'collection' => $collection,
                    'name'       => $fieldType['name'] ?? 'unknown',
                ]
            );

            $url = $this->httpClient->getEndpointUrl($collection).'/schema';

            $command = ['add-field-type' => $fieldType];

            $result = $this->httpClient->post($url, $command);

            if (($result['responseHeader']['status'] ?? -1) === 0) {
                $this->logger->info('[SolrSchemaManager] Field type added successfully');
                return true;
            }

            $this->logger->warning(
                '[SolrSchemaManager] Field type addition returned non-zero status',
                [
                    'status' => $result['responseHeader']['status'] ?? 'unknown',
                ]
            );

            return false;
        } catch (Exception $e) {
            $this->logger->error(
                '[SolrSchemaManager] Failed to add field type',
                [
                    'error' => $e->getMessage(),
                ]
            );
            return false;
        }//end try
    }//end addFieldType()

    /**
     * Get fields for a collection.
     *
     * @param string $collection Collection name
     *
     * @return array Fields indexed by name
     */
    public function getFields(string $collection): array
    {
        try {
            $url  = $this->httpClient->getEndpointUrl($collection).'/schema/fields?wt=json';
            $data = $this->httpClient->get($url);

            $fields = [];
            foreach (($data['fields'] ?? []) as $field) {
                $name = $field['name'] ?? null;
                if ($name !== null) {
                    $fields[$name] = $field;
                }
            }

            $this->logger->debug(
                '[SolrSchemaManager] Retrieved fields',
                [
                    'collection' => $collection,
                    'count'      => count($fields),
                ]
            );

            return $fields;
        } catch (Exception $e) {
            $this->logger->error(
                '[SolrSchemaManager] Failed to get fields',
                [
                    'collection' => $collection,
                    'error'      => $e->getMessage(),
                ]
            );
            return [];
        }//end try
    }//end getFields()

    /**
     * Add or update a field in a collection.
     *
     * @param array $fieldConfig Field configuration
     * @param bool  $force       Force update if exists
     *
     * @return string Action taken ('created', 'updated', 'skipped', 'failed')
     */
    public function addOrUpdateField(array $fieldConfig, bool $force): string
    {
        $collection = $this->collectionManager->getActiveCollectionName();

        if ($collection === null) {
            $this->logger->warning('[SolrSchemaManager] No active collection for field operation');
            return 'failed';
        }

        try {
            $fieldName = $fieldConfig['name'] ?? null;

            if ($fieldName === null) {
                $this->logger->warning('[SolrSchemaManager] Field name not provided');
                return 'failed';
            }

            // Check if field exists.
            $existingFields = $this->getFields($collection);

            if (isset($existingFields[$fieldName]) === true) {
                if ($force === false) {
                    $this->logger->debug(
                        '[SolrSchemaManager] Field already exists, skipping',
                        [
                            'field' => $fieldName,
                        ]
                    );
                    return 'skipped';
                }

                // Delete and recreate.
                $this->deleteField($collection, $fieldName);
            }

            // Add field.
            $url = $this->httpClient->getEndpointUrl($collection).'/schema';

            $command = ['add-field' => $fieldConfig];

            $result = $this->httpClient->post($url, $command);

            if (($result['responseHeader']['status'] ?? -1) === 0) {
                $this->logger->info('[SolrSchemaManager] Field created', ['field' => $fieldName]);
                if (isset($existingFields[$fieldName]) === true) {
                    return 'updated';
                }

                return 'created';
            }

            $this->logger->warning(
                '[SolrSchemaManager] Field creation returned non-zero status',
                [
                    'field'  => $fieldName,
                    'status' => $result['responseHeader']['status'] ?? 'unknown',
                ]
            );

            return 'failed';
        } catch (Exception $e) {
            $this->logger->error(
                '[SolrSchemaManager] Failed to add/update field',
                [
                    'error' => $e->getMessage(),
                ]
            );
            return 'failed';
        }//end try
    }//end addOrUpdateField()

    /**
     * Delete a field from a collection.
     *
     * @param string $collection Collection name
     * @param string $fieldName  Field name
     *
     * @psalm-suppress UnusedReturnValue
     *
     * @return bool True if successful
     */
    private function deleteField(string $collection, string $fieldName): bool
    {
        try {
            $this->logger->info(
                '[SolrSchemaManager] Deleting field',
                [
                    'collection' => $collection,
                    'field'      => $fieldName,
                ]
            );

            $url = $this->httpClient->getEndpointUrl($collection).'/schema';

            $command = ['delete-field' => ['name' => $fieldName]];

            $result = $this->httpClient->post($url, $command);

            if (($result['responseHeader']['status'] ?? -1) === 0) {
                $this->logger->info('[SolrSchemaManager] Field deleted');
                return true;
            }

            return false;
        } catch (Exception $e) {
            $this->logger->error(
                '[SolrSchemaManager] Failed to delete field',
                [
                    'error' => $e->getMessage(),
                ]
            );
            return false;
        }//end try
    }//end deleteField()

    /**
     * Get schema configuration for a collection.
     *
     * @param string $collection Collection name
     *
     * @return array Schema configuration
     */
    public function getSchema(string $collection): array
    {
        try {
            $url  = $this->httpClient->getEndpointUrl($collection).'/schema?wt=json';
            $data = $this->httpClient->get($url);

            return $data['schema'] ?? [];
        } catch (Exception $e) {
            $this->logger->error(
                '[SolrSchemaManager] Failed to get schema',
                [
                    'collection' => $collection,
                    'error'      => $e->getMessage(),
                ]
            );
            return [];
        }//end try
    }//end getSchema()
}//end class
