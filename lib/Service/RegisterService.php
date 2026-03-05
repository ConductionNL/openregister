<?php

/**
 * OpenRegister RegisterService
 *
 * Service class for managing registers in the OpenRegister application.
 *
 * This service acts as a facade for register operations,
 * coordinating between RegisterMapper and FileService.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

namespace OCA\OpenRegister\Service;

use Exception;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * RegisterService manages registers in the OpenRegister application
 *
 * Service class for managing registers in the OpenRegister application.
 * This service acts as a facade for register operations, coordinating between
 * RegisterMapper and FileService. Handles register CRUD operations, file management,
 * and organisation-related operations.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */
class RegisterService
{

    /**
     * Register mapper
     *
     * Handles database operations for register entities.
     *
     * @var RegisterMapper Register mapper instance
     */
    private readonly RegisterMapper $registerMapper;

    /**
     * Schema mapper
     *
     * Handles database operations for schema entities.
     *
     * @var SchemaMapper Schema mapper instance
     */
    private readonly SchemaMapper $schemaMapper;

    /**
     * Database connection
     *
     * Direct database connection for custom queries.
     *
     * @var IDBConnection Database connection instance
     */
    private readonly IDBConnection $db;

    /**
     * File service
     *
     * Handles file operations related to registers.
     *
     * @var FileService File service instance
     */
    private readonly FileService $fileService;

    /**
     * Organisation service
     *
     * Handles organisation-related operations and permissions.
     *
     * @var OrganisationService Organisation service instance
     */
    private readonly OrganisationService $organisationService;

    /**
     * Logger
     *
     * Used for logging register operations and errors.
     *
     * @var LoggerInterface Logger instance
     */
    private readonly LoggerInterface $logger;

    /**
     * Constructor
     *
     * Initializes service with required dependencies for register operations.
     *
     * @param RegisterMapper      $registerMapper      Register mapper for database operations
     * @param SchemaMapper        $schemaMapper        Schema mapper for schema operations
     * @param IDBConnection       $db                  Database connection for custom queries
     * @param FileService         $fileService         File service for file operations
     * @param OrganisationService $organisationService Organisation service for permissions
     * @param LoggerInterface     $logger              Logger for error tracking
     *
     * @return void
     */
    public function __construct(
        RegisterMapper $registerMapper,
        SchemaMapper $schemaMapper,
        IDBConnection $db,
        FileService $fileService,
        OrganisationService $organisationService,
        LoggerInterface $logger
    ) {
        $this->logger = $logger;
        $this->logger->debug(
            message: '[RegisterService] RegisterService constructor started.',
            context: ['file' => __FILE__, 'line' => __LINE__]
        );
        // Store dependencies for use in service methods.
        $this->registerMapper = $registerMapper;
        $this->schemaMapper   = $schemaMapper;
        $this->db          = $db;
        $this->fileService = $fileService;
        $this->organisationService = $organisationService;
        $this->logger->debug(
            message: '[RegisterService] RegisterService constructor completed.',
            context: ['file' => __FILE__, 'line' => __LINE__]
        );
    }//end __construct()

    /**
     * Find a register by ID with optional extensions
     *
     * Retrieves register entity by ID with optional extended data.
     * Extensions can include related entities like schemas, objects, etc.
     *
     * @param int|string    $id            The ID of the register to find.
     * @param array<string> $_extend       Optional array of extension names to include.
     * @param bool          $_multitenancy Whether to apply multitenancy filtering.
     *
     * @return Register The found register entity
     *
     * @throws \OCP\AppFramework\Db\DoesNotExistException If register not found
     * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException If multiple registers found (should not happen)
     * @throws \OCP\DB\Exception If database error occurs
     */
    public function find(int | string $id, array $_extend=[], bool $_multitenancy=true): Register
    {
        return $this->registerMapper->find(id: $id, _extend: $_extend, _multitenancy: $_multitenancy);
    }//end find()

