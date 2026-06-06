<?php

/**
 * RegisterSerializer
 *
 * Serializes Register entities to arrays with optional field expansion
 * (e.g. schema IDs → full schema objects, per-schema object counts).
 * Provides a stable serialization contract for both HTTP and DI consumers,
 * mirroring the output previously only available via RegistersController::index().
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
 * Supported _extend keys:
 *   - 'schemas'      Replace schema ID array with full schema objects (orphan IDs retained).
 *   - '@self.stats'  Attach per-schema object counts (only effective alongside 'schemas').
 *
 * Unknown _extend keys are silently ignored. The entity's own jsonSerialize()
 * remains ID-only; expansion is always opt-in via this serializer.
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
 *
 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
 */
class RegisterSerializer
{

    /**
     * Schema mapper for fetching schema objects during expansion.
     *
     * @var SchemaMapper
     */
    private readonly SchemaMapper $schemaMapper;

    /**
     * Logger for warnings (e.g. orphan schema IDs).
     *
     * @var LoggerInterface
     */
    private readonly LoggerInterface $logger;

    /**
     * Constructor.
     *
     * @param SchemaMapper    $schemaMapper Schema mapper for fetching schemas.
     * @param LoggerInterface $logger       Logger for warnings.
     *
     * @return void
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
     * Calls $register->jsonSerialize() for the base shape, then applies each
     * recognized _extend key in order. Unknown keys are silently ignored.
     *
     * @param Register   $register    The register entity to serialize.
     * @param array      $extend      Extension keys to apply ('schemas', '@self.stats').
     * @param array|null $schemaStats Pre-computed schema object counts keyed by schema ID.
     *                                Required when '@self.stats' is in $extend; ignored otherwise.
     *
     * @return array The serialized register array with requested extensions applied.
     *
     * @spec openspec/changes/extend-schemas-in-register-service/tasks.md#task-1.1
     */
    public function serialize(
        Register $register,
        array $extend=[],
        ?array $schemaStats=null
    ): array {
        $data = $register->jsonSerialize();

        if (in_array(needle: 'schemas', haystack: $extend, strict: true) === true) {
            $data = $this->applySchemaExtension(
                data: $data,
                extend: $extend,
                schemaStats: $schemaStats
            );
        }

        return $data;
    }//end serialize()

    /**
     * Serialize multiple Register entities.
     *
     * Iterates over the provided register array and delegates each entry to
     * serialize(). The per-register stats slice is extracted from $schemaStatsByRegisterId
     * using the register's integer ID.
     *
     * @param Register[] $registers               Array of Register entities.
     * @param array      $extend                  Extension keys to apply.
     * @param array|null $schemaStatsByRegisterId Pre-computed schema counts keyed by
     *                                            registerId → [schemaId → counts].
     *
     * @return array[] Array of serialized register arrays.
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
            $registerId = $register->getId();
            $stats      = null;

            if ($schemaStatsByRegisterId !== null && $registerId !== null) {
                $stats = $schemaStatsByRegisterId[$registerId] ?? null;
            }

            $result[] = $this->serialize(
                register: $register,
                extend: $extend,
                schemaStats: $stats
            );
        }

        return $result;
    }//end serializeMany()

    /**
     * Apply the 'schemas' (and optionally '@self.stats') extension to a serialized register array.
     *
     * For each schema ID in $data['schemas'], attempts SchemaMapper::find() with
     * _multitenancy: false.  On success the schema's jsonSerialize() output replaces
     * the ID.  On DoesNotExistException the original ID is kept in its position and a
     * warning is logged (orphan-ID retention per spec Decision 5).
     *
     * If '@self.stats' is also in $extend, each successfully expanded schema object
     * receives a 'stats.objects.total' value from $schemaStats.
     *
     * @param array      $data        Serialized register array (from jsonSerialize()).
     * @param array      $extend      Full _extend array (checked for '@self.stats').
     * @param array|null $schemaStats Pre-computed counts keyed by schema ID.
     *
     * @return array Updated serialized register array.
     *
     * @spec openspec/changes/extend-schemas-in-register-service/tasks.md#task-1.2
     * @spec openspec/changes/extend-schemas-in-register-service/tasks.md#task-1.3
     */
    private function applySchemaExtension(array $data, array $extend, ?array $schemaStats): array
    {
        $schemaIds = $data['schemas'] ?? [];

        if (empty($schemaIds) === true) {
            return $data;
        }

        $withStats       = in_array(needle: '@self.stats', haystack: $extend, strict: true);
        $expandedSchemas = [];

        foreach ($schemaIds as $schemaId) {
            try {
                $schema     = $this->schemaMapper->find(id: $schemaId, _multitenancy: false);
                $schemaData = $schema->jsonSerialize();

                if ($withStats === true) {
                    $count = $schemaStats[$schemaId] ?? $schemaStats[$schema->getId()] ?? null;
                    $schemaData['stats'] = [
                        'objects' => ['total' => isset($count) === true ? (int) ($count['total'] ?? 0) : 0],
                    ];
                }

                $expandedSchemas[] = $schemaData;
            } catch (DoesNotExistException $e) {
                // Orphan ID: retain in original position, do NOT drop.
                $this->logger->warning(
                    message: '[RegisterSerializer] Schema not found during expansion, retaining orphan ID',
                    context: [
                        'file'     => __FILE__,
                        'line'     => __LINE__,
                        'schemaId' => $schemaId,
                    ]
                );
                $expandedSchemas[] = $schemaId;
            }//end try
        }//end foreach

        $data['schemas'] = $expandedSchemas;

        return $data;
    }//end applySchemaExtension()
}//end class
