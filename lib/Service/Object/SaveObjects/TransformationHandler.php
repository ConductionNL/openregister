<?php

/**
 * TransformationHandler
 *
 * This file is part of the OpenRegister app for Nextcloud.
 *
 * @category Service
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  AGPL-3.0
 * @link     https://github.com/ConductionNL/openregister
 */

namespace OCA\OpenRegister\Service\Object\SaveObjects;

use OCA\OpenRegister\Db\Schema;

use OCA\OpenRegister\Service\Object\SaveObject\RelationCascadeHandler;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;
use DateTime;

/**
 * Handles transformation of objects to database format for bulk save operations.
 *
 * This handler is responsible for:
 * - Transforming object data to database-ready format
 * - UUID generation and validation
 * - Metadata extraction and assignment
 * - Relations scanning
 * - Owner and organization assignment
 *
 * @category Service
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  AGPL-3.0
 * @link     https://github.com/ConductionNL/openregister
 * @version  1.0.0
 */
class TransformationHandler
{


    /**
     * Constructor for TransformationHandler.
     *
     * @param RelationCascadeHandler $relationCascadeHandler Handler for relation operations.
     * @param OrganisationService    $organisationService    Service for organisation operations.
     * @param IUserSession           $userSession            User session for owner assignment.
     * @param LoggerInterface        $logger                 Logger for logging operations.
     */
    public function __construct(
        private readonly RelationCascadeHandler $relationCascadeHandler,
        // REMOVED: private readonly
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger
    ) {

    }//end __construct()


