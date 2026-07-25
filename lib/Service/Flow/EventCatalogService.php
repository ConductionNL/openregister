<?php

/**
 * OpenRegister flow event catalog.
 *
 * Single source of truth for the triggers a declarative flow (`x-openregister-flows`)
 * may subscribe to. The visual flow builder reads this catalog (via
 * `GET /api/flow/event-catalog`) to populate its trigger palette, and
 * {@see \OCA\OpenRegister\Listener\EventCatalogListener} uses the same map to
 * route dispatched events to {@see \OCA\OpenRegister\Service\Flow\FlowActionService}.
 *
 * Every entry is a real, dispatched event that resolves to an OpenRegister
 * object (so the flow's schema selects which flows run) — there are no
 * "declared but never fired" triggers. The canonical object-lifecycle triggers
 * keep a `legacy` alias (`created`/`updated`/`deleted`) so flows authored before
 * the catalog existed continue to fire unchanged.
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
 * @spec openspec/changes/visual-flow-builder/specs/flow-builder/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

/**
 * Declares the event catalog that backs flow triggers.
 */
class EventCatalogService
{
    /**
     * The canonical trigger catalog. Order is display order.
     *
     * Each entry:
     *  - id:     stable catalog trigger id (what a flow stores as its `trigger`).
     *  - label:  human label for the builder.
     *  - group:  palette grouping.
     *  - legacy: optional pre-catalog alias that MUST keep firing the same flows.
     *
     * @var array<int, array{id: string, label: string, group: string, legacy?: string}>
     */
    private const CATALOG = [
        ['id' => 'object.created', 'label' => 'An object is created', 'group' => 'Object', 'legacy' => 'created'],
        ['id' => 'object.updated', 'label' => 'An object is updated', 'group' => 'Object', 'legacy' => 'updated'],
        ['id' => 'object.deleted', 'label' => 'An object is deleted', 'group' => 'Object', 'legacy' => 'deleted'],
        ['id' => 'object.locked', 'label' => 'An object is locked', 'group' => 'Object'],
        ['id' => 'object.unlocked', 'label' => 'An object is unlocked', 'group' => 'Object'],
        ['id' => 'object.reverted', 'label' => 'An object is reverted', 'group' => 'Object'],
        ['id' => 'object.transitioned', 'label' => 'An object changes state', 'group' => 'Object'],
        ['id' => 'file.created', 'label' => 'A file is created', 'group' => 'File'],
        ['id' => 'file.updated', 'label' => 'A file is written', 'group' => 'File'],
        ['id' => 'file.deleted', 'label' => 'A file is deleted', 'group' => 'File'],
        ['id' => 'user.created', 'label' => 'A user is created', 'group' => 'User'],
        ['id' => 'user.deleted', 'label' => 'A user is deleted', 'group' => 'User'],
    ];

    /**
     * Return the catalog for the API/builder.
     *
     * @return array<int, array{id: string, label: string, group: string, legacy?: string}>
     */
    public function getCatalog(): array
    {
        return self::CATALOG;
    }//end getCatalog()

    /**
     * Every trigger id a flow may legally store (catalog ids + legacy aliases).
     *
     * @return array<int, string>
     */
    public function knownTriggerIds(): array
    {
        $ids = [];
        foreach (self::CATALOG as $entry) {
            $ids[] = $entry['id'];
            if (isset($entry['legacy']) === true) {
                $ids[] = $entry['legacy'];
            }
        }

        return $ids;
    }//end knownTriggerIds()

    /**
     * The set of trigger strings equivalent to a dispatched trigger.
     *
     * A flow matches the dispatched trigger when its stored `trigger` is any
     * member of this set, so `object.created` and legacy `created` are
     * interchangeable and existing flows are never orphaned.
     *
     * @param string $dispatched The trigger the listener dispatched (catalog id or legacy alias).
     *
     * @return array<int, string> The equivalent trigger strings (always includes $dispatched).
     */
    public function aliasesFor(string $dispatched): array
    {
        foreach (self::CATALOG as $entry) {
            if ($entry['id'] === $dispatched || (($entry['legacy'] ?? null) === $dispatched)) {
                $set = [$entry['id']];
                if (isset($entry['legacy']) === true) {
                    $set[] = $entry['legacy'];
                }

                return $set;
            }
        }

        // Unknown/custom trigger: match only itself (round-trips unknown ids).
        return [$dispatched];
    }//end aliasesFor()
}//end class