    /**
     * Find all registers with optional filters and extensions
     *
     * Retrieves all registers matching optional filters and search conditions.
     * Supports pagination via limit and offset parameters.
     * Extensions can include related entities like schemas, objects, etc.
     *
     * @param int|null                  $limit            Maximum number of results to return (null = no limit)
     * @param int|null                  $offset           Number of results to skip for pagination
     * @param array<string, mixed>|null $filters          Filters to apply (e.g., ['organisation_id' => 1])
     * @param array<string, mixed>|null $searchConditions Search conditions for advanced filtering
     * @param array<string, mixed>|null $searchParams     Search parameters for query building
     * @param array<string>             $_extend          Optional extensions to include in results.
     * @param bool                      $_multitenancy    Whether to apply multitenancy filtering.
     *
     * @return Register[] Array of found register entities
     *
     * @psalm-return array<Register>
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)    Optional parameters use null defaults for flexibility
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) Multiple optional filter parameters for flexibility
     */
    public function findAll(
        ?int $limit=null,
        ?int $offset=null,
        ?array $filters=[],
        ?array $searchConditions=[],
        ?array $searchParams=[],
        ?array $_extend=[],
        bool $_multitenancy=true
    ): array {
        // Find all registers with optional filtering, pagination, and extensions.
        return $this->registerMapper->findAll(
            limit: $limit,
            offset: $offset,
            filters: $filters,
            searchConditions: $searchConditions,
            searchParams: $searchParams,
            _extend: $_extend,
            _multitenancy: $_multitenancy
        );
    }//end findAll()

