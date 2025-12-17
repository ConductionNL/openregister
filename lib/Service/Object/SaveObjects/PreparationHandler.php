<?php

/**
 * PreparationHandler
 *
 * This file is part of the OpenRegister app for Nextcloud.
 *
 * @category Service
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @link     https://github.com/ConductionNL/openregister
 */

namespace OCA\OpenRegister\Service\Object\SaveObjects;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Object\SaveObjects\BulkValidationHandler;

use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use DateTime;
use Exception;

/**
 * Handles preparation of objects for bulk save operations.
 *
 * This handler is responsible for:
 * - Schema loading and caching
 * - Metadata hydration
 * - UUID generation
 * - Auto-publish logic
 * - Relations scanning
 * - Pre-validation cascading
 *
 * @category Service
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @link     https://github.com/ConductionNL/openregister
 * @version  1.0.0
 */
class PreparationHandler
{

    /**
     * Static cache for registers to avoid repeated DB queries.
     *
     * @var array<int, Register>
     */
    private static array $registerCache=[];

    /**
     * Static cache for schemas to avoid repeated DB queries.
     *
     * @var array<int, Schema>
     */
    private static array $schemaCache=[];


    /**
     * Constructor for PreparationHandler.
     *
     * @param SaveObject            $saveHandler         Handler for save operations.
     * @param SchemaMapper          $schemaMapper        Mapper for schema operations.
     * @param BulkValidationHandler $bulkValidationHandler Handler for schema analysis.
     * @param OrganisationService   $organisationService Service for organisation operations.
     * @param IUserSession          $userSession         User session for owner assignment.
     * @param LoggerInterface       $logger              Logger for logging operations.
     */
    public function __construct(
    private readonly SaveObject $saveHandler,
    private readonly SchemaMapper $schemaMapper,
    private readonly BulkValidationHandler $bulkValidationHandler,
    // REMOVED: private readonly.
    private readonly IUserSession $userSession,
    private readonly LoggerInterface $logger
    ) {
    }//end __construct()


