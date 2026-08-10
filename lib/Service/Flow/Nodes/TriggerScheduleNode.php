<?php

/**
 * An entry point: this flow starts on a schedule.
 *
 * The scheduling half of {@see TriggerObjectNode} — see that class for why a
 * trigger is a node at all, and why a flow may carry several.
 *
 * A schedule trigger has no SUBJECT. There is no object that caused it, so a
 * run started here begins with no item to work on and the flow's first real
 * node has to fetch what it needs. That is the difference an author has to see,
 * and it is why the register/schema pair belongs to the object trigger rather
 * than to the flow: on a schedule those two fields have nothing to describe,
 * and offering them invites an author to fill them in and expect a filter.
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

use InvalidArgumentException;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCA\OpenRegister\Service\Flow\IFlowNodeConfigKeys;
use OCA\OpenRegister\Service\Flow\IFlowTriggerNode;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\WorkflowEngine\IManager;

/**
 * Starts the flow on a cron schedule.
 */
class TriggerScheduleNode implements IFlowNode, IFlowNodeConfigKeys, IFlowTriggerNode
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
        return 'openregister.trigger-schedule';

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
        return $this->l10n->t('On a schedule');

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
        return $this->l10n->t('Start the flow on a cron schedule. The run begins with no object — the first node has to fetch what it works on.');

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
        return $this->urls->imagePath('core', 'actions/history.svg');

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
     * The config vocabulary of a schedule trigger.
     *
     * @return array<int, string> The accepted config keys.
     *
     * @spec openspec/specs/flow-engine/spec.md#requirement-a-trigger-is-a-node-and-a-flow-may-carry-several
     */
    public function configKeys(): array
    {
        return ['cron'];

    }//end configKeys()

    /**
     * The expression is required, and must have five fields.
     *
     * The shape is checked, not the semantics: a five-field expression that
     * never matches a real minute is a scheduling question, and this node is
     * not the scheduler. What it can catch is the expression that is not a cron
     * expression at all — which otherwise sits in a saved flow that simply
     * never runs.
     *
     * @param array $config The node configuration.
     *
     * @return void
     *
     * @throws InvalidArgumentException When the cron expression is missing or malformed.
     *
     * @spec openspec/specs/flow-engine/spec.md#requirement-a-trigger-is-a-node-and-a-flow-may-carry-several
     */
    public function validateConfig(array $config): void
    {
        $cron = trim((string) ($config['cron'] ?? ''));
        if ($cron === '') {
            throw new InvalidArgumentException(
                'A schedule trigger must carry a "cron" expression — without one nothing ever starts it, which is indistinguishable from a flow with nothing to do.'
            );
        }

        $fields = preg_split('/\s+/', $cron);
        if (is_array($fields) === false || count($fields) !== 5) {
            throw new InvalidArgumentException(
                sprintf(
                    'A cron expression has five space-separated fields (minute hour day month weekday); "%s" has %d.',
                    $cron,
                    (is_array($fields) === true) ? count($fields) : 0
                )
            );
        }

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
