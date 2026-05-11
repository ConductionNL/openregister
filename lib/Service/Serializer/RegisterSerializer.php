<?php

/**
 * OpenRegister Register Serializer
 *
 * Serializes Register entities with optional `_extend` post-processing.
 * Replaces schema-ID references with full schema objects when requested
 * and attaches per-schema object counts when both `schemas` and
 * `@self.stats` are passed.
 *
 * @category Serializer
 * @package  OCA\OpenRegister\Service\Serializer
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/extend-schemas-in-register-service/specs/register-service-extensions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Serializer;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use Psr\Log\LoggerInterface;

/**
 * Serializer for Register entities.
 *
 * Single home for `_extend` post-processing of register payloads. The HTTP
 * controller and any DI consumer go through this class so they receive
 * identical output for equivalent input.
 *
 * Recognised `_extend` keys:
 *  - `schemas`     — replace schema IDs with full schema objects
 *                    (orphan IDs are retained in their original position).
 *  - `@self.stats` — attach `stats.objects.total` to expanded schemas
 *                    (only effective alongside `schemas`).
 *
 * Unknown keys are ignored silently. The serializer never strips the
 * `properties` field on expanded schemas — consumer-side filtering stays
 * in the consumer.
 */
class RegisterSerializer
{

    /**
     * Schema mapper used to resolve schema IDs into full schema entities.
     *
     * @var SchemaMapper
     */
    private SchemaMapper $schemaMapper;

    /**
     * Logger for warnings on orphan schema IDs.
     *
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * Constructor.
     *
     * @param SchemaMapper    $schemaMapper Schema mapper for ID-to-object resolution
     * @param LoggerInterface $logger       Logger for orphan-ID warnings
     *
     * @return void
     */
    public function __construct(SchemaMapper $schemaMapper, LoggerInterface $logger)
    {
        $this->schemaMapper = $schemaMapper;
        $this->logger       = $logger;

    }//end __construct()

    /**
     * Serialize a register with optional `_extend` post-processing.
     *
     * @param Register   $register    The register entity to serialize.
     * @param array      $extend      Recognised keys: `schemas`, `@self.stats`. Unknown keys are ignored.
     * @param array|null $schemaStats Pre-computed `[schemaId => ['total' => int, ...]]` lookup. Only used
     *                                when both `schemas` and `@self.stats` are present in `$extend`.
     *
     * @return array The serialized register array.
     */
    public function serialize(Register $register, array $extend=[], ?array $schemaStats=null): array
    {
        $data = $register->jsonSerialize();

        $expandSchemas = in_array(needle: 'schemas', haystack: $extend, strict: true);
        if ($expandSchemas === true) {
            $data['schemas'] = $this->expandSchemas(
                schemaIds: $data['schemas'],
                attachStats: in_array(needle: '@self.stats', haystack: $extend, strict: true),
                schemaStats: $schemaStats
            );
        }

        return $data;

    }//end serialize()

    /**
     * Serialize many registers with optional `_extend` post-processing.
     *
     * @param Register[] $registers               Register entities to serialize.
     * @param array      $extend                  Recognised keys: `schemas`, `@self.stats`.
     * @param array|null $schemaStatsByRegisterId Pre-computed `[registerId => [schemaId => stats]]`
     *                                            lookup. Only used when both `schemas` and
     *                                            `@self.stats` are present in `$extend`.
     *
     * @return array<int, array> The serialized register arrays.
     *
     * @SuppressWarnings(PHPMD.LongVariable) `$schemaStatsByRegisterId` reflects the spec's per-register lookup key.
     */
    public function serializeMany(
        array $registers,
        array $extend=[],
        ?array $schemaStatsByRegisterId=null
    ): array {
        $result = [];
        foreach ($registers as $register) {
            // Cast to int to match the key type used when assembling `$schemaStatsByRegisterId`
            // in `RegisterService::findAllSerialized()`. PHP's numeric-string array-key coercion
            // would have handled mismatched types, but a consistent cast makes the contract obvious.
            $registerId  = (int) $register->getId();
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
     * Expand schema IDs into full schema objects, preserving orphan IDs in place.
     *
     * On `DoesNotExistException` or `MultipleObjectsReturnedException` the
     * original ID is retained in its original array position (not dropped).
     * This is a deliberate divergence from the pre-refactor controller
     * behaviour and is documented in the spec.
     *
     * @param array      $schemaIds   The schema-ID array to expand.
     * @param bool       $attachStats Whether `@self.stats` was also requested.
     * @param array|null $schemaStats Pre-computed `[schemaId => ['total' => int, ...]]` lookup.
     *
     * @return array Mixed array of schema objects (associative arrays) and bare orphan IDs.
     */
    private function expandSchemas(array $schemaIds, bool $attachStats, ?array $schemaStats): array
    {
        $expanded = [];
        foreach ($schemaIds as $schemaId) {
            try {
                $schema     = $this->schemaMapper->find(id: $schemaId, _multitenancy: false);
                $schemaData = $schema->jsonSerialize();
            } catch (DoesNotExistException | MultipleObjectsReturnedException $e) {
                $this->logger->warning(
                    message: '[RegisterSerializer] Schema unresolvable for expansion; preserving original ID',
                    context: [
                        'file'     => __FILE__,
                        'line'     => __LINE__,
                        'schemaId' => $schemaId,
                        'reason'   => $e::class,
                    ]
                );
                // Retain the orphan or ambiguous ID in its original position (typed as it came in).
                $expanded[] = $schemaId;
                continue;
            }

            if ($attachStats === true) {
                $resolvedSchemaId = $schemaData['id'];
                $statsForSchema   = ['total' => 0];
                if ($schemaStats !== null && isset($schemaStats[$resolvedSchemaId]) === true) {
                    $statsForSchema = $schemaStats[$resolvedSchemaId];
                }

                $schemaData['stats'] = ['objects' => $statsForSchema];
            }

            $expanded[] = $schemaData;
        }//end foreach

        return $expanded;

    }//end expandSchemas()
}//end class
