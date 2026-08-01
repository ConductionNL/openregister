<?php

/**
 * The "Explode" flow node — one item per element of an array field.
 *
 * The item model's missing primitive. Every node here already acts once per
 * ITEM, so the engine fans out naturally over a collection — but only when the
 * collection already IS the item list. Nothing turned an ARRAY SITTING ON an
 * item into items, and until now nothing could.
 *
 * That gap is not academic. It is the difference between a flow that can read a
 * list and one that can act on it:
 *
 *   - `object-read` fans out over OBJECTS it fetched (`fanOut: true`), not over
 *     data already on the item;
 *   - `loop` batches the EXISTING items into slices; it does not create any;
 *   - `filter` selects items; `switch` routes them; `merge` combines them.
 *
 * So a step that produced `{findings: [a, b, c]}` could be followed only by
 * something acting on the whole array at once. hydra's reviewer is exactly that
 * shape — it emits findings and the pipeline must file ONE ISSUE PER FINDING —
 * and expressing it needed either this node or a bespoke loop in shell, which
 * is the thing the flow port exists to remove.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Nodes
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Nodes;

use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\WorkflowEngine\IManager;
use UnexpectedValueException;

/**
 * Turns an array field into one item per element.
 *
 * @spec openspec/changes/or-flow-nodes/specs/flow-nodes/spec.md
 */
class ExplodeNode implements IFlowNode
{
    /**
     * Constructor.
     *
     * @param IL10N         $l10n Translations.
     * @param IURLGenerator $urls For the palette icon.
     */
    public function __construct(
        private readonly IL10N $l10n,
        private readonly IURLGenerator $urls
    ) {
    }//end __construct()

    /**
     * The step type.
     *
     * @return string The id.
     *
     * @spec openspec/changes/or-flow-nodes/specs/flow-nodes/spec.md
     */
    public function getId(): string
    {
        return 'openregister.explode';

    }//end getId()

    /**
     * Palette name.
     *
     * @return string The display name.
     *
     * @spec openspec/changes/or-flow-nodes/specs/flow-nodes/spec.md
     */
    public function getDisplayName(): string
    {
        return $this->l10n->t('Explode');

    }//end getDisplayName()

    /**
     * Palette description.
     *
     * @return string The description.
     *
     * @spec openspec/changes/or-flow-nodes/specs/flow-nodes/spec.md
     */
    public function getDescription(): string
    {
        return $this->l10n->t('Turn a list on the item into one item per entry, so later steps act on each.');

    }//end getDescription()

    /**
     * Palette icon.
     *
     * @return string The icon URL.
     *
     * @spec openspec/changes/or-flow-nodes/specs/flow-nodes/spec.md
     */
    public function getIcon(): string
    {
        return $this->urls->imagePath('openregister', 'app-dark.svg');

    }//end getIcon()

    /**
     * Available in both scopes.
     *
     * @param int $scope The scope constant.
     *
     * @return boolean Whether it is available.
     *
     * @spec openspec/changes/or-flow-nodes/specs/flow-nodes/spec.md
     */
    public function isAvailableForScope(int $scope): bool
    {
        return in_array($scope, [IManager::SCOPE_ADMIN, IManager::SCOPE_USER], true);

    }//end isAvailableForScope()

    /**
     * Reject an explode that names no path.
     *
     * @param array $config The step configuration.
     *
     * @return void
     *
     * @throws UnexpectedValueException When no path is named.
     *
     * @spec openspec/changes/or-flow-nodes/specs/flow-nodes/spec.md
     */
    public function validateConfig(array $config): void
    {
        if (trim((string) ($config['path'] ?? '')) === '') {
            throw new UnexpectedValueException($this->l10n->t('An explode step needs the path of a list.'));
        }

    }//end validateConfig()

    /**
     * Emit one item per element of the named list.
     *
     * The element is placed under `as` (default `item`) on a COPY of the
     * original record, so an exploded entry keeps the context it came from —
     * a finding still knows its repository and its run. Dropping the rest would
     * make the node unusable for anything except a list of complete records.
     *
     * An item whose path holds no list contributes NOTHING and is not an error:
     * "the reviewer found nothing" is the ordinary case, and failing the step on
     * an empty list would turn a clean review into a broken run. An item whose
     * path holds a SCALAR is a different matter — that is a mis-authored flow,
     * and it fails.
     *
     * @param array $items   The input items.
     * @param array $config  The step configuration.
     * @param array $context Run-level metadata.
     *
     * @return array One item per element, across all input items.
     *
     * @throws UnexpectedValueException When the path holds a scalar.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter) `$context` is part of the
     *   IFlowNode contract; this node needs no run metadata to fan out a list.
     * @SuppressWarnings(PHPMD.StaticAccess)          `FlowItems::item()` is the item
     *   constructor every node uses; injecting a factory for it would add a
     *   dependency to describe a shape.
     *
     * @spec openspec/changes/or-flow-nodes/specs/flow-nodes/spec.md
     */
    public function execute(array $items, array $config, array $context): array
    {
        // `validateConfig()` runs only when a flow is SAVED, and a seeded or
        // imported flow reaches here unvalidated — at which point returning the
        // items unchanged would make this a silent pass-through that looks
        // exactly like a list of one.
        $this->validateConfig(config: $config);

        $path  = trim((string) $config['path']);
        $under = (string) ($config['as'] ?? 'item');
        $keep  = (($config['keepRecord'] ?? true) !== false);

        $out = [];
        foreach ($items as $index => $item) {
            $json = (array) ($item[FlowItems::JSON] ?? []);
            $list = $this->valueAt(json: $json, path: $path);

            if ($list === null) {
                continue;
            }

            if (is_array($list) === false) {
                throw new UnexpectedValueException(
                    $this->l10n->t('The explode path "%s" is not a list.', [$path])
                );
            }

            foreach ($list as $entry) {
                $record = [];
                if ($keep === true) {
                    $record = $json;
                }

                $record[$under] = $entry;

                $out[] = FlowItems::item(json: $record, fromItemIndex: $index);
            }
        }//end foreach

        return $out;

    }//end execute()

    /**
     * The value at a dotted path, or null when absent.
     *
     * @param array  $json The item's record.
     * @param string $path The dotted path.
     *
     * @return mixed The value, or null.
     *
     * @spec openspec/changes/or-flow-nodes/specs/flow-nodes/spec.md
     */
    private function valueAt(array $json, string $path)
    {
        $value = $json;
        foreach (explode('.', $path) as $segment) {
            if (is_array($value) === false || array_key_exists($segment, $value) === false) {
                return null;
            }

            $value = $value[$segment];
        }

        return $value;

    }//end valueAt()
}//end class
