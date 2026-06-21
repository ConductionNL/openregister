<?php

/**
 * OpenRegister RegisterSerializer
 *
 * Entity-level serializer for Register entities with `_extend` support.
 * Lives in the `lib/Service/Serializer/` namespace, the first inhabitant
 * of a folder that will host future entity serializers
 * (`SchemaSerializer`, `ObjectSerializer`).
 *
 * The serializer owns the schema-expansion + per-schema stats logic
 * that used to live inline in `RegistersController::index()`, so HTTP
 * consumers and DI consumers receive identical post-processed data.
 * `Register::jsonSerialize()` stays ID-only by contract; expansion is
 * an opt-in serializer step.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Serializer
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/extend-schemas-in-register-service/specs/register-service-extensions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Serializer;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use Psr\Log\LoggerInterface;

/**
 * Serialize Register entities with optional `_extend` post-processing.
 *
 * Supported `_extend` keys:
 * - `schemas` — replace schema IDs with hydrated schema objects.
 * - `@self.stats` — attach per-schema `stats.objects.total` counts
 *   (only meaningful alongside `schemas`).
 *
 * Unknown keys are silently ignored. Orphan schema IDs (schema not
 * found in the DB) are retained in their original array position; the
 * serializer logs a warning and does not throw.
 *
 * @spec openspec/changes/extend-schemas-in-register-service/specs/register-service-extensions/spec.md
 */
final class RegisterSerializer
{
    /**
     * Wire mappers + logger via constructor DI.
     *
     * @param SchemaMapper    $schemaMapper Schema lookup mapper.
     * @param LoggerInterface $logger       Logger for orphan-ID warnings.
     */
    public function __construct(
        private readonly SchemaMapper $schemaMapper,
        private readonly LoggerInterface $logger,
    ) {

    }//end __construct()

    /**
     * Serialize a single Register entity with optional extensions.
     *
     * @param Register                         $register    The Register entity to serialize.
     * @param array                            $extend      Extension keys to apply (`schemas`, `@self.stats`).
     * @param array<int,array{total:int}>|null $schemaStats Pre-computed per-schema object counts (id → ['total' => int]).
     *
     * @return array Serialized register payload, with extensions applied.
     *
     * @spec openspec/changes/extend-schemas-in-register-service/specs/register-service-extensions/spec.md
     *   (Requirement: schemas extension SHALL replace schema IDs with full schema objects)
     */
    public function serialize(Register $register, array $extend=[], ?array $schemaStats=null): array
    {
        $data        = $register->jsonSerialize();
        $wantSchemas = in_array('schemas', $extend, true);
        $wantStats   = in_array('@self.stats', $extend, true);

        if ($wantSchemas === true) {
            $data['schemas'] = $this->expandSchemas(ids: ($data['schemas'] ?? []), attachStats: $wantStats, schemaStats: $schemaStats);
        }

        return $data;

    }//end serialize()

    /**
     * Serialize a collection of Register entities.
     *
     * @param Register[]                                  $registers               Registers to serialize.
     * @param array                                       $extend                  Extension keys to apply.
     * @param array<int,array<int,array{total:int}>>|null $schemaStatsByRegisterId Pre-computed per-register stats
     *                                                                             (registerId → schemaId →
     *                                                                             ['total' => int]).
     *
     * @return array<int,array> Serialized payload for each register.
     *
     * @spec openspec/changes/extend-schemas-in-register-service/specs/register-service-extensions/spec.md
     */
    public function serializeMany(
        array $registers,
        array $extend=[],
        ?array $schemaStatsByRegisterId=null,
    ): array {
        $out = [];
        foreach ($registers as $register) {
            $registerId = (int) $register->getId();
            $stats      = null;
            if ($schemaStatsByRegisterId !== null
                && isset($schemaStatsByRegisterId[$registerId]) === true
            ) {
                $stats = $schemaStatsByRegisterId[$registerId];
            }

            $out[] = $this->serialize(register: $register, extend: $extend, schemaStats: $stats);
        }

        return $out;

    }//end serializeMany()

    /**
     * Expand a `schemas` ID array to schema objects, optionally annotating with stats.
     *
     * Orphan IDs (SchemaMapper::find throws DoesNotExistException) are
     * retained in their original position with their original PHP type
     * preserved. The serializer logs a warning for each orphan.
     *
     * @param array                            $ids         Schema ID array (int|string).
     * @param bool                             $attachStats Whether to attach per-schema stats to expanded entries.
     * @param array<int,array{total:int}>|null $schemaStats Pre-computed per-schema stats (id → ['total'
     *                                                      => int]).
     *
     * @return array Heterogeneous array of schema objects + retained orphan IDs.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Handles three branches per ID (resolved/orphan/stats-on)
     * which produce the smallest combined surface; splitting them duplicates the loop scaffold.
     */
    private function expandSchemas(array $ids, bool $attachStats, ?array $schemaStats): array
    {
        $expanded = [];
        foreach ($ids as $schemaId) {
            try {
                // Match the original controller's call shape: bypass
                // multitenancy when expanding schemas for registers (a
                // register may legitimately reference a schema that is
                // not directly visible in the caller's tenant — the
                // expansion is read-only metadata).
                $schema     = $this->schemaMapper->find(id: $schemaId, _multitenancy: false);
                $schemaJson = $schema->jsonSerialize();
            } catch (DoesNotExistException $e) {
                // Preserve the orphan ID at its original position +
                // original type so typed JSON clients can still see it
                // even if they have to switch decoder shape on the
                // edge case. Log warning and continue.
                $this->logger->warning(
                    message: '[RegisterSerializer] Schema not found for expansion — retaining orphan ID',
                    context: [
                        'file'     => __FILE__,
                        'line'     => __LINE__,
                        'schemaId' => $schemaId,
                    ]
                );
                $expanded[] = $schemaId;
                continue;
            }//end try

            if ($attachStats === true) {
                $idForLookup = $schemaJson['id'] ?? null;
                $count       = 0;
                if ($idForLookup !== null
                    && $schemaStats !== null
                    && isset($schemaStats[$idForLookup]) === true
                    && isset($schemaStats[$idForLookup]['total']) === true
                ) {
                    $count = (int) $schemaStats[$idForLookup]['total'];
                }

                $schemaJson['stats'] = [
                    'objects' => ['total' => $count],
                ];
            }

            $expanded[] = $schemaJson;
        }//end foreach

        return $expanded;

    }//end expandSchemas()
}//end class