    /**
     * Transform objects to database format in-place.
     *
     * This method transforms object data to the format required for database storage.
     * It handles:
     * - UUID generation and validation
     * - Register and schema assignment
     * - Owner and organization assignment
     * - Relations scanning
     * - Business data extraction
     *
     * @param array &$objects    Array of objects to transform (passed by reference).
     * @param array $schemaCache Cache of schema objects for validation.
     *
     * @psalm-param    array<int, array<string, mixed>> &$objects
     * @psalm-param    array<int|string, Schema> $schemaCache
     * @phpstan-param  array<int, array<string, mixed>> &$objects
     * @phpstan-param  array<int|string, Schema> $schemaCache
     *
     * @return array Array with 'valid' and 'invalid' keys containing transformed and invalid objects.
     *
     * @psalm-return   array{valid: list<array<string, mixed>>, invalid: list<array<string, mixed>>}
     * @phpstan-return array{valid: list<array<string, mixed>>, invalid: list<array<string, mixed>>}
     */
    public function transformObjectsToDatabaseFormatInPlace(array &$objects, array $schemaCache): array
    {
        $transformedObjects = [];
        $invalidObjects     = [];

        foreach ($objects ?? [] as $index => &$object) {
            // CRITICAL FIX: Objects from prepareSingleSchemaObjectsOptimized are already flat $selfData arrays.
            // They don't have an '@self' key because they ARE the self data.
            // Only extract @self if it exists (mixed schema or other paths).
            if (($object['@self'] ?? null) !== null) {
                $selfData = $object['@self'];
            } else {
                // Object is already a flat $selfData array from prepareSingleSchemaObjectsOptimized.
                $selfData = $object;
            }

            // Auto-wire @self metadata with proper UUID validation and generation.
            new DateTime();

            // Accept any non-empty string as ID, prioritize CSV 'id' column over @self.id.
            $providedId = $object['id'] ?? $selfData['id'] ?? null;
            if (($providedId !== null) === true && empty(trim($providedId)) === false) {
                // Accept any non-empty string as identifier.
                $selfData['uuid'] = $providedId;
            } else {
                // No ID provided or empty - generate new UUID.
                $selfData['uuid'] = Uuid::v4()->toRfc4122();
            }

            // CRITICAL FIX: Use register and schema from object data if available.
            // Register and schema should be provided in object data for this method.
            if (($selfData['register'] ?? null) === null && ($object['register'] ?? null) !== null) {
                if (is_object($object['register']) === true) {
                    $selfData['register'] = $object['register']->getId();
                } else {
                    $selfData['register'] = $object['register'];
                }
            }

            if (($selfData['schema'] ?? null) === null && ($object['schema'] ?? null) !== null) {
                if (is_object($object['schema']) === true) {
                    $selfData['schema'] = $object['schema']->getId();
                } else {
                    $selfData['schema'] = $object['schema'];
                }
            }

            // Note: Register and schema should be set in object data before calling this method.
            // VALIDATION FIX: Validate that required register and schema are properly set.
            if (($selfData['register'] ?? null) === null || ($selfData['schema'] ?? null) === null) {
                if (($selfData['register'] ?? null) === null) {
                    $invalidObjects[] = [
                        'object' => $object,
                        'error'  => 'Register ID is required but not found in object data or method parameters',
                        'index'  => $index,
                        'type'   => 'MissingRegisterException',
                    ];
                    continue;
                }

                if (($selfData['schema'] ?? null) === null) {
                    $invalidObjects[] = [
                        'object' => $object,
                        'error'  => 'Schema ID is required but not found in object data or method parameters',
                        'index'  => $index,
                        'type'   => 'MissingSchemaException',
                    ];
                    continue;
                }
            }//end if

            // VALIDATION FIX: Verify schema exists in cache (validates schema exists in database).
            if (isset($schemaCache[$selfData['schema']]) === false) {
                $invalidObjects[] = [
                    'object' => $object,
                    'error'  => "Schema ID {$selfData['schema']} does not exist or could not be loaded",
                    'index'  => $index,
                    'type'   => 'InvalidSchemaException',
                ];
                continue;
            }

            // Set owner to current user if not provided (with null check).
            if (($selfData['owner'] ?? null) === null || empty($selfData['owner']) === true) {
                $currentUser = $this->userSession->getUser();
                if (($currentUser !== null) === true) {
                    $selfData['owner'] = $currentUser->getUID();
                } else {
                    $selfData['owner'] = null;
                }
            }

            // Set organization using optimized OrganisationService method if not provided.
            if (($selfData['organisation'] ?? null) === null || empty($selfData['organisation']) === true) {
                // NO ERROR SUPPRESSION: Let organisation service errors bubble up immediately!
                $selfData['organisation'] = null;
                // TODO->getOrganisationForNewEntity();
            }

            // DATABASE-MANAGED: created and updated are handled by database DEFAULT and ON UPDATE clauses.
            // METADATA EXTRACTION: Skip redundant extraction as prepareSingleSchemaObjectsOptimized already handles this.
            // with enhanced twig-like concatenation support. This redundant extraction was overwriting the.
            // properly extracted metadata with simpler getValueFromPath results.
            // DEBUG: Log mixed schema object structure.
            $this->logger->info(
                "[SaveObjects] DEBUG - Mixed schema object structure",
                [
                    'available_keys'      => array_keys($object),
                    'has_object_property' => isset($object['object']) === true,
                    'sample_data'         => array_slice($object, 0, 3, true),
                ]
            );

            // TEMPORARY FIX: Extract business data properly based on actual structure.
            if (($object['object'] ?? null) !== null && is_array($object['object']) === true) {
                // NEW STRUCTURE: object property contains business data.
                $businessData = $object['object'];
                $this->logger->info("[SaveObjects] Using object property for business data (mixed)");
            } else {
                // LEGACY STRUCTURE: Remove metadata fields to isolate business data.
                $businessData   = $object;
                $metadataFields = [
                    '@self',
                    'name',
                    'description',
                    'summary',
                    'image',
                    'slug',
                    'published',
                    'depublished',
                    'register',
                    'schema',
                    'organisation',
                    'uuid',
                    'owner',
                    'created',
                    'updated',
                    'id',
                ];

                foreach ($metadataFields ?? [] as $field) {
                    unset($businessData[$field]);
                }

                // CRITICAL DEBUG: Log what we're removing and what remains.
                $this->logger->info(
                    "[SaveObjects] Metadata removal applied (mixed)",
                    [
                        'removed_fields'       => array_intersect($metadataFields, array_keys($object)),
                        'remaining_keys'       => array_keys($businessData),
                        'business_data_sample' => array_slice($businessData, 0, 3, true),
                    ]
                );
            }//end if

            // RELATIONS EXTRACTION: Scan the business data for relations (UUIDs and URLs).
            // ONLY scan if relations weren't already set during preparation phase.
            if (($selfData['relations'] ?? null) === null || empty($selfData['relations']) === true) {
                if (($schemaCache[$selfData['schema']] ?? null) !== null) {
                    $schema    = $schemaCache[$selfData['schema']];
                    $relations = $this->relationCascadeHandler->scanForRelations(data: $businessData, prefix: '', schema: $schema);
                    $selfData['relations'] = $relations;

                    $this->logger->info(
                        "[SaveObjects] Relations scanned in transformation",
                        [
                            'uuid'          => $selfData['uuid'] ?? 'unknown',
                            'relationCount' => count($relations),
                            'relations'     => array_slice($relations, 0, 3, true),
                        ]
                    );
                }
            } else {
                $this->logger->info(
                    "[SaveObjects] Relations already set from preparation",
                    [
                        'uuid'          => $selfData['uuid'] ?? 'unknown',
                        'relationCount' => count($selfData['relations']),
                    ]
                );
            }//end if

            // Store the clean business data in the database object column.
            $selfData['object'] = $businessData;

            $transformedObjects[] = $selfData;
        }//end foreach

        // Return both transformed objects and any invalid objects found during transformation.
        return [
            'valid'   => $transformedObjects,
            'invalid' => $invalidObjects,
        ];

    }//end transformObjectsToDatabaseFormatInPlace()


}//end class
