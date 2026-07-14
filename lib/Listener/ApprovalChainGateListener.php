<?php

/**
 * OpenRegister ApprovalChainGateListener
 *
 * Subscribes to ObjectUpdatingEvent and blocks a lifecycle transition named by a
 * schema's `x-openregister-approval-chains` declaration until the provisioned
 * `ApprovalChain`'s steps are all approved. Mirrors
 * `Listener\LifecycleValidationListener`'s schema-parse-off-`getConfiguration()`
 * shape exactly, re-deriving the matched transition independently rather than
 * refactoring the shipped lifecycle listener.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/approval-chains-declarative/specs/approval-workflow/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Db\ApprovalChain;
use OCA\OpenRegister\Db\ApprovalChainMapper;
use OCA\OpenRegister\Db\ApprovalStepMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Service\ApprovalChainAnnotationInstaller;
use OCA\OpenRegister\Service\ApprovalService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Gates a declared lifecycle transition on approval-chain completion.
 *
 * @template-implements IEventListener<ObjectUpdatingEvent>
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class ApprovalChainGateListener implements IEventListener
{
    /**
     * Constructor.
     *
     * @param SchemaMapper                      $schemaMapper Schema lookup mapper.
     * @param ApprovalChainMapper                $chainMapper  Chain mapper.
     * @param ApprovalStepMapper                 $stepMapper   Step mapper.
     * @param ApprovalService                    $approvalService Provisions/inspects chain steps.
     * @param ApprovalChainAnnotationInstaller    $installer    Ensures the declared chain is provisioned.
     * @param IUserSession                        $userSession  Current user session (requester identity).
     * @param LoggerInterface                     $logger       Logger for gate diagnostics.
     */
    public function __construct(
        private readonly SchemaMapper $schemaMapper,
        private readonly ApprovalChainMapper $chainMapper,
        private readonly ApprovalStepMapper $stepMapper,
        private readonly ApprovalService $approvalService,
        private readonly ApprovalChainAnnotationInstaller $installer,
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Gate the attempted lifecycle transition against any declared approval chain.
     *
     * @param Event $event Inbound dispatcher event.
     *
     * @return void
     *
     * @spec openspec/changes/approval-chains-declarative/specs/approval-workflow/spec.md
     */
    public function handle(Event $event): void
    {
        if (($event instanceof ObjectUpdatingEvent) === false) {
            return;
        }

        $oldObject = $event->getOldObject();
        if ($oldObject === null) {
            return;
        }

        $newObject = $event->getNewObject();
        $schema    = $this->loadSchema(object: $newObject);
        if ($schema === null) {
            return;
        }

        $config = ($schema->getConfiguration() ?? []);
        $chains = ($config['x-openregister-approval-chains'] ?? null);
        if (is_array($chains) === false || $chains === []) {
            return;
        }

        $lifecycle = ($config['x-openregister-lifecycle'] ?? null);
        if (is_array($lifecycle) === false) {
            return;
        }

        $field       = (string) ($lifecycle['field'] ?? ($lifecycle['property'] ?? ''));
        $transitions = ($lifecycle['transitions'] ?? []);
        $oldData     = ($oldObject->getObject() ?? []);
        $newData     = ($newObject->getObject() ?? []);
        $oldValue    = ($oldData[$field] ?? null);
        $newValue    = ($newData[$field] ?? null);

        if ($oldValue === $newValue || is_string($newValue) === false) {
            return;
        }

        $action = $this->matchTransition(
            transitions: $transitions,
            oldValue: (string) $oldValue,
            newValue: $newValue
        );
        if ($action === null) {
            // Not a recognised transition — LifecycleValidationListener rejects
            // this on its own; the approval gate has nothing to evaluate.
            return;
        }

        foreach ($chains as $chainKey => $spec) {
            if (is_string($chainKey) === false || is_array($spec) === false) {
                continue;
            }

            if (($spec['transition'] ?? null) !== $action) {
                continue;
            }

            $blocked = $this->evaluateGate(
                event: $event,
                schema: $schema,
                chainKey: $chainKey,
                spec: $spec,
                object: $newObject,
                newData: $newData
            );
            if ($blocked === true) {
                return;
            }
        }//end foreach
    }//end handle()

    /**
     * Evaluate one declared chain against the object attempting its gated
     * transition. Returns true when the event was rejected (short-circuits the
     * caller's loop).
     *
     * @param ObjectUpdatingEvent  $event    The event being evaluated.
     * @param Schema               $schema   The object's schema.
     * @param string               $chainKey Declarative chain key.
     * @param array<string, mixed> $spec     The chain's declared spec.
     * @param ObjectEntity         $object   The object attempting the transition.
     * @param array<string, mixed> $newData  The object's new (attempted) data.
     *
     * @return bool True when the transition was blocked.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    private function evaluateGate(
        ObjectUpdatingEvent $event,
        Schema $schema,
        string $chainKey,
        array $spec,
        ObjectEntity $object,
        array $newData
    ): bool {
        // Idempotent: guarantees the chain row exists even if the installer
        // hasn't run yet for this schema revision (e.g. schema saved before
        // this capability existed).
        $this->installer->installSchema(schema: $schema);

        $schemaId = $schema->getId();
        $chain    = null;
        if ($schemaId !== null) {
            $chain = $this->chainMapper->findBySchemaAndName(schemaId: (int) $schemaId, name: $chainKey);
        }

        if ($chain === null) {
            // Declared but couldn't be provisioned (e.g. no valid approvers) —
            // fail closed, mirroring LifecycleGuardRegistry's missing-tag policy.
            $this->logger->error(
                sprintf('[ApprovalChainGateListener] chain "%s" could not be resolved for schema %s', $chainKey, (string) $schemaId)
            );
            $this->reject(
                event: $event,
                code: 'approval-chain-misconfigured',
                message: sprintf('Approval chain "%s" is declared but not provisioned.', $chainKey)
            );
            return true;
        }

        $objectUuid = (string) $object->getUuid();
        $steps      = $this->stepMapper->findByChainAndObject($chain->getId(), $objectUuid);

        if ($steps !== []) {
            $anyRejected = false;
            $allApproved = true;
            foreach ($steps as $step) {
                if ($step->getStatus() === 'rejected') {
                    $anyRejected = true;
                }

                if ($step->getStatus() !== 'approved') {
                    $allApproved = false;
                }
            }

            if ($allApproved === true) {
                // Release: every provisioned step is approved.
                return false;
            }

            if ($anyRejected === true) {
                // Terminal-failed cycle — clear it so a fresh attempt opens a
                // new cycle (resubmission), then fall through to provision.
                $this->stepMapper->deleteByChainAndObject($chain->getId(), $objectUuid);
            } else {
                // Still in progress — block again, no new rows.
                $this->reject(
                    event: $event,
                    code: 'approval-chain-pending',
                    message: sprintf('Approval chain "%s" is still pending a decision.', $chainKey)
                );
                return true;
            }
        }//end if

        // No steps yet (first attempt, or a rejected cycle was just cleared):
        // provision the applicable tier and block.
        $requesterId   = $this->userSession->getUser()?->getUID();
        $stepsOverride = $this->resolveStepsOverride(spec: $spec, newData: $newData);

        $this->approvalService->initializeChain(
            chain: $chain,
            objectUuid: $objectUuid,
            requesterId: $requesterId,
            stepsOverride: $stepsOverride
        );

        $this->reject(
            event: $event,
            code: 'approval-chain-pending',
            message: sprintf('Transition requires approval via chain "%s".', $chainKey)
        );
        return true;
    }//end evaluateGate()

    /**
     * Resolve the applicable step definitions for this object.
     *
     * When the spec declares `amountField`, selects the single `approvers` tier
     * with the highest `minAmount` that is `<=` the object's value for that
     * field. Otherwise returns `null` so `initializeChain()` falls back to the
     * chain's own static `steps` (every declared tier, unchanged behaviour).
     *
     * @param array<string, mixed> $spec    The chain's declared spec.
     * @param array<string, mixed> $newData The object's new (attempted) data.
     *
     * @return array<int, array<string, mixed>>|null Step override, or null for no routing.
     */
    private function resolveStepsOverride(array $spec, array $newData): ?array
    {
        $amountField = ($spec['amountField'] ?? null);
        $approvers   = ($spec['approvers'] ?? []);
        if (is_string($amountField) === false || $amountField === '' || is_array($approvers) === false) {
            return null;
        }

        $amount = (float) ($newData[$amountField] ?? 0);

        $best         = null;
        $bestMinAmount = -1.0;
        foreach ($approvers as $tier) {
            if (is_array($tier) === false) {
                continue;
            }

            $role      = (string) ($tier['role'] ?? '');
            $minAmount = (float) ($tier['minAmount'] ?? 0);
            if ($role === '' || $minAmount > $amount) {
                continue;
            }

            if ($best === null || $minAmount > $bestMinAmount) {
                $best          = $role;
                $bestMinAmount = $minAmount;
            }
        }

        if ($best === null) {
            return null;
        }

        return [['order' => 1, 'role' => $best]];
    }//end resolveStepsOverride()

    /**
     * Find the transition (action name) whose `to` matches the new value AND
     * whose `from` list contains the old value. Mirrors
     * `LifecycleValidationListener::findTransitionByTarget()`.
     *
     * @param array<string, mixed> $transitions Transition map from the annotation.
     * @param string               $oldValue    Current lifecycle field value.
     * @param string               $newValue    Attempted lifecycle field value.
     *
     * @return string|null The matched action name, or null.
     */
    private function matchTransition(array $transitions, string $oldValue, string $newValue): ?string
    {
        foreach ($transitions as $action => $spec) {
            if (is_array($spec) === false || ($spec['to'] ?? null) !== $newValue) {
                continue;
            }

            $from = ($spec['from'] ?? []);
            if (is_string($from) === true) {
                $from = [$from];
            }

            if (is_array($from) === true && in_array($oldValue, $from, true) === true) {
                return (string) $action;
            }
        }

        return null;
    }//end matchTransition()

    /**
     * Load the schema referenced by an object, returning null on failure.
     *
     * @param ObjectEntity $object Object whose schema reference to resolve.
     *
     * @return Schema|null Resolved schema, or null on lookup failure.
     */
    private function loadSchema(ObjectEntity $object): ?Schema
    {
        $schemaRef = $object->getSchema();
        if ($schemaRef === null || $schemaRef === '') {
            return null;
        }

        try {
            return $this->schemaMapper->find($schemaRef, _multitenancy: false);
        } catch (\Throwable $e) {
            return null;
        }
    }//end loadSchema()

    /**
     * Stop the event and stamp a structured error onto it.
     *
     * @param ObjectUpdatingEvent $event   The event being rejected.
     * @param string              $code    Structured error code.
     * @param string              $message Human-readable message.
     *
     * @return void
     */
    private function reject(ObjectUpdatingEvent $event, string $code, string $message): void
    {
        $event->setErrors(
            [
                'code'    => $code,
                'message' => $message,
            ]
        );
        $event->stopPropagation();
    }//end reject()
}//end class
