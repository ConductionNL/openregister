<?php

/**
 * TablesController
 *
 * Controller for managing database tables view and magic table operations.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://www.OpenRegister.nl
 */

namespace OCA\OpenRegister\Controller;

use Exception;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * TablesController class.
 *
 * Controller for managing table operations including magic table synchronization.
 *
 * @psalm-suppress UnusedClass
 */
class TablesController extends Controller
{
    /**
     * Constructor
     *
     * @param string          $appName        Application name
     * @param IRequest        $request        Request object
     * @param IAppConfig      $config         Application config
     * @param MagicMapper     $magicMapper    Magic mapper for table operations
     * @param RegisterMapper  $registerMapper Register mapper
     * @param SchemaMapper    $schemaMapper   Schema mapper
     * @param LoggerInterface $logger         Logger
     */
    public function __construct(
        $appName,
        IRequest $request,
        private readonly IAppConfig $config,
        private readonly MagicMapper $magicMapper,
        private readonly RegisterMapper $registerMapper,
        private readonly SchemaMapper $schemaMapper,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * Sync magic table for a register/schema combination.
     *
     * This triggers the magic table update process which:
     * - Adds missing columns
     * - De-requires columns that are no longer required in schema
     * - Drops duplicate camelCase columns when snake_case exists
     * - Makes obsolete columns nullable
     * - Updates indexes for relations and facetable fields
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param int|string $registerId The register ID or slug
     * @param int|string $schemaId   The schema ID or slug
     *
     * @return JSONResponse
     */
    public function sync(int|string $registerId, int|string $schemaId): JSONResponse
    {
        try {
            // Find register.
            $register = null;
            if (is_numeric($registerId) === true) {
                $register = $this->registerMapper->find((int) $registerId);
            } else {
                $register = $this->registerMapper->findBySlug($registerId);
            }

            if ($register === null) {
                return new JSONResponse(['error' => 'Register not found'], 404);
            }

            // Find schema.
            $schema = null;
            if (is_numeric($schemaId) === true) {
                $schema = $this->schemaMapper->find((int) $schemaId);
            } else {
                $schema = $this->schemaMapper->findBySlug($schemaId);
            }

            if ($schema === null) {
                return new JSONResponse(['error' => 'Schema not found'], 404);
            }

            // Trigger table sync (without dropping/recreating).
            // This updates the table structure to match the schema without losing data.
            $result = $this->magicMapper->syncTableForRegisterSchema(
                register: $register,
                schema: $schema
            );

            $this->logger->info(
                '[TablesController] Magic table sync completed',
                [
                    'registerId' => $register->getId(),
                    'schemaId'   => $schema->getId(),
                    'result'     => $result,
                ]
            );

            return new JSONResponse([
                'success'    => true,
                'message'    => 'Magic table synchronized successfully',
                'register'   => [
                    'id'    => $register->getId(),
                    'title' => $register->getTitle(),
                ],
                'schema'     => [
                    'id'    => $schema->getId(),
                    'title' => $schema->getTitle(),
                ],
                'tableName'  => 'openregister_objects_'.$register->getId().'_'.$schema->getId(),
                'statistics' => [
                    'metadata' => [
                        'count' => $result['metadataProperties'] ?? 0,
                        'description' => 'Built-in system columns (id, uuid, register, schema, etc.)',
                    ],
                    'properties' => [
                        'count' => $result['regularProperties'] ?? 0,
                        'description' => 'Schema-defined properties',
                    ],
                    'columns' => [
                        'added' => [
                            'count' => $result['columnsAdded'] ?? 0,
                            'list' => $result['columnsAddedList'] ?? [],
                        ],
                        'removed' => [
                            'count' => $result['columnsDropped'] ?? 0,
                            'list' => $result['columnsDroppedList'] ?? [],
                        ],
                        'deRequired' => [
                            'count' => $result['columnsDeRequired'] ?? 0,
                            'list' => $result['columnsDeRequiredList'] ?? [],
                            'description' => 'Columns made nullable (no longer required)',
                        ],
                        'unchanged' => [
                            'count' => $result['columnsUnchanged'] ?? 0,
                        ],
                        'total' => $result['totalProperties'] ?? 0,
                    ],
                ],
            ]);
        } catch (Exception $e) {
            $this->logger->error(
                '[TablesController] Magic table sync failed',
                [
                    'registerId' => $registerId,
                    'schemaId'   => $schemaId,
                    'error'      => $e->getMessage(),
                ]
            );

            return new JSONResponse([
                'error'   => 'Failed to sync magic table',
                'message' => $e->getMessage(),
            ], 500);
        }//end try
    }//end sync()

    /**
     * Sync all magic tables for all register/schema combinations.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse
     */
    public function syncAll(): JSONResponse
    {
        try {
            $registers = $this->registerMapper->findAll();
            $results   = [];
            $errors    = [];

            foreach ($registers as $register) {
                $schemas = $register->getSchemas();
                if (is_array($schemas) === false) {
                    continue;
                }

                foreach ($schemas as $schemaRef) {
                    // Schema reference can be ID or slug.
                    $schemaId = is_array($schemaRef) ? ($schemaRef['id'] ?? $schemaRef) : $schemaRef;

                    try {
                        $schema = null;
                        if (is_numeric($schemaId) === true) {
                            $schema = $this->schemaMapper->find((int) $schemaId);
                        } else {
                            $schema = $this->schemaMapper->findBySlug((string) $schemaId);
                        }

                        if ($schema === null) {
                            continue;
                        }

                        $this->magicMapper->syncTableForRegisterSchema(
                            register: $register,
                            schema: $schema
                        );

                        $results[] = [
                            'register' => $register->getId(),
                            'schema'   => $schema->getId(),
                            'status'   => 'success',
                        ];
                    } catch (Exception $e) {
                        $errors[] = [
                            'register' => $register->getId(),
                            'schema'   => $schemaId,
                            'error'    => $e->getMessage(),
                        ];
                    }//end try
                }//end foreach
            }//end foreach

            return new JSONResponse([
                'success'      => count($errors) === 0,
                'message'      => 'Sync completed for '.count($results).' tables',
                'synced'       => $results,
                'errors'       => $errors,
                'totalSynced'  => count($results),
                'totalErrors'  => count($errors),
            ]);
        } catch (Exception $e) {
            return new JSONResponse([
                'error'   => 'Failed to sync magic tables',
                'message' => $e->getMessage(),
            ], 500);
        }//end try
    }//end syncAll()
}//end class
