<?php

/**
 * PaginatedResult — the canonical list-response envelope for the
 * pluggable integration registry.
 *
 * Standardizes every integration leaf's list endpoint on a single
 * shape: `{items: array, total: int, nextCursor: ?string}`. Before
 * this value object the read contract was inconsistent — some
 * controllers returned a flat array, some `{items: [...]}` without a
 * total, some `{results, total}`, and only a couple carried a
 * `nextCursor`. That broke load-more in the frontend (e.g.
 * `CnEmailTab.hasMore` could never become true because `total` was
 * missing from the generic registry endpoint).
 *
 * The normalizer `fromMixed()` is intentionally permissive so the
 * change is additive and backward-compatible: a provider that still
 * returns a flat array, or a controller-shaped `{results, total}`
 * envelope, is coerced into the canonical shape without anyone having
 * to change their `IntegrationProvider::list()` signature.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/pluggable-integration-registry/tasks.md#task-19
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration;

/**
 * Immutable value object describing a paginated list response.
 *
 * The canonical serialized shape is:
 * ```json
 * { "items": [...], "total": 42, "nextCursor": "50" }
 * ```
 *
 * `nextCursor` is `null` when there are no further pages. For the
 * frontend's backward-compatible `data.results || data.items` reader,
 * `toArray()` additionally mirrors `items` under `results`.
 */
final class PaginatedResult
{

    /**
     * The list rows for the current page.
     *
     * @var array<int,array<string,mixed>>
     */
    public readonly array $items;

    /**
     * Total number of rows across all pages.
     *
     * @var integer
     */
    public readonly int $total;

    /**
     * Opaque cursor for the next page, or null on the last page.
     *
     * @var string|null
     */
    public readonly ?string $nextCursor;

    /**
     * Constructor.
     *
     * @param array<int,array<string,mixed>> $items      The list rows.
     * @param int                            $total      Total number of rows.
     * @param string|null                    $nextCursor Next-page cursor or null.
     *
     * @return void
     */
    public function __construct(array $items, int $total, ?string $nextCursor=null)
    {
        $this->items      = $items;
        $this->total      = $total;
        $this->nextCursor = $nextCursor;
    }//end __construct()

    /**
     * Normalize a mixed provider / service return value into the
     * canonical envelope.
     *
     * Accepts, in order of preference:
     *   - a `PaginatedResult` (returned unchanged)
     *   - an envelope array with `items` or `results` (+ optional
     *     `total` / `nextCursor`)
     *   - a flat list array (total defaults to `count()`, no nextCursor)
     *
     * When `total` is absent it falls back to the item count, and
     * `nextCursor` defaults to null — so a provider that returns a
     * plain array still produces a valid (single-page) envelope.
     *
     * @param mixed $value Raw value returned by a provider or service.
     *
     * @return self The normalized envelope.
     *
     * @spec openspec/specs/generic-integrations/spec.md#Requirement:Integration-list-responses-MUST-be-normalised-into-a-canonical-pagination-envelope
     */
    public static function fromMixed(mixed $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        if (is_array($value) === false) {
            return new self(items: [], total: 0, nextCursor: null);
        }

        // Envelope shape: has an explicit items/results key.
        if (array_key_exists('items', $value) === true || array_key_exists('results', $value) === true) {
            return self::fromEnvelope(value: $value);
        }

        // Flat list: numeric-keyed array of rows.
        return new self(items: array_values($value), total: count($value), nextCursor: null);
    }//end fromMixed()

    /**
     * Parse an envelope-shaped array (has `items` or `results` key) into a PaginatedResult.
     *
     * @param array<string,mixed> $value The envelope array.
     *
     * @return self
     */
    private static function fromEnvelope(array $value): self
    {
        $items = $value['items'] ?? $value['results'] ?? [];
        if (is_array($items) === false) {
            $items = [];
        }

        $total = count($items);
        if (array_key_exists('total', $value) === true && is_numeric($value['total']) === true) {
            $total = (int) $value['total'];
        }

        $nextCursor = null;
        if (array_key_exists('nextCursor', $value) === true && $value['nextCursor'] !== null) {
            $nextCursor = (string) $value['nextCursor'];
        }

        return new self(items: array_values($items), total: $total, nextCursor: $nextCursor);
    }//end fromEnvelope()

    /**
     * Serialize to the canonical wire shape.
     *
     * Includes a `results` mirror of `items` so existing frontend
     * readers (`data.results || data.items`) keep working without a
     * coordinated release.
     *
     * @return array{items:array<int,array<string,mixed>>,results:array<int,array<string,mixed>>,total:int,nextCursor:?string}
     *
     * @spec openspec/specs/generic-integrations/spec.md#Requirement:Integration-list-responses-MUST-be-normalised-into-a-canonical-pagination-envelope
     */
    public function toArray(): array
    {
        return [
            'items'      => $this->items,
            'results'    => $this->items,
            'total'      => $this->total,
            'nextCursor' => $this->nextCursor,
        ];
    }//end toArray()
}//end class