    /**
     * Prepare objects for bulk save operations.
     *
     * This method prepares objects by:
     * - Loading and caching schemas
     * - Hydrating metadata (name, description, summary, etc.)
     * - Generating UUIDs for new objects
     * - Scanning for relations
     * - Handling pre-validation cascading
     *
     * @param array $objects Array of objects to prepare.
     *
     * @return array Array containing [prepared objects, schema cache, invalid objects].
     *
     * @throws Exception If schema not found or other preparation errors.
     *
     * @psalm-param array<int, array<string, mixed>> $objects
     * @phpstan-param array<int, array<string, mixed>> $objects
     * @psalm-return array{0: array<int, array<string, mixed>>, 1: array<int|string, Schema>, 2: array<int, array<string, mixed>>}
     * @phpstan-return array{0: array<int, array<string, mixed>>, 1: array<int|string, Schema>, 2: array<int, array<string, mixed>>}
     */
    public function prepareObjectsForBulkSave(array $objects): array
    {
        // Early return for empty arrays.
        if (empty($objects) === true) {
            return [[], [], []];
        }

        $preparedObjects=[];
        $schemaCache     = [];
        $schemaAnalysis  = [];
        $invalidObjects  = [];

        // PERFORMANCE OPTIMIZATION: Build comprehensive schema analysis cache first.
        $schemaIds=[];
        foreach ($objects as $object) {
            $selfData = $object['@self'] ?? [];
            $schemaId = $selfData['schema'] ?? null;
            if (($schemaId !== null) === true && in_array($schemaId, $schemaIds, true) === false) {
                $schemaIds[] = $schemaId;
            }
        }

        // PERFORMANCE OPTIMIZATION: Load and analyze all schemas with caching.
        foreach ($schemaIds as $schemaId) {
            // Load schema (implementation would use schema mapper).
            // For this extracted handler, we assume schema loading is done externally.
            // This is a placeholder - actual implementation needs schema mapper injection.
            $schema = $this->loadSchemaWithCache($schemaId);
            $schemaCache[$schemaId] = $schema;

            // Get schema analysis (implementation would use bulk validation handler).
            $schemaAnalysis[$schemaId] = $this->getSchemaAnalysisWithCache($schema);
        }

        // Pre-process objects using cached schema analysis.
        foreach ($objects as $index => $object) {
            $selfData = $object['@self'] ?? [];
            $schemaId = $selfData['schema'] ?? null;

            // Allow objects without schema ID to pass through - they'll be caught in transformation.
            if ($schemaId === null || $schemaId === '') {
                $preparedObjects[$index] = $object;
                continue;
            }

            // Schema validation - direct error if not found in cache.
            if (isset($schemaCache[$schemaId]) === false) {
                throw new Exception("Schema {$schemaId} not found in cache during preparation");
            }

            $schema = $schemaCache[$schemaId];

            // Accept any non-empty string as ID, generate UUID if not provided.
            $providedId = $selfData['id'] ?? null;
            if (($providedId === null) === true || empty(trim($providedId)) === true) {
                // No ID provided or empty - generate new UUID.
                $selfData['id'] = \Symfony\Component\Uid\Uuid::v4()->toRfc4122();
                $object['@self'] = $selfData;
            }

            // METADATA HYDRATION: Create temporary entity for metadata extraction.
            $tempEntity = new ObjectEntity();
            $tempEntity->setObject($object);

            // CRITICAL FIX: Hydrate @self data into the entity before calling hydrateObjectMetadata.
            if (($object['@self'] ?? null) !== null && is_array($object['@self']) === true) {
                $selfDataForHydration = $object['@self'];

                // Convert published/depublished strings to DateTime objects.
                if (($selfDataForHydration['published'] ?? null) !== null && is_string($selfDataForHydration['published']) === true) {
                    try {
                        $selfDataForHydration['published'] = new DateTime($selfDataForHydration['published']);
                    } catch (Exception $e) {
                        // Keep as string if conversion fails.
                    }
                }

                if (($selfDataForHydration['depublished'] ?? null) !== null && is_string($selfDataForHydration['depublished']) === true) {
                    try {
                        $selfDataForHydration['depublished'] = new DateTime($selfDataForHydration['depublished']);
                    } catch (Exception $e) {
                        // Keep as string if conversion fails.
                    }
                }

                $tempEntity->hydrate($selfDataForHydration);
            }//end if

            $this->saveHandler->hydrateObjectMetadata(entity: $tempEntity, schema: $schema);

            // AUTO-PUBLISH LOGIC: Only set published for NEW objects if not already set from CSV.
            $config = $schema->getConfiguration();
            $isNewObject = empty($selfData['id']) === true || isset($selfData['id']) === false;
            if (($config['autoPublish'] ?? null) !== null && $config['autoPublish'] === true && ($isNewObject === true)) {
                // Check if published date was already set from @self data (CSV).
                $publishedFromCsv = ($selfData['published'] ?? null) !== null && (empty($selfData['published']) === false);
                if (($publishedFromCsv === false) === true && $tempEntity->getPublished() === null) {
                    $this->logger->debug('Auto-publishing NEW object in bulk creation', [
                        'schema' => $schema->getTitle(),
                        'autoPublish' => true,
                        'isNewObject' => true,
                        'publishedFromCsv' => false
                    ]);
                    $tempEntity->setPublished(new DateTime());
                } elseif ($publishedFromCsv === true) {
                    $this->logger->debug('Skipping auto-publish - published date provided from CSV', [
                        'schema' => $schema->getTitle(),
                        'publishedFromCsv' => true,
                        'csvPublishedDate' => $selfData['published']
                    ]);
                }
            }//end if

            // Extract hydrated metadata back to object's @self data.
            $selfData = $object['@self'] ?? [];
            if ($tempEntity->getName() !== null) {
                $selfData['name'] = $tempEntity->getName();
            }

            if ($tempEntity->getDescription() !== null) {
                $selfData['description'] = $tempEntity->getDescription();
            }

            if ($tempEntity->getSummary() !== null) {
                $selfData['summary'] = $tempEntity->getSummary();
            }

            if ($tempEntity->getImage() !== null) {
                $selfData['image'] = $tempEntity->getImage();
            }

            if ($tempEntity->getSlug() !== null) {
                $selfData['slug'] = $tempEntity->getSlug();
            }

            if ($tempEntity->getPublished() !== null) {
                $publishedFormatted = $tempEntity->getPublished()->format('c');
                $selfData['published'] = $publishedFormatted;
            }

            if ($tempEntity->getDepublished() !== null) {
                $depublishedFormatted = $tempEntity->getDepublished()->format('c');
                $selfData['depublished'] = $depublishedFormatted;
            }

            // RELATIONS EXTRACTION: Scan the object data for relations.
            $objectDataForRelations = $tempEntity->getObject();
            $relations = $this->saveHandler->scanForRelations(data: $objectDataForRelations, prefix: '', schema: $schema);
            $selfData['relations'] = $relations;

            $object['@self'] = $selfData;

            // Handle pre-validation cascading (placeholder - needs actual implementation).
            $processedObject = $this->handlePreValidationCascading($object, $selfData['id']);

            $preparedObjects[$index] = $processedObject;
        }//end foreach

        // PERFORMANCE OPTIMIZATION: Handle bulk inverse relations (placeholder).
        $this->handleBulkInverseRelationsWithAnalysis($preparedObjects, $schemaAnalysis);

        // Return prepared objects, schema cache, and any invalid objects.
        return [array_values($preparedObjects), $schemaCache, $invalidObjects];
    }//end prepareObjectsForBulkSave()


