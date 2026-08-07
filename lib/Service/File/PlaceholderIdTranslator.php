<?php

/**
 * PlaceholderIdTranslator
 *
 * This file is part of the OpenRegister app for Nextcloud.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File;

/**
 * Translates the internal, global entity id (`openregister_entities.id`) to a
 * SCOPE-LOCAL sequence number for the redaction placeholder `[<TYPE>: <id>]`.
 *
 * Detection dedups by value, so the same person carries the same global
 * `e.id` in every file and every publication — a stable cross-disclosure
 * linking key (pseudonymisation, not anonymisation; AVG Art. 4(5) / WP29
 * Op. 05/2014 linkability). This translator keeps `e.id` as the internal
 * "same person" key but emits a number that is local to a single scope and
 * never links a person across scopes.
 *
 * Two scopes:
 * - **Per-document (default):** numbers are assigned lazily by order of first
 *   appearance within one anonymise run (`perDocument()`); no persistence.
 * - **Per-dossier (opt-in):** the translator is pre-seeded with a map
 *   deterministically recomputed from the dossier's stored entity-relation
 *   rows (`forDossier()` / `withSeededMap()`), so every per-file call within
 *   the dossier resolves the same person to the same number.
 *
 * @category Service
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/openregister
 *
 * @spec openspec/changes/anonymisation-placeholder-id-scope/design.md
 */
class PlaceholderIdTranslator
{

    /**
     * Map of internal entity id (stringified) to its scope-local number.
     *
     * @var array<string, int>
     */
    private array $map = [];

    /**
     * Next sequence number to assign to a not-yet-seen entity id.
     *
     * @var integer
     */
    private int $next = 1;

    /**
     * Private constructor — use the named factory methods.
     *
     * @param array<int|string, int> $seed Pre-computed `e.id => local_number` map
     *                                     (empty for a fresh per-document translator).
     */
    private function __construct(array $seed=[])
    {
        foreach ($seed as $entityId => $number) {
            $this->map[(string) $entityId] = (int) $number;
        }

        if ($this->map !== []) {
            // Continue numbering after the highest seeded value so an entity
            // not present in the seed (defensive edge) still gets a unique,
            // non-colliding number.
            $this->next = (max($this->map) + 1);
        }

    }//end __construct()

    /**
     * Create a per-document translator: numbers assigned lazily, by order of
     * first appearance, within a single run. No persistence.
     *
     * @return self
     *
     * @spec openspec/changes/anonymisation-placeholder-id-scope/design.md (Decision 1)
     */
    public static function perDocument(): self
    {
        return new self();

    }//end perDocument()

    /**
     * Create a translator pre-seeded with an explicit `e.id => number` map.
     *
     * @param array<int|string, int> $seed The seed map.
     *
     * @return self
     *
     * @spec openspec/changes/anonymisation-placeholder-id-scope/design.md (Decision 3)
     */
    public static function withSeededMap(array $seed): self
    {
        return new self($seed);

    }//end withSeededMap()

    /**
     * Build a per-dossier translator from the dossier's stored entity-relation
     * rows. The numbering is a PURE function of the rows (Decision 3): the
     * same stored rows always yield the same map, independent of which file
     * triggered the call or the order the rows arrive in.
     *
     * @param array<int, array<string, mixed>> $rows Entity-relation rows ({entity_id, file_id, position_start}) for the dossier's files.
     *
     * @return self
     *
     * @spec openspec/changes/anonymisation-placeholder-id-scope/design.md (Decision 3)
     */
    public static function forDossier(array $rows): self
    {
        return new self(self::rankByFirstAppearance(rows: $rows));

    }//end forDossier()

    /**
     * Pure ranking core: impose the total stable order
     * `(file_id ASC, position_start ASC, entity_id ASC)` and assign each
     * distinct `entity_id` its rank of first appearance (`1, 2, 3 …`).
     *
     * Exposed (static, side-effect-free) so the ordering contract can be
     * unit-tested directly.
     *
     * @param array<int, array<string, mixed>> $rows Entity-relation rows ({entity_id, file_id, position_start}).
     *
     * @return array<string, int> Map of `e.id` (stringified) to its scope-local number.
     *
     * @spec openspec/changes/anonymisation-placeholder-id-scope/design.md (Decision 3)
     */
    public static function rankByFirstAppearance(array $rows): array
    {
        usort(
            $rows,
            static function (array $left, array $right): int {
                return [
                    (int) ($left['file_id'] ?? 0),
                    (int) ($left['position_start'] ?? 0),
                    (int) ($left['entity_id'] ?? 0),
                ] <=> [
                    (int) ($right['file_id'] ?? 0),
                    (int) ($right['position_start'] ?? 0),
                    (int) ($right['entity_id'] ?? 0),
                ];
            }
        );

        $map    = [];
        $number = 1;
        foreach ($rows as $row) {
            $entityId = (string) ($row['entity_id'] ?? '');
            if ($entityId === '' || isset($map[$entityId]) === true) {
                continue;
            }

            $map[$entityId] = $number;
            $number++;
        }

        return $map;

    }//end rankByFirstAppearance()

    /**
     * Translate an internal entity id to its scope-local number. In
     * per-document mode an unseen id is assigned the next number (first
     * appearance); in seeded (per-dossier) mode it returns the pre-computed
     * number. The same id always resolves to the same number for the life of
     * this translator (within-scope consistency).
     *
     * @param int|string $entityId The internal `openregister_entities.id`.
     *
     * @return int The scope-local sequence number.
     *
     * @spec openspec/changes/anonymisation-placeholder-id-scope/design.md (Decision 1)
     */
    public function translate(int | string $entityId): int
    {
        $key = (string) $entityId;
        if (isset($this->map[$key]) === false) {
            $this->map[$key] = $this->next;
            $this->next++;
        }

        return $this->map[$key];

    }//end translate()
}//end class
