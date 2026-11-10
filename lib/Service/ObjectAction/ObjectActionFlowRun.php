<?php

/**
 * Runs the flow or steps body of a checked action through the one flow engine.
 *
 * The caller has resolved the action, checked the right and `when`, and
 * validated the inputs; this runs the body as the caller. A sync run that
 * suspends answers 202 `suspended`, never a false `completed`; `mode: async`
 * answers 202 `queued` with a link to the run.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ObjectAction
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-running-an-action-validates-inputs-checks-the-right-and-the-precondition-then-executes-its-body
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ObjectAction;

use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Flow\FlowRunOptions;
use OCA\OpenRegister\Service\Flow\MacroActionBinding;
use OCP\AppFramework\Http;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The flow and steps body of the action runner.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) ObjectActionSecrets and MacroActionBinding are the
 *   request-scoped secret holder and a stateless reader, as in the runner they came from.
 *
 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-running-an-action-validates-inputs-checks-the-right-and-the-precondition-then-executes-its-body
 */
class ObjectActionFlowRun {

	/**
	 * Constructor.
	 *
	 * @param ObjectActionFlowEngines $engines  The steps store, the flow engine and the macro resolver.
	 * @param ObjectActionInputs      $inputs   The writeOnly split.
	 * @param ObjectActionRunRecorder $recorder Writes the `action.run` entry.
	 * @param LoggerInterface         $logger   Diagnostics.
	 */
	public function __construct(
		private readonly ObjectActionFlowEngines $engines,
		private readonly ObjectActionInputs $inputs,
		private readonly ObjectActionRunRecorder $recorder,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Run a flow or steps body.
	 *
	 * A writeOnly input is held aside for the run's duration and never stored
	 * in the run's context.
	 *
	 * @param ObjectActionTarget     $target     The subject, its schema and its address as the caller named it.
	 * @param ObjectActionInvocation $invocation The checked action and its inputs, defaults resolved.
	 *
	 * @return array{status: int, body: array<string, mixed>} The answer.
	 *
	 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-running-an-action-validates-inputs-checks-the-right-and-the-precondition-then-executes-its-body
	 */
	public function run(ObjectActionTarget $target, ObjectActionInvocation $invocation): array {
		$definition          = $invocation->definition;
		[$storable, $secret] = $this->inputs->split(inputs: $invocation->inputs, writeOnly: $definition->writeOnlyInputs());
		$run = new FlowBodyRun(target: $target, definition: $definition, storable: $storable);
		$ref = $this->holdSecrets(secret: $secret);

		try {
			$start = $this->start(run: $run, context: $this->context(run: $run, ref: $ref));
		} catch (Throwable $e) {
			return $this->failed(definition: $definition, error: $e);
		} finally {
			$this->releaseSecrets(ref: $ref);
		}

		return $this->answerFor(run: $run, start: $start);
	}//end run()

	/**
	 * Hold the writeOnly inputs for the run.
	 *
	 * @param array<string, mixed> $secret The writeOnly inputs.
	 *
	 * @return string|null The holding reference, or null when there is nothing to hold.
	 */
	private function holdSecrets(array $secret): ?string {
		if ($secret === []) {
			return null;
		}

		return ObjectActionSecrets::hold(values: $secret);
	}//end holdSecrets()

	/**
	 * Release held writeOnly inputs.
	 *
	 * @param string|null $ref The holding reference, or null when nothing was held.
	 *
	 * @return void
	 */
	private function releaseSecrets(?string $ref): void {
		if ($ref !== null) {
			ObjectActionSecrets::release(ref: $ref);
		}
	}//end releaseSecrets()

	/**
	 * The run context: the action, the storable inputs and, when held, the secret reference.
	 *
	 * @param FlowBodyRun $run The action and the inputs without writeOnly ones.
	 * @param string|null $ref The holding reference of the writeOnly inputs.
	 *
	 * @return array<string, mixed> The context.
	 */
	private function context(FlowBodyRun $run, ?string $ref): array {
		$context = ['action' => $run->definition->key, 'inputs' => $run->storable];
		if ($ref !== null) {
			$context[ObjectActionSecrets::CONTEXT_KEY] = $ref;
		}

		return $context;
	}//end context()

	/**
	 * The answer when the flow could not be started, logged.
	 *
	 * @param ObjectActionDefinition $definition The action.
	 * @param Throwable              $error      What went wrong.
	 *
	 * @return array{status: int, body: array<string, mixed>} The answer.
	 */
	private function failed(ObjectActionDefinition $definition, Throwable $error): array {
		$this->logger->warning(
			'[ObjectActionRunner] Action "{action}" failed: {error}',
			['action' => $definition->key, 'error' => $error->getMessage(), 'exception' => $error]
		);

		return $this->answer(status: Http::STATUS_UNPROCESSABLE_ENTITY, body: ['error' => $error->getMessage(), 'action' => $definition->key]);
	}//end failed()

	/**
	 * The stored flow a flow or steps body runs.
	 *
	 * @param Schema                 $schema     The schema.
	 * @param ObjectActionDefinition $definition The action.
	 *
	 * @return string The flow's uuid.
	 */
	private function flowUuid(Schema $schema, ObjectActionDefinition $definition): string {
		if ($definition->kind === ObjectActionDefinition::KIND_STEPS) {
			return $this->engines->steps->flowFor(schema: $schema, definition: $definition);
		}

		$flow = (string)$definition->body;
		if (MacroActionBinding::namesUuid(flow: $flow) === true) {
			return $flow;
		}

		return ($this->engines->macros->declaredFlowUuid(schema: $schema, name: $flow) ?? $flow);
	}//end flowUuid()

	/**
	 * Queue the run, and advance it unless the action is `async`.
	 *
	 * A bound flow keeps its own run authorization (`FlowService::run()`); a
	 * steps flow is the system's and is authorised by the action's right alone.
	 *
	 * @param FlowBodyRun          $run     The target (its schema and `{uuid, register, schema}` subject) and the action.
	 * @param array<string, mixed> $context The run context.
	 *
	 * @return ObjectActionFlowStart The started run.
	 */
	private function start(FlowBodyRun $run, array $context): ObjectActionFlowStart {
		$definition = $run->definition;
		$subject    = $run->target->subject();
		$sync       = ($definition->mode !== 'async');
		$flowUuid   = $this->flowUuid(schema: $run->target->schema, definition: $definition);
		if ($definition->kind === ObjectActionDefinition::KIND_STEPS) {
			$flowRun = $this->engines->flows->runForAction(
				uuid: $flowUuid,
				options: new FlowRunOptions(subject: $subject, context: $context, sync: $sync, trigger: Flow::TRIGGER_MANUAL)
			);
			return new ObjectActionFlowStart(run: $flowRun, flowUuid: $flowUuid, sync: $sync);
		}

		$flowRun = $this->engines->flows->run(uuid: $flowUuid, subject: $subject, context: $context, sync: $sync);
		return new ObjectActionFlowStart(run: $flowRun, flowUuid: $flowUuid, sync: $sync);
	}//end start()

	/**
	 * The answer for a started run, and its audit entry.
	 *
	 * @param FlowBodyRun           $run   The subject, the action and the storable inputs.
	 * @param ObjectActionFlowStart $start The started run.
	 *
	 * @return array{status: int, body: array<string, mixed>} The answer.
	 */
	private function answerFor(FlowBodyRun $run, ObjectActionFlowStart $start): array {
		$definition = $run->definition;
		if ($start->isPending() === true) {
			$this->record(run: $run, outcome: $start->outcome(outcome: $start->pendingStatus(), changes: []));
			return $this->answer(status: Http::STATUS_ACCEPTED, body: $start->pendingBody(definition: $definition));
		}

		$changes = $this->engines->macros->changesOf(run: $start->run, flowUuid: $start->flowUuid);
		$this->record(run: $run, outcome: $start->outcome(outcome: (string)$start->run->getStatus(), changes: $changes));
		if ($start->isCompleted() === false) {
			return $this->answer(status: Http::STATUS_UNPROCESSABLE_ENTITY, body: $start->endedBody(key: $definition->key));
		}

		$next = $this->engines->macros->nextForRun(flowUuid: $start->flowUuid, run: $start->run);
		return $this->answer(status: Http::STATUS_OK, body: $start->completedBody(definition: $definition, next: $next));
	}//end answerFor()

	/**
	 * Write the run's `action.run` entry.
	 *
	 * @param FlowBodyRun            $run     The subject, the action and the storable inputs.
	 * @param ObjectActionRunOutcome $outcome The outcome.
	 *
	 * @return void
	 */
	private function record(FlowBodyRun $run, ObjectActionRunOutcome $outcome): void {
		$this->recorder->record(object: $run->target->object, definition: $run->definition, inputs: $run->storable, outcome: $outcome);
	}//end record()

	/**
	 * An answer.
	 *
	 * @param int                  $status The HTTP status.
	 * @param array<string, mixed> $body   The body.
	 *
	 * @return array{status: int, body: array<string, mixed>} The answer.
	 */
	private function answer(int $status, array $body): array {
		return ['status' => $status, 'body' => $body];
	}//end answer()
}//end class
