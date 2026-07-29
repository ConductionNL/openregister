<?php

/**
 * Routes each item to an output branch — per-item If / Switch.
 *
 * {@see SwitchNode} branches the whole batch: the engine picks one outgoing edge
 * by its condition and every item follows it. This node is the other kind of
 * branch, the one n8n's If and Switch actually are — it decides *per item*. Each
 * item is tested against the node's rules in order and tagged for the output of
 * the first rule it matches (or the fallback); items matching nothing with no
 * fallback are dropped. The engine then delivers each item only to the output
 * place it was tagged for ({@see FlowEngine::advanceItems()}).
 *
 * So a Router with rules -> "high" / "low" and an edge from each output splits
 * one stream of items into two, and both branches run with their own share.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Nodes
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/or-flow-per-item-routing/specs/flow-per-item-routing/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Nodes;

use OCA\OpenRegister\Service\Flow\FlowExpression;
use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\WorkflowEngine\IManager;
use UnexpectedValueException;

/**
 * Tags each item with the output branch it should go to.
 */
class RouterNode implements IFlowNode
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
     */
    public function getId(): string
    {
        return 'openregister.route';

    }//end getId()

    /**
     * Palette name.
     *
     * @return string The display name.
     */
    public function getDisplayName(): string
    {
        return $this->l10n->t('Route items');

    }//end getDisplayName()

    /**
     * Palette description.
     *
     * @return string The description.
     */
    public function getDescription(): string
    {
        return $this->l10n->t('Send each item down a different branch, chosen per item by a rule.');

    }//end getDescription()

    /**
     * Palette icon.
     *
     * @return string The icon URL.
     */
    public function getIcon(): string
    {
        return $this->urls->imagePath('core', 'actions/arrow-right.svg');

    }//end getIcon()

    /**
     * Routing grants no privilege.
     *
     * @param int $scope The scope constant.
     *
     * @return boolean Whether it is available.
     */
    public function isAvailableForScope(int $scope): bool
    {
        return in_array($scope, [IManager::SCOPE_ADMIN, IManager::SCOPE_USER], true);

    }//end isAvailableForScope()

    /**
     * Reject a router with no rules — it would route nothing.
     *
     * @param array $config The step configuration.
     *
     * @return void
     *
     * @throws UnexpectedValueException When no rules are configured.
     */
    public function validateConfig(array $config): void
    {
        if ($this->rulesOf(config: $config) === []) {
            throw new UnexpectedValueException($this->l10n->t('A router needs at least one rule.'));
        }

    }//end validateConfig()

    /**
     * Tag each item with the output it matches.
     *
     * Rules are tried in order; the first whose condition holds for the item wins
     * and the item is tagged for that rule's output. An item matching no rule
     * takes the fallback (`default`) when one is set, and is otherwise dropped —
     * the same as a Switch case with no match and no default.
     *
     * @param array $items   The input items.
     * @param array $config  The step configuration.
     * @param array $context Run-level metadata.
     *
     * @return array The items, each tagged (or dropped when unmatched).
     */
    public function execute(array $items, array $config, array $context): array
    {
        $rules    = $this->rulesOf(config: $config);
        $fallback = trim((string) ($config['default'] ?? ''));
        $count    = count($items);

        $out = [];
        foreach ($items as $index => $item) {
            $data   = FlowExpression::dataFor(
                item: (array) $item,
                itemCount: $count,
                context: $context
            );
            $output = $this->outputFor(rules: $rules, data: $data, fallback: $fallback);
            if ($output === null) {
                // No rule matched and no fallback: this item goes nowhere.
                continue;
            }

            $out[] = FlowItems::item(
                json: (array) ($item[FlowItems::JSON] ?? []),
                binary: (array) ($item[FlowItems::BINARY] ?? []),
                fromItemIndex: $index,
                output: $output
            );
        }//end foreach

        return $out;

    }//end execute()

    /**
     * The output branch an item's data selects, or null when it selects none.
     *
     * @param array<int, array> $rules    The routing rules.
     * @param array             $data     The item's expression data.
     * @param string            $fallback The default output, empty for none.
     *
     * @return string|null The chosen output, or null.
     */
    private function outputFor(array $rules, array $data, string $fallback): ?string
    {
        foreach ($rules as $rule) {
            $output = trim((string) ($rule['output'] ?? ''));
            if ($output === '') {
                continue;
            }

            if (FlowExpression::isTrue(logic: ($rule['condition'] ?? null), data: $data) === true) {
                return $output;
            }
        }

        if ($fallback !== '') {
            return $fallback;
        }

        return null;

    }//end outputFor()

    /**
     * The configured rules, as a clean list.
     *
     * @param array $config The step configuration.
     *
     * @return array<int, array> The rules.
     */
    private function rulesOf(array $config): array
    {
        $rules = ($config['rules'] ?? []);
        if (is_array($rules) === false) {
            return [];
        }

        return array_values(array_filter($rules, static fn ($rule): bool => is_array($rule) === true));

    }//end rulesOf()
}//end class
