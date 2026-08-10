<?php

/**
 * Ends the run deliberately — n8n's "Stop And Error".
 *
 * A flow sometimes needs to stop on purpose: a guard condition was met, a
 * precondition failed, a branch should go no further. This node throws
 * {@see FlowStop}, which the engine turns into a clean `stopped` or, when the
 * node is configured as an error, a `failed` run carrying the message.
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

use OCA\OpenRegister\Service\Flow\FlowStop;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCA\OpenRegister\Service\Flow\IFlowNodeConfigKeys;
use OCA\OpenRegister\Service\Flow\IFlowStopNode;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\WorkflowEngine\IManager;

/**
 * Stops the run, optionally as an error.
 */
class StopNode implements IFlowNode, IFlowNodeConfigKeys, IFlowStopNode
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
        return 'openregister.stop';

    }//end getId()

    /**
     * Palette name.
     *
     * @return string The display name.
     */
    public function getDisplayName(): string
    {
        return $this->l10n->t('Stop');

    }//end getDisplayName()

    /**
     * Palette description.
     *
     * @return string The description.
     */
    public function getDescription(): string
    {
        return $this->l10n->t('End the flow here, optionally as an error with a message.');

    }//end getDescription()

    /**
     * Palette icon.
     *
     * @return string The icon URL.
     */
    public function getIcon(): string
    {
        return $this->urls->imagePath('core', 'actions/close.svg');

    }//end getIcon()

    /**
     * Stopping grants no privilege.
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
     * The config vocabulary of a stop step.
     *
     * Both keys are OPTIONAL — a stop with no config is a perfectly good "end
     * this branch here" — which is why `validateConfig()` below has nothing to
     * require and why an empty body was, on its own terms, correct. It is also
     * why this node was the one that let `{"status": "...", "reason": "..."}`
     * through: a required-key check cannot object to a config with no required
     * keys. Naming the vocabulary is the only thing that can.
     *
     * @return array<int, string> The accepted config keys.
     *
     * @spec openspec/changes/or-flow-preflight/specs/flow-preflight/spec.md
     */
    public function configKeys(): array
    {
        return ['error', 'message'];

    }//end configKeys()

    /**
     * Nothing to REQUIRE — a stop with no message is a fine clean stop.
     *
     * Empty, and correct to be: this node has no mandatory key, so there is
     * nothing here to miss. The check that catches a stop step written in
     * another node's dialect is {@see self::configKeys()} above, which is a
     * different question and needed a different method to ask it.
     *
     * @param array $config The step configuration.
     *
     * @return void
     */
    public function validateConfig(array $config): void
    {

    }//end validateConfig()

    /**
     * End the run.
     *
     * Ends the run when it receives items. An EMPTY item list means this
     * branch was not taken, and the node passes through instead — see the
     * comment in the body for why that distinction is load-bearing.
     *
     * @param array $items   The input items — empty means "this branch was not taken".
     * @param array $config  The step configuration.
     * @param array $context Run-level metadata.
     *
     * @return array The empty item list, when there was nothing to stop for.
     *
     * @throws FlowStop When items reached it — this is how the node ends the run.
     */
    public function execute(array $items, array $config, array $context): array
    {
        // A stop that received NOTHING is a branch that was not taken.
        //
        // `FlowEngine::advanceItems()` marks every place on a firing
        // transition's `to` list and then distributes items to them by output
        // tag — so after a route, the branch the router did NOT choose is
        // marked and holds zero items. Its steps still fire. For an item-driven
        // node that is harmless (zero items, zero work; `SourceCallNode` even
        // short-circuits explicitly). For a stop it was not: the node threw
        // regardless, so a graph with a refusal stop on each guard branch ended
        // its run on a guard that had not tripped.
        //
        // Measured on hydra's commit-by-API flow: every step through
        // `move-ref` completed, the branch ref genuinely moved on GitHub — and
        // the run reported `failed` with "the branch tip moved while the commit
        // was being built". The opposite of what happened, and the failure the
        // rail exists to report is exactly the one it falsely claimed. A caller
        // reading the run status would roll back a commit that was correct.
        //
        // Refusing on an empty branch also cannot be what an author meant: they
        // wrote the stop to describe a condition, and no items reaching it means
        // the condition did not select anything.
        if ($items === []) {
            return [];
        }

        $isError = (($config['error'] ?? false) === true);
        $message = trim((string) ($config['message'] ?? ''));
        if ($message === '') {
            $message = 'Flow stopped';
            if ($isError === true) {
                $message = 'Flow stopped with an error';
            }
        }

        throw new FlowStop(reason: $message, isError: $isError);

    }//end execute()
}//end class
