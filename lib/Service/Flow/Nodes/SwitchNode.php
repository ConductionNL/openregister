<?php

/**
 * The visible anchor for a branch — If / Switch.
 *
 * The routing itself is not done here: the engine picks which outgoing edge to
 * follow by evaluating each edge's `condition`, so a Switch is just a node with
 * several conditioned edges leaving it. This node is the thing an author drops
 * on the canvas and draws those edges from; it passes its items straight
 * through, unchanged, to whichever branch the engine selects.
 *
 * Keeping the decision on the edges rather than in a node is deliberate: it
 * means any node can branch (an agent step can have a "succeeded" and a
 * "failed" edge), and the palette entry is a convenience, not a requirement.
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
 * @spec openspec/changes/or-flow-logic/specs/flow-logic/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Nodes;

use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCA\OpenRegister\Service\Flow\IFlowNodeConfigKeys;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\WorkflowEngine\IManager;

/**
 * A pass-through node whose outgoing edges carry the routing conditions.
 */
class SwitchNode implements IFlowNode, IFlowNodeConfigKeys
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
        return 'openregister.switch';

    }//end getId()

    /**
     * Palette name.
     *
     * @return string The display name.
     */
    public function getDisplayName(): string
    {
        return $this->l10n->t('Switch');

    }//end getDisplayName()

    /**
     * Palette description.
     *
     * @return string The description.
     */
    public function getDescription(): string
    {
        return $this->l10n->t('Send the flow down one of several branches, chosen by the condition on each.');

    }//end getDescription()

    /**
     * Palette icon.
     *
     * @return string The icon URL.
     */
    public function getIcon(): string
    {
        return $this->urls->imagePath('core', 'actions/caret.svg');

    }//end getIcon()

    /**
     * Branching grants no privilege.
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
     * A switch reads NO config — its conditions live on its outgoing edges.
     *
     * The empty list is a real statement, not a stub: any key at all on a
     * switch step is one nothing will ever read. An author who writes
     * `config.rules` here has written a route and drawn a switch, and the flow
     * will pass every item down the first edge without complaint.
     *
     * @return array<int, string> The accepted config keys — none.
     *
     * @spec openspec/changes/or-flow-preflight/specs/flow-preflight/spec.md
     */
    public function configKeys(): array
    {
        return [];

    }//end configKeys()

    /**
     * Nothing to validate on the node — the conditions live on its edges.
     *
     * @param array $config The step configuration.
     *
     * @return void
     *
     * @spec openspec/changes/or-flow-logic/specs/flow-logic/spec.md
     */
    public function validateConfig(array $config): void
    {

    }//end validateConfig()

    /**
     * Pass items through untouched; the engine routes them.
     *
     * @param array $items   The input items.
     * @param array $config  The step configuration.
     * @param array $context Run-level metadata.
     *
     * @return array The items, unchanged.
     */
    public function execute(array $items, array $config, array $context): array
    {
        return $items;

    }//end execute()
}//end class
