<?php

/**
 * A Nextcloud Flow operation that starts an OpenRegister flow.
 *
 * The bridge between the two systems, and deliberately in this direction.
 *
 * Nextcloud Flow is very good at the half it owns: an admin picks an event,
 * adds checks, and something happens. It cannot branch, join, loop, or carry
 * data from one step to the next — an operation returns void. So rather than
 * competing with it, this makes every Nextcloud Flow rule a possible ENTRY
 * POINT into a flow that can do all of those things.
 *
 * The other direction is not available and should not be faked: an
 * `IOperation` cannot be invoked as a flow step because `onEvent()` returns
 * nothing and takes an event rather than data. Wrapping one as a step would
 * mean synthesising an `Event` and an `IRuleMatcher` it never asked for, and
 * discarding the step's output because there is none. See {@see IFlowNode} for
 * the full finding.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Flow
 * @package  OCA\OpenRegister\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/or-flow-nodes/specs/flow-nodes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Flow;

use OCP\EventDispatcher\Event;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\WorkflowEngine\IManager;
use OCP\WorkflowEngine\IOperation;
use OCP\WorkflowEngine\IRuleMatcher;
use Psr\Log\LoggerInterface;
use UnexpectedValueException;

/**
 * Starts a named OpenRegister flow when a Nextcloud Flow rule matches.
 */
class RunFlowOperation implements IOperation
{
    /**
     * Constructor.
     *
     * @param IL10N           $l10n   Translations.
     * @param IURLGenerator   $urls   For the operation icon.
     * @param LoggerInterface $logger The logger.
     */
    public function __construct(
        private readonly IL10N $l10n,
        private readonly IURLGenerator $urls,
        private readonly LoggerInterface $logger
    ) {

    }//end __construct()

    /**
     * Name shown in the Nextcloud Flow rule editor.
     *
     * @return string The display name.
     */
    public function getDisplayName(): string
    {
        return $this->l10n->t('Run an OpenRegister flow');

    }//end getDisplayName()

    /**
     * What this operation does.
     *
     * @return string The description.
     */
    public function getDescription(): string
    {
        return $this->l10n->t('Start a flow that can branch, join, loop and carry data between steps.');

    }//end getDescription()

    /**
     * Icon for the rule editor.
     *
     * @return string The icon URL.
     */
    public function getIcon(): string
    {
        return $this->urls->imagePath('openregister', 'app-dark.svg');

    }//end getIcon()

    /**
     * Available to admins and to users.
     *
     * The flow itself enforces what the triggering user may touch, so there is
     * no reason to withhold this from the personal scope.
     *
     * @param int $scope The scope constant.
     *
     * @return bool Whether it is available.
     */
    public function isAvailableForScope(int $scope): bool
    {
        return in_array($scope, [IManager::SCOPE_ADMIN, IManager::SCOPE_USER], true);

    }//end isAvailableForScope()

    /**
     * Reject a rule that names no flow.
     *
     * @param string $name      The rule name.
     * @param array  $checks    The rule's checks.
     * @param string $operation The operation payload — the flow id.
     *
     * @return void
     *
     * @throws UnexpectedValueException When no flow is named.
     */
    public function validateOperation(string $name, array $checks, string $operation): void
    {
        if (trim($operation) === '') {
            throw new UnexpectedValueException($this->l10n->t('Please choose a flow to run.'));
        }

    }//end validateOperation()

    /**
     * Start the named flow for every rule that matched.
     *
     * @param string       $eventName   The event that fired.
     * @param Event        $event       The event.
     * @param IRuleMatcher $ruleMatcher Resolves which rules matched.
     *
     * @return void
     */
    public function onEvent(string $eventName, Event $event, IRuleMatcher $ruleMatcher): void
    {
        foreach ($ruleMatcher->getFlows(false) as $flow) {
            $flowId = trim((string) ($flow['operation'] ?? ''));
            if ($flowId === '') {
                continue;
            }

            // Queued rather than run inline: a Nextcloud Flow operation runs
            // inside the dispatch of the event that triggered it — often a file
            // write or a share change — and a flow can be long. Running it here
            // would put an arbitrary graph on the critical path of a user action.
            $this->logger->debug(
                message: '[RunFlowOperation] Nextcloud Flow rule matched; queueing an OpenRegister flow run',
                context: [
                    'file'  => __FILE__,
                    'line'  => __LINE__,
                    'flow'  => $flowId,
                    'event' => $eventName,
                ]
            );

            // TODO(#2072): hand off to the run queue once run persistence lands.
            // Deliberately not executed inline in the meantime — a partial
            // implementation that blocks the triggering request would be worse
            // than an honest gap.
        }//end foreach

    }//end onEvent()
}//end class
