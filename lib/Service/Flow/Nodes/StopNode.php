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
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\WorkflowEngine\IManager;

/**
 * Stops the run, optionally as an error.
 */
class StopNode implements IFlowNode
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
     * Nothing to validate — a stop with no message is a fine clean stop.
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
     * @param array $items   The input items (unused — the run ends).
     * @param array $config  The step configuration.
     * @param array $context Run-level metadata.
     *
     * @return array Never returns normally.
     *
     * @throws FlowStop Always — this is how the node ends the run.
     */
    public function execute(array $items, array $config, array $context): array
    {
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