    /**
     * Create a new register from array data.
     *
     * @param array $data The data to create the register from
     *
     * @return Register The created register
     *
     * @throws Exception If register creation fails
     */
    public function createFromArray(array $data): Register
    {
        $this->logger->info(
            message: '[RegisterService] 🔹 RegisterService: Starting createFromArray',
            context: ['file' => __FILE__, 'line' => __LINE__]
        );

        // Create the register first.
        $register = $this->registerMapper->createFromArray(object: $data);
        $this->logger->info(
            message: '[RegisterService] 🔹 RegisterService: Register created with ID: '.$register->getId(),
            context: ['file' => __FILE__, 'line' => __LINE__]
        );

        // Set organisation from active organisation for multi-tenancy (if not already set).
        if ($register->getOrganisation() === null || $register->getOrganisation() === '') {
            $this->logger->info(
                message: '[RegisterService] 🔹 RegisterService: Getting organisation for new entity',
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
            $organisationUuid = $this->organisationService->getOrganisationForNewEntity();
            $this->logger->info(
                message: '[RegisterService] 🔹 RegisterService: Got organisation UUID: '.$organisationUuid,
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
            $register->setOrganisation($organisationUuid);
            $register = $this->registerMapper->update($register);
            $this->logger->info(
                message: '[RegisterService] 🔹 RegisterService: Updated register with organisation',
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
        }

        // Ensure folder exists for the new register.
        $this->logger->info(
            message: '[RegisterService] 🔹 RegisterService: Calling ensureRegisterFolderExists',
            context: ['file' => __FILE__, 'line' => __LINE__]
        );
        $this->ensureRegisterFolderExists(entity: $register);
        $this->logger->info(
            message: '[RegisterService] 🔹 RegisterService: Folder creation completed',
            context: ['file' => __FILE__, 'line' => __LINE__]
        );

        return $register;
    }//end createFromArray()

    /**
     * Update an existing register from array data.
     *
     * @param int   $id   The ID of the register to update
     * @param array $data The new data for the register
     *
     * @return Register The updated register
     *
     * @throws Exception If register update fails
     */
    public function updateFromArray(int $id, array $data): Register
    {
        // Update the register first.
        $register = $this->registerMapper->updateFromArray(id: $id, object: $data);

        // Ensure folder exists for the updated register (handles legacy folder properties).
        $this->ensureRegisterFolderExists(entity: $register);

        return $register;
    }//end updateFromArray()

    /**
     * Delete a register.
     *
     * @param Register $register The register to delete
     *
     * @return Register The deleted register
     *
     * @throws Exception If register has attached objects or deletion fails
     *
     * @psalm-suppress PossiblyUnusedReturnValue
     */
    public function delete(Register $register): Register
    {
        return $this->registerMapper->delete($register);
    }//end delete()

    /**
     * Ensure folder exists for a Register.
     *
     * This method checks if the register has a valid folder ID and creates one if needed.
     * It handles legacy cases where the folder property might be null, empty, or a string path.
     *
     * @param Register $entity The register entity to ensure folder for
     *
     * @return void
     *
     * @psalm-return   void
     * @phpstan-return void
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Multiple folder state checks and error handling
     */
    private function ensureRegisterFolderExists(Register $entity): void
    {
        $folderProperty = $entity->getFolder();

        // Check if folder needs to be created (null, empty string, or legacy string path).
        if ($folderProperty === null || $folderProperty === '' || is_string($folderProperty) === true) {
            try {
                // Create folder and get the folder node.
                $folderNode = $this->fileService->createEntityFolder($entity);

                if ($folderNode === null) {
                    $this->logger->warning(
                        message: "[RegisterService] Failed to create folder for register {$entity->getId()}",
                        context: ['file' => __FILE__, 'line' => __LINE__]
                    );
                    return;
                }

                // Update the entity with the folder ID.
                $entity->setFolder((string) $folderNode->getId());

                // Save the entity with the new folder ID.
                $this->registerMapper->update($entity);

                $folderId   = $folderNode->getId();
                $registerId = $entity->getId();
                $this->logger->info(
                    message: "[RegisterService] Created folder with ID {$folderId} for register {$registerId}",
                    context: ['file' => __FILE__, 'line' => __LINE__]
                );
            } catch (Exception $e) {
                // Log the error but don't fail the register creation/update.
                // The register can still function without a folder.
                $this->logger->error(
                    message: "[RegisterService] Failed to create folder for register {$entity->getId()}: ".$e->getMessage(),
                    context: ['file' => __FILE__, 'line' => __LINE__]
                );
            }//end try
        }//end if
    }//end ensureRegisterFolderExists()

    /**
     * Get object counts per schema for a register using optimized SQL
     *
     * This method builds a single SQL query that counts objects for each schema,
     * handling both magic table and blob storage configurations efficiently.
     *
     * @param int   $registerId The register ID to get counts for
     * @param array $schemas    Array of schema objects with their configurations
     *
     * @return array<int, array{total: int}> Associative array mapping schema IDs to counts
     *
     * @psalm-return array<int, array{total: int}>
     */
    public function getSchemaObjectCounts(int $registerId, array $schemas): array
    {
        // Initialize result array.
        $result = [];

        if (empty($schemas) === true) {
            return $result;
        }

        try {
            $this->logger->debug(
                message: '[RegisterService] GetSchemaObjectCounts: Processing '.count($schemas).' schemas for register '.$registerId,
                context: ['file' => __FILE__, 'line' => __LINE__]
            );

            // Build a UNION query that counts objects for each schema.
            $unionQueries = [];
            $blobSchemas  = [];

            foreach ($schemas as $schema) {
                $schemaId = $schema['id'] ?? null;
                if ($schemaId === null) {
                    $this->logger->warning(
                        message: '[RegisterService] Schema without ID found, skipping',
                        context: ['file' => __FILE__, 'line' => __LINE__]
                    );
                    continue;
                }

                $this->logger->debug(
                    message: "[RegisterService] Processing schema ID: {$schemaId}",
                    context: ['file' => __FILE__, 'line' => __LINE__]
                );

                // Check if this schema uses magic table (has 'table' configuration in properties).
                $isMagicTable = false;
                if (isset($schema['properties']) === true && is_array($schema['properties']) === true) {
                    foreach ($schema['properties'] as $property) {
                        if (isset($property['table']) === true && is_array($property['table']) === true) {
                            $isMagicTable = true;
                            break;
                        }
                    }
                }

                $magicTableLabel = 'no';
                if ($isMagicTable === true) {
                    $magicTableLabel = 'yes';
                }

                $this->logger->debug(
                    message: "[RegisterService] Schema {$schemaId} is magic table: ".($magicTableLabel),
                    context: ['file' => __FILE__, 'line' => __LINE__]
                );

                if ($isMagicTable === true) {
                    // Magic table: check if table exists, then query it.
                    // Note: Nextcloud's IDBConnection doesn't have getPrefix(), we use the table name directly.
                    $tableName = 'openregister_table_'.$registerId.'_'.$schemaId;

                    // Check if table exists.
                    $tableExists = $this->db->tableExists($tableName);

                    if ($tableExists === true) {
                        $quotedTableName = $this->db->getQueryBuilder()->getTableName($tableName);
                        // Magic tables store data in flat columns (not in an 'object' column).
                        // The _deleted column is JSONB and should be NULL for non-deleted objects.
                        // Cast schema_id to VARCHAR to match blob storage query type.
                        $unionQueries[] = "
                            SELECT
                                CAST({$schemaId} AS VARCHAR) as schema_id,
                                COUNT(*) as total,
                                COUNT(CASE WHEN _deleted IS NOT NULL THEN 1 END) as deleted,
                                0 as invalid,
                                0 as locked,
                                0 as published,
                                0 as size
                            FROM {$quotedTableName}
                        ";
                    } else {
                        // Table doesn't exist yet, return 0 for all stats.
                        $result[$schemaId] = [
                            'total'     => 0,
                            'deleted'   => 0,
                            'invalid'   => 0,
                            'locked'    => 0,
                            'published' => 0,
                            'size'      => 0,
                        ];
                    }//end if
                } else {
                    // Blob storage: add to blob schemas list.
                    $blobSchemas[] = (int) $schemaId;
                }//end if
            }//end foreach

            // Add blob storage query if there are any blob schemas.
            if (empty($blobSchemas) === false) {
                $schemaIdsList = implode("','", $blobSchemas);
                $qb            = $this->db->getQueryBuilder();
                $tableName     = $qb->getTableName('openregister_objects');
                $unionQueries[] = "
                    SELECT
                        schema as schema_id,
                        COUNT(*) as total,
                        COUNT(CASE WHEN deleted IS NOT NULL THEN 1 END) as deleted,
                        COUNT(CASE WHEN validation IS NOT NULL THEN 1 END) as invalid,
                        COUNT(CASE WHEN locked IS NOT NULL THEN 1 END) as locked,
                        COUNT(CASE WHEN published IS NOT NULL AND published <= NOW()
                              AND (depublished IS NULL OR depublished > NOW()) THEN 1 END) as published,
                        COALESCE(SUM(size), 0) as size
                    FROM {$tableName}
                    WHERE register = '{$registerId}'
                      AND schema IN ('{$schemaIdsList}')
                    GROUP BY schema
                ";
            }

            if (empty($unionQueries) === true) {
                return $result;
            }

            // Combine all queries with UNION ALL.
            $sql = implode(' UNION ALL ', $unionQueries);

            // Log the SQL for debugging.
            $this->logger->debug(
                message: '[RegisterService] Schema object counts SQL: '.$sql,
                context: ['file' => __FILE__, 'line' => __LINE__]
            );

            // Execute the query.
            $stmt = $this->db->prepare($sql);
            $stmt->execute();

            // Process results.
            while (($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
                $result[(int) $row['schema_id']] = [
                    'total'     => (int) $row['total'],
                    'deleted'   => (int) $row['deleted'],
                    'invalid'   => (int) $row['invalid'],
                    'locked'    => (int) $row['locked'],
                    'published' => (int) $row['published'],
                    'size'      => (int) $row['size'],
                ];
            }

            $stmt->closeCursor();

            // Ensure all blob schemas have an entry (even if 0).
            foreach ($blobSchemas as $schemaId) {
                if (isset($result[$schemaId]) === false) {
                    $result[$schemaId] = [
                        'total'     => 0,
                        'deleted'   => 0,
                        'invalid'   => 0,
                        'locked'    => 0,
                        'published' => 0,
                        'size'      => 0,
                    ];
                }
            }
        } catch (\Exception $e) {
            // Log error but don't fail - return empty counts.
            $this->logger->error(
                message: '[RegisterService] Error getting schema object counts: '.$e->getMessage(),
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
            $this->logger->error(
                message: '[RegisterService] Stack trace: '.$e->getTraceAsString(),
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
        }//end try

        return $result;
    }//end getSchemaObjectCounts()
}//end class
