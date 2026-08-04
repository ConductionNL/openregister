<?php

/**
 * Keeps only the items whose condition holds.
 *
 * The simplest node that proves an item list is a list: it returns fewer items
 * than it received, and returning none is a legitimate outcome that ends the
 * branch's data rather than an error.
 *
 * The condition is evaluated PER ITEM, so one authored expression filters a
 * whole collection without the author drawing a loop.
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
 * @spec openspec/changes/or-flow-expressions/specs/flow-expressions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Nodes;

use OCA\OpenRegister\Service\Flow\FlowExpression;
use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCA\OpenRegister\Service\Flow\IFlowNodeConfigKeys;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\WorkflowEngine\IManager;
use UnexpectedValueException;

/**
 * Drops items that do not match.
 */
class FilterNode implements IFlowNode, IFlowNodeConfigKeys
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
        return 'openregister.filter';

    }//end getId()

    /**
     * Palette name.
     *
     * @return string The display name.
     */
    public function getDisplayName(): string
    {
        return $this->l10n->t('Filter');

    }//end getDisplayName()

    /**
     * Palette description.
     *
     * @return string The description.
     */
    public function getDescription(): string
    {
        return $this->l10n->t('Keep only the items matching a condition.');

    }//end getDescription()

    /**
     * Palette icon.
     *
     * @return string The icon URL.
     */
    public function getIcon(): string
    {
        return $this->urls->imagePath('core', 'actions/filter.svg');

    }//end getIcon()

    /**
     * Filtering grants no privilege, so both scopes get it.
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
     * The config vocabulary of a filter step.
     *
     * @return array<int, string> The accepted config keys.
     *
     * @spec openspec/changes/or-flow-preflight/specs/flow-preflight/spec.md
     */
    public function configKeys(): array
    {
        return ['condition'];

    }//end configKeys()

    /**
     * Reject a filter with no condition, or one that cannot be evaluated.
     *
     * A filter with no condition keeps everything, which is a step that does
     * nothing while looking like it does something.
     *
     * @param array $config The step configuration.
     *
     * @return void
     *
     * @throws UnexpectedValueException When the condition is missing or malformed.
     */
    public function validateConfig(array $config): void
    {
        $condition = ($config['condition'] ?? null);
        if ($condition === null || $condition === []) {
            throw new UnexpectedValueException($this->l10n->t('A filter needs a condition.'));
        }

        if (FlowExpression::isValid(logic: $condition) === false) {
            throw new UnexpectedValueException($this->l10n->t('That condition is not a valid expression.'));
        }

    }//end validateConfig()

    /**
     * Keep the matching items.
     *
     * Output items are re-paired to their ORIGINAL input index, not to their
     * new position, so provenance survives the drop: item 3 of the output can
     * still be traced to item 7 of the input.
     *
     * @param array $items   The input items.
     * @param array $config  The step configuration.
     * @param array $context Run-level metadata.
     *
     * @return array The items that matched.
     */
    public function execute(array $items, array $config, array $context): array
    {
        $condition = ($config['condition'] ?? null);
        $count     = count($items);

        $kept = [];
        foreach ($items as $index => $item) {
            $data = FlowExpression::dataFor(
                item: $item,
                itemIndex: $index,
                itemCount: $count,
                context: $context
            );

            if (FlowExpression::isTrue(logic: $condition, data: $data) === false) {
                continue;
            }

            $kept[] = FlowItems::item(
                json: (array) ($item[FlowItems::JSON] ?? []),
                binary: (array) ($item[FlowItems::BINARY] ?? []),
                fromItemIndex: $index
            );
        }

        return $kept;

    }//end execute()
}//end class