    /**
     * Load schema with caching.
     *
     * @param int|string $schemaId The schema ID to load.
     *
     * @return Schema The loaded schema.
     */
    private function loadSchemaWithCache($schemaId): Schema
    {
        // Check static cache first.
        if ((self::$schemaCache[$schemaId] ?? null) !== null) {
            return self::$schemaCache[$schemaId];
        }

        // Load from database and cache.
        $schema = $this->schemaMapper->find($schemaId);
        self::$schemaCache[$schemaId] = $schema;

        return $schema;
    }//end loadSchemaWithCache()


    /**
     * Get schema analysis with caching.
     *
     * @param Schema $schema The schema to analyze.
     *
     * @return array The schema analysis.
     */
    private function getSchemaAnalysisWithCache(Schema $schema): array
    {
        // Delegate to BulkValidationHandler for comprehensive schema analysis.
        return $this->bulkValidationHandler->performComprehensiveSchemaAnalysis($schema);
    }//end getSchemaAnalysisWithCache()


    /**
     * Handle pre-validation cascading.
     *
     * @param array  $object The object to process.
     * @param string $uuid   The object UUID.
     *
     * @return array The processed object.
     */
    private function handlePreValidationCascading(array $object, string $uuid): array
    {
        // Delegate to BulkValidationHandler for pre-validation cascading.
        [$processedObject, $_] = $this->bulkValidationHandler->handlePreValidationCascading($object, $uuid);
        return $processedObject;
    }//end handlePreValidationCascading()


    /**
     * Handle bulk inverse relations with analysis.
     *
     * @param array &$preparedObjects The prepared objects.
     * @param array $schemaAnalysis   The schema analysis.
     *
     * @return void
     */
    private function handleBulkInverseRelationsWithAnalysis(array &$preparedObjects, array $schemaAnalysis): void
    {
        // This method is handled internally by SaveObjects for performance.
        // For now, we skip it in the handler to avoid circular dependencies.
    }//end handleBulkInverseRelationsWithAnalysis()
}//end class
