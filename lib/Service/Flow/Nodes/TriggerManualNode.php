<?php

/**
 * An entry point: this flow is started by a person, on demand.
 *
 * The on-demand half of {@see TriggerObjectNode} — see that class for why a
 * trigger is a node at all, and why a flow may carry several.
 *
 * It carries no configuration. That is the whole point of it: a flow with no
 * trigger node at all and a flow with a manual trigger are DIFFERENT claims,
 * and only one of them is legible. The first says nothing about intent — it
 * reads as unfinished, and an author cannot tell whether the trigger is missing
 * or deliberate. The second says "a person starts this", on the canvas, where
 * the rest of the flow is.
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
 * @spec openspec/specs/flow-engine/spec.md#requirement-a-trigger-is-a-node-and-a-flow-may-carry-several
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Nodes;

use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCA\OpenRegister\Service\Flow\IFlowNodeConfigKeys;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\WorkflowEngine\IManager;

/**
 * Starts the flow when someone runs it.
 */
class TriggerManualNode implements IFlowNode, IFlowNodeConfigKeys
{
    /**
     * Constructor.
     *
     * @param IL10N         $l10n Translations.
     * @param IURLGenerator $urls For the palette icon.
     *
     * @spec openspec/specs/flow-engine/spec.md#requirement-a-trigger-is-a-node-and-a-flow-may-carry-several
     */
    public function __construct(
        private readonly IL10N $l10n,
        private readonly IURLGenerator $urls
    ) {

    }//end __construct()

    /**
     * The node type.
     *
     * @return string The id.
     *
     * @spec openspec/specs/flow-engine/spec.md#requirement-a-trigger-is-a-node-and-a-flow-may-carry-several
     */
    public function getId(): string
    {
        return 'openregister.trigger-manual';

    }//end getId()

    /**
     * Palette name.
     *
     * @return string The display name.
     *
     * @spec openspec/specs/flow-engine/spec.md#requirement-a-trigger-is-a-node-and-a-flow-may-carry-several
     */
    public function getDisplayName(): string
    {
        return $this->l10n->t('When someone runs it');

    }//end getDisplayName()

    /**
     * Palette description.
     *
     * @return string The description.
     *
     * @spec openspec/specs/flow-engine/spec.md#requirement-a-trigger-is-a-node-and-a-flow-may-carry-several
     */
    public function getDescription(): string
    {
        return $this->l10n->t('Start the flow on demand. Says "a person starts this" out loud, which a flow with no trigger at all cannot.');

    }//end getDescription()

    /**
     * Palette icon.
     *
     * @return string The icon URL.
     *
     * @spec openspec/specs/flow-engine/spec.md#requirement-a-trigger-is-a-node-and-a-flow-may-carry-several
     */
    public function getIcon(): string
    {
        return $this->urls->imagePath('core', 'actions/play.svg');

    }//end getIcon()

    /**
     * Starting a flow grants no privilege of its own.
     *
     * @param int $scope The scope constant.
     *
     * @return boolean Whether it is available.
     *
     * @spec openspec/specs/flow-engine/spec.md#requirement-a-trigger-is-a-node-and-a-flow-may-carry-several
     */
    public function isAvailableForScope(int $scope): bool
    {
        return in_array($scope, [IManager::SCOPE_ADMIN, IManager::SCOPE_USER], true);

    }//end isAvailableForScope()

    /**
     * A manual trigger takes no configuration.
     *
     * Naming the vocabulary as EMPTY is not the same as saying nothing: an
     * empty list lets the preflight report a key written here in another
     * node's dialect, which would otherwise be stored, ignored, and reported as
     * a healthy step.
     *
     * @return array<int, string> The accepted config keys — none.
     *
     * @spec openspec/specs/flow-engine/spec.md#requirement-a-trigger-is-a-node-and-a-flow-may-carry-several
     */
    public function configKeys(): array
    {
        return [];

    }//end configKeys()

    /**
     * Nothing to require.
     *
     * @param array $config The node configuration.
     *
     * @return void
     *
     * @spec openspec/specs/flow-engine/spec.md#requirement-a-trigger-is-a-node-and-a-flow-may-carry-several
     */
    public function validateConfig(array $config): void
    {

    }//end validateConfig()

    /**
     * A trigger is an entry point, not work.
     *
     * @param array $items   The input items.
     * @param array $config  The node configuration.
     * @param array $context Run-level metadata.
     *
     * @return array The items, unchanged.
     *
     * @spec openspec/specs/flow-engine/spec.md#requirement-a-trigger-is-a-node-and-a-flow-may-carry-several
     */
    public function execute(array $items, array $config, array $context): array
    {
        return $items;

    }//end execute()
}//end class
