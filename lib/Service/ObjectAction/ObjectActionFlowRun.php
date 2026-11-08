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

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
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
	 * @param ObjectEntity           $object     The subject.
	 * @param Schema                 $schema     Its schema.
	 * @param ObjectActionDefinition $definition The checked action.
	 * @param array<string, mixed>   $inputs     The inputs, defaults resolved.
	 * @param array<string, mixed>   $subject    `{uuid, register, schema}`.
	 *
	 * @return array{status: int, body: array<string, mixed>} The answer.
	 *
	 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-running-an-action-validates-inputs-checks-the-right-and-the-precondition-then-executes-its-body
	 */
	public function run(ObjectEntity $object, Schema $schema, ObjectActionDefinition $definition, array $inputs, array $subject): array {
		[$storable, $secret] = $this->inputs->split(inputs: $inputs, writeOnly: $definition->writeOnlyInputs());
		$ref     = $this->holdSecrets(secret: $secret);
		$context = $this->context(definition: $definition, storable: $storable, ref: $ref);

		try {
			$start = $this->start(schema: $schema, definition: $definition, subject: $subject, context: $context);
		} catch (Throwable $e) {
			return $this->failed(definition: $definition, error: $e);
		} finally {
			$this->releaseSecrets(ref: $ref);
		}

		return $this->answerFor(object: $object, definition: $definition, start: $start, inputs: $storable);
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
	 * @param ObjectActionDefinition $definition The action.
	 * @param array<string, mixed>   $storable   The inputs without writeOnly ones.
	 * @param string|null            $ref        The holding reference of the writeOnly inputs.
	 *
	 * @return array<string, mixed> The context.
	 */
	private function context(ObjectActionDefinition $definition, array $storable, ?string $ref): array {
		$context = ['action' => $definition->key, 'inputs' => $storable];
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
	 * @param Schema                 $schema     The schema.
	 * @param ObjectActionDefinition $definition The action.
	 * @param array<string, mixed>   $subject    `{uuid, register, schema}`.
	 * @param array<string, mixed>   $context    The run context.
	 *
	 * @return ObjectActionFlowStart The started run.
	 */
	private function start(Schema $schema, ObjectActionDefinition $definition, array $subject, array $context): ObjectActionFlowStart {
		$sync     = ($definition->mode !== 'async');
		$flowUuid = $this->flowUuid(schema: $schema, definition: $definition);
		if ($definition->kind === ObjectActionDefinition::KIND_STEPS) {
			$run = $this->engines->flows->runForAction(uuid: $flowUuid, subject: $subject, context: $context, sync: $sync);
			return new ObjectActionFlowStart(run: $run, flowUuid: $flowUuid, sync: $sync);
		}

		$run = $this->engines->flows->run(uuid: $flowUuid, subject: $subject, context: $context, sync: $sync);
		return new ObjectActionFlowStart(run: $run, flowUuid: $flowUuid, sync: $sync);
	}//end start()

	/**
	 * The answer for a started run, and its audit entry.
	 *
	 * @param ObjectEntity           $object     The subject.
	 * @param ObjectActionDefinition $definition The action.
	 * @param ObjectActionFlowStart  $start      The started run.
	 * @param array<string, mixed>   $inputs     The storable inputs.
	 *
	 * @return array{status: int, body: array<string, mixed>} The answer.
	 */
	private function answerFor(ObjectEntity $object, ObjectActionDefinition $definition, ObjectActionFlowStart $start, array $inputs): array {
		if ($start->isPending() === true) {
			$pending = $start->outcome(outcome: $start->pendingStatus(), changes: []);
			$this->recorder->record(object: $object, definition: $definition, inputs: $inputs, outcome: $pending);
			return $this->answer(status: Http::STATUS_ACCEPTED, body: $start->pendingBody(definition: $definition));
		}

		$status  = (string)$start->run->getStatus();
		$changes = $this->engines->macros->changesOf(run: $start->run, flowUuid: $start->flowUuid);
		$this->recorder->record(object: $object, definition: $definition, inputs: $inputs, outcome: $start->outcome(outcome: $status, changes: $changes));
		if ($start->isCompleted() === false) {
			return $this->answer(status: Http::STATUS_UNPROCESSABLE_ENTITY, body: $start->endedBody(key: $definition->key));
		}

		$next = $this->engines->macros->nextForRun(flowUuid: $start->flowUuid, run: $start->run);
		return $this->answer(status: Http::STATUS_OK, body: $start->completedBody(definition: $definition, next: $next));
	}//end answerFor()

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
