<?php

/**
 * OpenRegister RegisterSerializer
 *
 * Serializes Register entities to arrays with optional _extend transformations
 * (schema ID expansion, per-schema object counts) so that any caller — HTTP or DI —
 * gets an identical payload without duplicating expansion logic in the controller.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Serializer
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/extend-schemas-in-register-service/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Serializer;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use Psr\Log\LoggerInterface;

/**
 * Serializes Register entities with optional _extend transformations.
 *
 * Supported _extend values:
 * - 'schemas'     — replace schema IDs with full schema objects from SchemaMapper
 * - '@self.stats' — attach per-schema object counts (only effective with 'schemas')
 *
 * Unknown _extend keys are silently ignored. Missing schema IDs are retained in
 * their original array position (they are never dropped silently).
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/extend-schemas-in-register-service/tasks.md#task-1
 */
class RegisterSerializer
{

    /**
     * Schema mapper for schema lookups during expansion.
     *
     * @var SchemaMapper
     */
    private readonly SchemaMapper $schemaMapper;

    /**
     * Logger for warnings on missing schema IDs.
     *
     * @var LoggerInterface
     */
    private readonly LoggerInterface $logger;

    /**
     * Constructor.
     *
     * @param SchemaMapper    $schemaMapper Schema mapper for ID-to-object expansion.
     * @param LoggerInterface $logger       Logger for orphan-schema warnings.
     *
     * @spec openspec/changes/extend-schemas-in-register-service/tasks.md#task-1.1
     */
    public function __construct(
        SchemaMapper $schemaMapper,
        LoggerInterface $logger
    ) {
        $this->schemaMapper = $schemaMapper;
        $this->logger       = $logger;
    }//end __construct()

    /**
     * Serialize a single Register entity with optional _extend transformations.
     *
     * Calls $register->jsonSerialize() to get the base array, then applies any
     * requested _extend transformations. The Register entity is never mutated.
     *
     * @param Register   $register    The register entity to serialize.
     * @param array      $extend      Extension keys to apply ('schemas', '@self.stats').
     * @param array|null $schemaStats Pre-computed schema stats keyed by schema ID
     *                                (from RegisterService::getSchemaObjectCounts()).
     *                                Required when '@self.stats' + 'schemas' are both requested.
     *
     * @return array Serialized register array with requested extensions applied.
     *
     * @spec openspec/changes/extend-schemas-in-register-service/tasks.md#task-1.1
     * @spec openspec/changes/extend-schemas-in-register-service/tasks.md#task-1.2
     * @spec openspec/changes/extend-schemas-in-register-service/tasks.md#task-1.3
     */
    public function serialize(
        Register $register,
        array $extend=[],
        ?array $schemaStats=null
    ): array {
        $data = $register->jsonSerialize();

        if (in_array(needle: 'schemas', haystack: $extend, strict: true) === true) {
            $data = $this->applySchemaExtension(
                registerData: $data,
                extend: $extend,
                schemaStats: $schemaStats
            );
        }

        return $data;
    }//end serialize()

    /**
     * Serialize multiple Register entities with optional _extend transformations.
     *
     * Iterates over the provided Register entities and delegates each to serialize().
     *
     * @param array      $registers               Array of Register entities.
     * @param array      $extend                  Extension keys to apply.
     * @param array|null $schemaStatsByRegisterId Pre-computed schema stats keyed by
     *                                            register ID then schema ID.
     *
     * @return array Array of serialized register arrays.
     *
     * @spec openspec/changes/extend-schemas-in-register-service/tasks.md#task-1.1
     */
    public function serializeMany(
        array $registers,
        array $extend=[],
        ?array $schemaStatsByRegisterId=null
    ): array {
        $result = [];

        foreach ($registers as $register) {
            $registerId  = $register->getId();
            $schemaStats = null;
            if ($schemaStatsByRegisterId !== null && isset($schemaStatsByRegisterId[$registerId]) === true) {
                $schemaStats = $schemaStatsByRegisterId[$registerId];
            }

            $result[] = $this->serialize(
                register: $register,
                extend: $extend,
                schemaStats: $schemaStats
            );
        }

        return $result;
    }//end serializeMany()

    /**
     * Apply the 'schemas' extension to a serialized register array.
     *
     * For each schema ID in $registerData['schemas'], attempts to load the full
     * schema object via SchemaMapper. On success the schema's jsonSerialize() output
     * replaces the ID. On DoesNotExistException the original ID is retained in its
     * position and a warning is logged.
     *
     * When '@self.stats' is also requested, attaches stats.objects.total to each
     * successfully expanded schema using the provided $schemaStats lookup.
     *
     * @param array      $registerData Serialized register array (pre-extension).
     * @param array      $extend       Extension keys in effect.
     * @param array|null $schemaStats  Pre-computed stats keyed by schema ID.
     *
     * @return array Updated register array with schemas extended.
     *
     * @spec openspec/changes/extend-schemas-in-register-service/tasks.md#task-1.2
     * @spec openspec/changes/extend-schemas-in-register-service/tasks.md#task-1.3
     */
    private function applySchemaExtension(
        array $registerData,
        array $extend,
        ?array $schemaStats
    ): array {
        $schemaIds = $registerData['schemas'] ?? [];

        if (is_array($schemaIds) === false || empty($schemaIds) === true) {
            return $registerData;
        }

        $addStats        = in_array(needle: '@self.stats', haystack: $extend, strict: true);
        $expandedSchemas = [];

        foreach ($schemaIds as $schemaId) {
            try {
                $schema     = $this->schemaMapper->find(id: $schemaId, _multitenancy: false);
                $schemaData = $schema->jsonSerialize();

                if ($addStats === true) {
                    $count = 0;
                    if ($schemaStats !== null && isset($schemaStats[$schemaData['id']]) === true) {
                        $count = $schemaStats[$schemaData['id']]['total'] ?? 0;
                    }

                    $schemaData['stats'] = ['objects' => ['total' => $count]];
                }

                $expandedSchemas[] = $schemaData;
            } catch (DoesNotExistException $e) {
                // Retain original ID in position — do NOT drop orphan references.
                $this->logger->warning(
                    message: '[RegisterSerializer] Schema not found during expansion — retaining orphan ID.',
                    context: [
                        'schemaId' => $schemaId,
                        'file'     => __FILE__,
                        'line'     => __LINE__,
                    ]
                );
                $expandedSchemas[] = $schemaId;
            }//end try
        }//end foreach

        $registerData['schemas'] = $expandedSchemas;

        return $registerData;
    }//end applySchemaExtension()
}//end class
