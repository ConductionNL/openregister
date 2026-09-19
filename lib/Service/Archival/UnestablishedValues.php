<?php

/**
 * Drops the values that establish nothing, the way the read path does.
 *
 * 🔴 THIS EXISTS BECAUSE ONE OBJECT ANSWERED IN TWO SHAPES. `GET
 * /api/objects/...` runs its whole response through
 * `ObjectsController::stripEmptyValues()` unless `_empty=true` is passed.
 * Create, update and patch answer unstripped. The archival resolver emitted
 * nulls, so `@self._retention` read differently depending on the verb, and on
 * GET `annotation.matchedRule: null` vanished: "no rule fired" became
 * indistinguishable from "this reader does not know".
 *
 * A block that has been through {@see self::without()} comes out of that strip
 * unchanged, so every verb returns it byte for byte the same. The rule mirrors
 * the strip member for member, including its one asymmetry: the items of a
 * LIST are kept (maps inside it are pruned, not removed), and only a list left
 * empty is dropped.
 *
 * It is not a general replacement for the strip. It exists so a render-layer
 * block can obey the spec's rule that a key nothing established is omitted,
 * not nulled, and so arrive the same on every verb.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Archival
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/archival-annotation-vocabulary/spec.md#requirement-get-on-an-archival-schema-row-surfaces-_retention-block
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Archival;

/**
 * Omits null, empty-string and emptied-array members, at any depth.
 */
final class UnestablishedValues {

    /**
     * The values that establish nothing, compared strictly.
     *
     * Exactly the ones `ObjectsController::stripEmptyValues()` removes from a
     * response. `false` and `0` are absent on purpose: `immutable: false` and
     * a rule index of 0 are answers, not silence.
     *
     * @var array<int, mixed>
     */
    private const NOTHING = [null, '', []];

    /**
     * Drop every member of a map that establishes nothing, at any depth.
     *
     * @param array<array-key, mixed> $values The map to prune.
     *
     * @return array<array-key, mixed> The members that establish something.
     *
     * @spec openspec/specs/archival-annotation-vocabulary/spec.md#requirement-get-on-an-archival-schema-row-surfaces-_retention-block
     */
    public function without(array $values): array {
        $kept = [];
        foreach ($values as $key => $value) {
            if (is_array($value) === true) {
                $value = $this->prunedMember(value: $value);
            }

            if (in_array($value, self::NOTHING, true) === true) {
                continue;
            }

            $kept[$key] = $value;
        }

        return $kept;
    }//end without()

    /**
     * Prune one array member the way the read path's strip does.
     *
     * A map is pruned recursively. A list keeps every item, and only the maps
     * inside it are pruned, because `stripEmptyValues()` never removes an item
     * from a list.
     *
     * @param array<array-key, mixed> $value The member to prune.
     *
     * @return array<array-key, mixed> The pruned member, possibly empty.
     *
     * @spec openspec/specs/archival-annotation-vocabulary/spec.md#requirement-get-on-an-archival-schema-row-surfaces-_retention-block
     */
    private function prunedMember(array $value): array {
        if (array_is_list($value) === false) {
            return $this->without(values: $value);
        }

        $items = [];
        foreach ($value as $item) {
            if (is_array($item) === true) {
                $item = $this->without(values: $item);
            }

            $items[] = $item;
        }

        return $items;
    }//end prunedMember()
}//end class
