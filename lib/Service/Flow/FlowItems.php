<?php

/**
 * Normalisation for the item array that travels between flow steps.
 *
 * The engine's data channel is a LIST of items, not one object. A step receives
 * items and returns items, and a step that acts per record acts once per item.
 * This mirrors the model n8n proved out, and it is the reason a flow can express
 * "fetch 200 rows, act on each" without the author drawing a loop.
 *
 * An item is:
 *
 *   [
 *     'json'       => array,       // the record itself
 *     'binary'     => array,       // file attachments, keyed by name
 *     'pairedItem' => array|null,  // which input item produced this one
 *   ]
 *
 * `pairedItem` is what makes a run explainable: given an item at the end of a
 * flow, it is the chain back to the input that caused it. Without it, a step
 * that fans one item into ten leaves no way to answer "where did this come
 * from", which is the question every debugging session starts with.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/or-flow-engine/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

/**
 * Helpers for building and normalising flow item lists.
 */
final class FlowItems
{

    /**
     * The key carrying an item's record.
     */
    public const JSON = 'json';

    /**
     * The key carrying an item's file attachments.
     */
    public const BINARY = 'binary';

    /**
     * The key carrying an item's provenance.
     */
    public const PAIRED_ITEM = 'pairedItem';

    /**
     * Coerce whatever a step returned into a well-formed item list.
     *
     * Steps are written by consuming apps, so the engine cannot assume the
     * shape it gets back. Three authoring shapes are accepted and normalised
     * rather than rejected, because rejecting them would push the same
     * boilerplate into every dispatcher:
     *
     *   - a full item list             -> used as-is
     *   - a single item (`['json'=>…]`) -> wrapped into a one-item list
     *   - a bare record (`['a'=>1]`)    -> wrapped as that item's `json`
     *
     * A step that legitimately produces nothing returns an empty list, which
     * is meaningful: it ends that branch's data, exactly as a filter should.
     *
     * @param mixed    $value         What the step returned.
     * @param int|null $fromItemIndex Input item this output came from, when known.
     *
     * @return array<int, array<string, mixed>> A well-formed item list.
     *
     * @spec openspec/changes/or-flow-engine/specs/flow-engine/spec.md
     */
    public static function normalise(mixed $value, ?int $fromItemIndex=null): array
    {
        if (is_array($value) === false) {
            return [];
        }

        if ($value === []) {
            return [];
        }

        // A list whose members are arrays is treated as an item list; each
        // member is normalised in turn so a half-shaped list still lands well.
        if (array_is_list($value) === true) {
            $items = [];
            foreach ($value as $index => $member) {
                if (is_array($member) === false) {
                    continue;
                }

                $items[] = self::item(
                    json: (array) ($member[self::JSON] ?? $member),
                    binary: (array) ($member[self::BINARY] ?? []),
                    fromItemIndex: self::pairedIndex(member: $member, fallback: ($fromItemIndex ?? $index))
                );
            }

            return $items;
        }

        // A single item, or a bare record standing in for one.
        return [
            self::item(
                json: (array) ($value[self::JSON] ?? $value),
                binary: (array) ($value[self::BINARY] ?? []),
                fromItemIndex: self::pairedIndex(member: $value, fallback: $fromItemIndex)
            ),
        ];

    }//end normalise()

    /**
     * Build one well-formed item.
     *
     * @param array    $json          The record.
     * @param array    $binary        File attachments, keyed by name.
     * @param int|null $fromItemIndex Input item this came from, when known.
     *
     * @return array<string, mixed> The item.
     *
     * @spec openspec/changes/or-flow-engine/specs/flow-engine/spec.md
     */
    public static function item(array $json, array $binary=[], ?int $fromItemIndex=null): array
    {
        $paired = null;
        if ($fromItemIndex !== null) {
            $paired = ['item' => $fromItemIndex];
        }

        return [
            self::JSON        => $json,
            self::BINARY      => $binary,
            self::PAIRED_ITEM => $paired,
        ];

    }//end item()

    /**
     * Seed a run's item list from the object it is about.
     *
     * A run always starts with exactly one item, so a flow that never fans out
     * reads the same as the single-subject model it replaces.
     *
     * @param object $subject The object the run is about.
     *
     * @return array<int, array<string, mixed>> A one-item list.
     *
     * @spec openspec/changes/or-flow-engine/specs/flow-engine/spec.md
     */
    public static function fromSubject(object $subject): array
    {
        $json = [];
        if (method_exists($subject, 'jsonSerialize') === true) {
            $json = (array) $subject->jsonSerialize();
        } else if (method_exists($subject, 'getObject') === true) {
            $json = (array) $subject->getObject();
        } else {
            $json = get_object_vars($subject);
        }

        return [self::item(json: $json)];

    }//end fromSubject()

    /**
     * Read an already-set `pairedItem` index, falling back when absent.
     *
     * A dispatcher that tracked provenance itself keeps it; one that did not
     * gets the engine's best guess rather than nothing.
     *
     * @param array    $member   The returned member.
     * @param int|null $fallback The index to use when the member carries none.
     *
     * @return int|null The paired input index.
     *
     * @spec openspec/changes/or-flow-engine/specs/flow-engine/spec.md
     */
    private static function pairedIndex(array $member, ?int $fallback): ?int
    {
        $paired = ($member[self::PAIRED_ITEM] ?? null);
        if (is_array($paired) === true && isset($paired['item']) === true) {
            return (int) $paired['item'];
        }

        if (is_int($paired) === true) {
            return $paired;
        }

        return $fallback;

    }//end pairedIndex()
}//end class
