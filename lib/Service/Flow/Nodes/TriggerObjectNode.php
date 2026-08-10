<?php

/**
 * An entry point: this flow starts when an object event fires.
 *
 * A trigger is not a step. Nothing in a run "executes" it — it is where the
 * run BEGINS, and by the time any item reaches this node the trigger has
 * already happened. It is a node so that the thing which starts a flow is
 * visible on the canvas next to everything else the flow does, and so that a
 * flow can have SEVERAL entry points, which four columns on the flow row
 * cannot express.
 *
 * ONE EVENT, ONE REGISTER, ONE SCHEMA
 * -----------------------------------
 * A trigger names exactly one event type on exactly one register/schema pair,
 * and `validateConfig()` refuses anything else. That is a deliberate narrowing,
 * for a reason that is about the DATA and not about the lookup:
 *
 *   objects of different schemas do not carry the same JSON.
 *
 * A trigger that admitted two schemas would hand the next node two different
 * shapes under one name, and the node would read the fields it knows from
 * whichever shape happened to arrive. That does not fail — it half-works, on
 * the subset of fields the two shapes share, which is the worst outcome
 * available. Normalising differing shapes is a mapping node's job, downstream,
 * where it is written down and can be read.
 *
 * A flow that must react to two schemas therefore carries two trigger nodes,
 * each with its own mapping, converging on a common path. That is more to
 * author and it is honest about what is happening.
 *
 * The narrowing also happens to make the matching cheap — an exact triple
 * rather than a set intersection — which is what lets the resolver answer
 * "which flows want this event" without opening every flow document. That is a
 * consequence, not the reason.
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
use OCA\OpenRegister\Service\Flow\IFlowStartNode;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\WorkflowEngine\IManager;

/**
 * Starts the flow when an object is created, updated or deleted.
 */
class TriggerObjectNode implements IFlowNode, IFlowNodeConfigKeys, IFlowStartNode
{

    /**
     * The object events a trigger may name.
     *
     * Deliberately a closed list. An event name the engine never fires is a
     * trigger that is saved, looks configured, and can never start anything —
     * the failure mode a trigger cannot afford, because nothing happening looks
     * exactly like nothing needing to happen.
     *
     * @var array<int, string>
     */
    public const EVENTS = [
        'object.created',
        'object.updated',
        'object.deleted',
    ];

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
        return 'openregister.trigger-object';

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
        return $this->l10n->t('When an object changes');

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
        return $this->l10n->t(
            'Start the flow when one kind of object is created, updated or deleted. One event, one register, one schema — use a mapping node to normalise other shapes.'
        );

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
     * The config vocabulary of an object trigger.
     *
     * @return array<int, string> The accepted config keys.
     *
     * @spec openspec/specs/flow-engine/spec.md#requirement-a-trigger-is-a-node-and-a-flow-may-carry-several
     */
    public function configKeys(): array
    {
        return ['event', 'register', 'schema'];

    }//end configKeys()

    /**
     * All three keys are REQUIRED, and `event` must be one the engine fires.
     *
     * Required, not optional-with-a-default: a trigger missing its subject
     * would either match nothing (and never fire) or match everything (and fire
     * on every object in the instance). Neither is a defensible default, and
     * both are silent.
     *
     * @param array $config The node configuration.
     *
     * @return void
     *
     * @throws InvalidArgumentException When the trigger does not name exactly one subject.
     *
     * @spec openspec/specs/flow-engine/spec.md#requirement-a-trigger-is-a-node-and-a-flow-may-carry-several
     */
    public function validateConfig(array $config): void
    {
        $event = trim((string) ($config['event'] ?? ''));
        if ($event === '') {
            throw new InvalidArgumentException(
                'An object trigger must name an "event" — one of: '.implode(', ', self::EVENTS)
            );
        }

        if (in_array($event, self::EVENTS, true) === false) {
            throw new InvalidArgumentException(
                sprintf(
                    'Unknown trigger event "%s". The engine fires: %s.',
                    $event,
                    implode(', ', self::EVENTS)
                )
            );
        }

        foreach (['register', 'schema'] as $key) {
            if (trim((string) ($config[$key] ?? '')) === '') {
                throw new InvalidArgumentException(
                    sprintf(
                        'An object trigger must name one "%s". A trigger with no %s either matches nothing and never fires, or matches everything — and both are silent.',
                        $key,
                        $key
                    )
                );
            }
        }

    }//end validateConfig()

    /**
     * A trigger is an entry point, not work.
     *
     * By the time a run exists the trigger has already fired, so there is
     * nothing left for this node to do: it passes its items straight through so
     * the run continues to whatever the author wired after it.
     *
     * It deliberately does NOT re-check the subject. The resolver decided this
     * flow wanted this event before the run was queued; re-deciding here would
     * put the same rule in two places, and the copy that drifted would either
     * drop legitimate runs or admit ones the resolver rejected.
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
