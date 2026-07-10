<?php

/**
 * OpenRegister Calculation Payload Builder
 *
 * Builds the evaluation payload the calculation engine consumes: the object's
 * stored data enriched with the synthetic `@self` system-metadata block, the
 * pre-resolved `@ref.<name>` cross-object references
 * (`x-openregister-references`), and the pre-resolved `@aggregate.<name>`
 * aggregate references (`x-openregister-aggregate-refs`).
 *
 * Extracted from {@see \OCA\OpenRegister\Listener\CalculationOnSaveListener}
 * so the save-time materialisation path and the temporal re-evaluation sweep
 * ({@see \OCA\OpenRegister\Service\Calculation\TemporalCalculationSweepService})
 * evaluate calculations against ONE payload shape — a drifted copy would make
 * the sweep recompute different values than the write path persists.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Calculation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/dsar-escalation-and-dpia/specs/dsar-deadline-escalation/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Calculation;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;

/**
 * Build the enriched payload materialised calculations evaluate against.
 *
 * @spec openspec/changes/dsar-escalation-and-dpia/specs/dsar-deadline-escalation/spec.md
 *   (Requirement: Time-dependent calculated fields re-evaluate without object writes)
 */
class CalculationPayloadBuilder
{
    /**
     * Constructor.
     *
     * @param ReferenceResolver          $references Cross-object reference pre-resolver.
     * @param AggregateReferenceResolver $aggregates Aggregate-reference pre-resolver.
     */
    public function __construct(
        private readonly ReferenceResolver $references,
        private readonly AggregateReferenceResolver $aggregates,
    ) {

    }//end __construct()

    /**
     * Enrich an object's data with `@self`, `@ref` and `@aggregate` blocks.
     *
     * Mirrors the save-time listener exactly: `@self` carries the entity's
     * system metadata (calculations reference `@self.created` etc. via the
     * evaluator's dotted prop path), `@ref` the pre-resolved declared
     * cross-object references, `@aggregate` the pre-resolved aggregate
     * references. Resolution is best-effort and never raises. Callers MUST
     * strip the synthetic keys before persisting
     * ({@see self::stripSyntheticKeys()}).
     *
     * @param ObjectEntity $object The object whose data to enrich.
     * @param Schema       $schema The object's schema (declares the reference blocks).
     *
     * @return array<string, mixed> The enriched evaluation payload.
     *
     * @spec openspec/changes/dsar-escalation-and-dpia/specs/dsar-deadline-escalation/spec.md
     *   (Requirement: Time-dependent calculated fields re-evaluate without object writes)
     */
    public function build(ObjectEntity $object, Schema $schema): array
    {
        $data = ($object->getObject() ?? []);

        $created          = $object->getCreated();
        $updated          = $object->getUpdated();
        $createdFormatted = null;
        if ($created !== null) {
            $createdFormatted = $created->format(\DateTimeInterface::ATOM);
        }

        $updatedFormatted = null;
        if ($updated !== null) {
            $updatedFormatted = $updated->format(\DateTimeInterface::ATOM);
        }

        $data['@self'] = [
            'id'       => $object->getUuid(),
            'uuid'     => $object->getUuid(),
            'register' => $object->getRegister(),
            'schema'   => $object->getSchema(),
            'owner'    => $object->getOwner(),
            'created'  => $createdFormatted,
            'updated'  => $updatedFormatted,
        ];

        $references = $this->configBlock(schema: $schema, key: 'x-openregister-references');
        if ($references !== null) {
            $data['@ref'] = $this->references->resolveAll(
                payload: $data,
                references: $references,
                register: $object->getRegister()
            );
        }

        $aggregateRefs = $this->configBlock(schema: $schema, key: 'x-openregister-aggregate-refs');
        if ($aggregateRefs !== null) {
            $data['@aggregate'] = $this->aggregates->resolveAll(
                payload: $data,
                aggregates: $aggregateRefs,
                registerRef: $object->getRegister()
            );
        }

        return $data;

    }//end build()

    /**
     * Strip the synthetic evaluation-only keys from a payload.
     *
     * @param array<string, mixed> $data The enriched payload.
     *
     * @return array<string, mixed> The payload without `@self` / `@ref` / `@aggregate`.
     *
     * @spec openspec/changes/dsar-escalation-and-dpia/specs/dsar-deadline-escalation/spec.md
     *   (Requirement: Time-dependent calculated fields re-evaluate without object writes)
     */
    public function stripSyntheticKeys(array $data): array
    {
        unset($data['@self'], $data['@ref'], $data['@aggregate']);

        return $data;

    }//end stripSyntheticKeys()

    /**
     * Read a non-empty array block off the schema configuration.
     *
     * @param Schema $schema The schema to inspect.
     * @param string $key    The configuration key.
     *
     * @return array<string, mixed>|null The block, or null when absent/empty.
     */
    private function configBlock(Schema $schema, string $key): ?array
    {
        $config = ($schema->getConfiguration() ?? []);
        $value  = ($config[$key] ?? null);
        if (is_array($value) === true && count($value) > 0) {
            return $value;
        }

        return null;

    }//end configBlock()
}//end class
