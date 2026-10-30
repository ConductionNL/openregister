<?php

/**
 * Runs one action on one object: the single path every click, row action and selection takes.
 *
 * In this order, and nothing is written before the last step:
 *
 * 1. resolve the action (declared, inherited, or a lifecycle transition), or 404;
 * 2. check the action's right on this object, or 403;
 * 3. evaluate `when`, or 409 `action-precondition-unmet` with the reason;
 * 4. resolve defaults and validate the inputs, or 422 per field;
 * 5. execute the body: a transition through `TransitionEngine::transition()`
 *    (its guards, condition and transition authorization still apply), a
 *    flow or inline steps through the one flow engine with `context.inputs`.
 *
 * The run executes as the caller. A sync run that suspends answers 202
 * `suspended`, never a false `completed`; `mode: async` answers 202 `queued`
 * with a link to the run.
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

use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\Flow\FlowNextHint;
use OCA\OpenRegister\Service\Flow\FlowService;
use OCA\OpenRegister\Service\Flow\MacroActionBinding;
use OCA\OpenRegister\Service\Flow\MacroActionResolver;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCP\AppFramework\Http;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Executes a declared action or a lifecycle transition on one object.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The single run path joins the declaration, the
 *   gate, the inputs, the three bodies and the audit; splitting it would make two run paths.
 * @SuppressWarnings(PHPMD.StaticAccess) ObjectActionDefinition, ObjectActionSecrets and
 *   MacroActionBinding are stateless readers and the request-scoped secret holder.
 *
 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-running-an-action-validates-inputs-checks-the-right-and-the-precondition-then-executes-its-body
 */
class ObjectActionRunner {

	/**
	 * The error code of a precondition refusal.
	 *
	 * @var string
	 */
	public const PRECONDITION_UNMET = 'action-precondition-unmet';

	/**
	 * Constructor.
	 *
	 * @param ObjectActionDefinitions $definitions The schema's actions, inherited ones included.
	 * @param ObjectActionGate        $gate        The right and the precondition.
	 * @param ObjectActionInputs      $inputs      Defaults, validation and digest.
	 * @param ObjectActionStepsFlow   $steps       The stored flow inline steps run as.
	 * @param ObjectActionAudit       $audit       Writes the `action.run` entry.
	 * @param TransitionEngine        $transitions Runs a transition body.
	 * @param FlowService             $flows       Runs a flow or steps body.
	 * @param MacroActionResolver     $macros      Resolves a flow by name, its `next` and its changes.
	 * @param LoggerInterface         $logger      Diagnostics.
	 */
	public function __construct(
		private readonly ObjectActionDefinitions $definitions,
		private readonly ObjectActionGate $gate,
		private readonly ObjectActionInputs $inputs,
		private readonly ObjectActionStepsFlow $steps,
		private readonly ObjectActionAudit $audit,
		private readonly TransitionEngine $transitions,
		private readonly FlowService $flows,
		private readonly MacroActionResolver $macros,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The action a key names on this schema: declared, inherited, or a lifecycle transition.
	 *
	 * @param Schema       $schema The schema.
	 * @param ObjectEntity $object The object, for its register and its available moves.
	 * @param string       $key    The action key.
	 *
	 * @return ObjectActionDefinition|null The action, or null when nothing answers to the key.
	 *
	 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-running-an-action-validates-inputs-checks-the-right-and-the-precondition-then-executes-its-body
	 */
	public function resolve(Schema $schema, ObjectEntity $object, string $key): ?ObjectActionDefinition {
		$declared = $this->definitions->find(schema: $schema, key: $key, register: $object->getRegister());
		if ($declared !== null) {
			if ($declared->isRunnable() === false) {
				return null;
			}

			return $declared;
		}

		if ($this->isTransition(schema: $schema, object: $object, key: $key) === false) {
			return null;
		}

		return ObjectActionDefinition::fromDeclaration(key: $key, definition: ['name' => $key, 'transition' => $key]);
	}//end resolve()

	/**
	 * Run an action on one object.
	 *
	 * @param ObjectEntity         $object  The subject.
	 * @param Schema               $schema  Its schema.
	 * @param string               $key     The action key.
	 * @param array<string, mixed> $given   The inputs the caller sent.
	 * @param array<string, mixed> $subject `{uuid, register, schema}` as the caller named them.
	 *
	 * @return array{status: int, body: array<string, mixed>} The HTTP status and body.
	 *
	 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-running-an-action-validates-inputs-checks-the-right-and-the-precondition-then-executes-its-body
	 */
	public function run(ObjectEntity $object, Schema $schema, string $key, array $given, array $subject): array {
		$checked = $this->check(object: $object, schema: $schema, key: $key, given: $given);
		if ($checked['refusal'] !== null) {
			return $checked['refusal'];
		}

		$definition = $checked['definition'];
		if ($definition->kind === ObjectActionDefinition::KIND_TRANSITION) {
			return $this->runTransition(object: $object, definition: $definition, inputs: $checked['inputs']);
		}

		return $this->runFlow(object: $object, schema: $schema, definition: $definition, inputs: $checked['inputs'], subject: $subject);
	}//end run()

	/**
	 * Steps 1 to 4 of a run, without running: resolve, right, `when`, inputs.
	 *
	 * The run itself and a bulk job's preview ask exactly this, so a
	 * rehearsal never promises what the run would refuse.
	 *
	 * @param ObjectEntity         $object The subject.
	 * @param Schema               $schema Its schema.
	 * @param string               $key    The action key.
	 * @param array<string, mixed> $given  The inputs the caller sent.
	 *
	 * @return array{refusal: array{status: int, body: array<string, mixed>}|null, definition: ObjectActionDefinition|null, inputs: array<string, mixed>}
	 *         The refusal, or the action and its inputs with defaults resolved.
	 *
	 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-running-an-action-validates-inputs-checks-the-right-and-the-precondition-then-executes-its-body
	 */
	public function check(ObjectEntity $object, Schema $schema, string $key, array $given): array {
		$refused = static fn (int $status, array $body): array => [
			'refusal' => ['status' => $status, 'body' => $body],
			'definition' => null,
			'inputs' => [],
		];

		$definition = $this->resolve(schema: $schema, object: $object, key: $key);
		if ($definition === null) {
			return $refused(Http::STATUS_NOT_FOUND, ['error' => sprintf('No action "%s" runs on this object.', $key)]);
		}

		$right = $key;
		if ($this->definitions->find(schema: $schema, key: $key, register: $object->getRegister()) === null) {
			$right = 'update';
		}

		if ($this->gate->may(schema: $schema, right: $right, object: $object) === false) {
			return $refused(Http::STATUS_FORBIDDEN, ['error' => sprintf('You may not perform "%s" on this object.', $key)]);
		}

		$inputs = $this->inputs->withDefaults(schema: $definition->inputs, given: $given, object: $object, userId: $this->gate->userId());
		if ($this->gate->whenHolds(definition: $definition, object: $object, inputs: $inputs) === false) {
			return $refused(
				Http::STATUS_CONFLICT,
				['error' => self::PRECONDITION_UNMET, 'message' => $this->gate->reason(definition: $definition), 'action' => $key]
			);
		}

		$errors = $this->inputErrors(definition: $definition, inputs: $inputs);
		if ($errors !== []) {
			return $refused(
				Http::STATUS_UNPROCESSABLE_ENTITY,
				['error' => 'invalid-inputs', 'message' => 'The inputs do not fit this action.', 'errors' => $errors, 'action' => $key]
			);
		}

		return ['refusal' => null, 'definition' => $definition, 'inputs' => $inputs];
	}//end check()

	/**
	 * Input errors, skipping a transition that declares no input schema of its own.
	 *
	 * A plain lifecycle transition validates its own `data` allowlist, so its
	 * inputs are handed to the engine rather than refused here.
	 *
	 * @param ObjectActionDefinition $definition The action.
	 * @param array<string, mixed>   $inputs     The inputs, defaults resolved.
	 *
	 * @return list<array{field: string, message: string}> The errors.
	 */
	private function inputErrors(ObjectActionDefinition $definition, array $inputs): array {
		if ($definition->kind === ObjectActionDefinition::KIND_TRANSITION && $definition->inputs === null) {
			return [];
		}

		return $this->inputs->errors(schema: $definition->inputs, inputs: $inputs);
	}//end inputErrors()

	/**
	 * Run a transition body: one write, always sync.
	 *
	 * @param ObjectEntity           $object     The subject.
	 * @param ObjectActionDefinition $definition The action.
	 * @param array<string, mixed>   $inputs     The inputs, passed as the transition's data.
	 *
	 * @return array{status: int, body: array<string, mixed>} The answer.
	 */
	private function runTransition(ObjectEntity $object, ObjectActionDefinition $definition, array $inputs): array {
		try {
			$saved = $this->transitions->transition(objectId: (string)$object->getUuid(), action: (string)$definition->body, data: $inputs);
		} catch (NotAuthorizedException $e) {
			return $this->answer(status: Http::STATUS_FORBIDDEN, body: ['error' => $e->getMessage(), 'action' => $definition->key]);
		} catch (Throwable $e) {
			return $this->answer(status: Http::STATUS_UNPROCESSABLE_ENTITY, body: ['error' => $e->getMessage(), 'action' => $definition->key]);
		}

		$this->recordRun(object: $saved, definition: $definition, run: null, inputs: $inputs, changes: [], outcome: FlowRun::STATUS_COMPLETED);

		return $this->answer(
			status: Http::STATUS_OK,
			body: [
				'run' => null,
				'outcome' => FlowRun::STATUS_COMPLETED,
				'action' => $definition->key,
				'kind' => ObjectActionDefinition::KIND_TRANSITION,
				'next' => ($definition->next ?? FlowNextHint::STAY),
				'object' => $saved->jsonSerialize(),
			]
		);
	}//end runTransition()

	/**
	 * Run a flow or steps body through the one flow engine.
	 *
	 * @param ObjectEntity           $object     The subject.
	 * @param Schema                 $schema     Its schema.
	 * @param ObjectActionDefinition $definition The action.
	 * @param array<string, mixed>   $inputs     The inputs.
	 * @param array<string, mixed>   $subject    `{uuid, register, schema}`.
	 *
	 * @return array{status: int, body: array<string, mixed>} The answer.
	 */
	private function runFlow(ObjectEntity $object, Schema $schema, ObjectActionDefinition $definition, array $inputs, array $subject): array {
		[$storable, $secret] = $this->inputs->split(inputs: $inputs, writeOnly: $definition->writeOnlyInputs());
		$context = ['action' => $definition->key, 'inputs' => $storable];
		$ref = null;
		if ($secret !== []) {
			$ref = ObjectActionSecrets::hold(values: $secret);
			$context[ObjectActionSecrets::CONTEXT_KEY] = $ref;
		}

		$sync = ($definition->mode !== 'async');

		try {
			$flowUuid = $this->flowUuid(schema: $schema, definition: $definition);
			$run = $this->startRun(definition: $definition, flowUuid: $flowUuid, subject: $subject, context: $context, sync: $sync);
		} catch (Throwable $e) {
			$this->logger->warning(
				'[ObjectActionRunner] Action "{action}" failed: {error}',
				['action' => $definition->key, 'error' => $e->getMessage(), 'exception' => $e]
			);

			return $this->answer(status: Http::STATUS_UNPROCESSABLE_ENTITY, body: ['error' => $e->getMessage(), 'action' => $definition->key]);
		} finally {
			if ($ref !== null) {
				ObjectActionSecrets::release(ref: $ref);
			}
		}

		return $this->flowAnswer(object: $object, definition: $definition, run: $run, flowUuid: $flowUuid, inputs: $storable, sync: $sync);
	}//end runFlow()

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
			return $this->steps->flowFor(schema: $schema, definition: $definition);
		}

		$flow = (string)$definition->body;
		if (MacroActionBinding::namesUuid(flow: $flow) === true) {
			return $flow;
		}

		return ($this->macros->declaredFlowUuid(schema: $schema, name: $flow) ?? $flow);
	}//end flowUuid()

	/**
	 * Queue the run, and advance it when sync.
	 *
	 * A bound flow keeps its own run authorization (`FlowService::run()`); a
	 * steps flow is the system's and is authorised by the action's right alone.
	 *
	 * @param ObjectActionDefinition $definition The action.
	 * @param string                 $flowUuid   The flow.
	 * @param array<string, mixed>   $subject    `{uuid, register, schema}`.
	 * @param array<string, mixed>   $context    The run context.
	 * @param bool                   $sync       Whether to advance it now.
	 *
	 * @return FlowRun The run.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) $sync chooses who advances the run, as in FlowService::run().
	 */
	private function startRun(ObjectActionDefinition $definition, string $flowUuid, array $subject, array $context, bool $sync): FlowRun {
		if ($definition->kind === ObjectActionDefinition::KIND_STEPS) {
			return $this->flows->runForAction(uuid: $flowUuid, subject: $subject, context: $context, sync: $sync);
		}

		return $this->flows->run(uuid: $flowUuid, subject: $subject, context: $context, sync: $sync);
	}//end startRun()

	/**
	 * The answer for a flow or steps run, and its audit entry.
	 *
	 * @param ObjectEntity           $object     The subject.
	 * @param ObjectActionDefinition $definition The action.
	 * @param FlowRun                $run        The run.
	 * @param string                 $flowUuid   The flow.
	 * @param array<string, mixed>   $inputs     The storable inputs.
	 * @param bool                   $sync       Whether it was advanced now.
	 *
	 * @return array{status: int, body: array<string, mixed>} The answer.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) $sync says which answer the run earned.
	 */
	private function flowAnswer(
		ObjectEntity $object,
		ObjectActionDefinition $definition,
		FlowRun $run,
		string $flowUuid,
		array $inputs,
		bool $sync,
	): array {
		$runUuid = (string)$run->getUuid();
		$status = (string)$run->getStatus();
		$links = ['run' => '/api/flow-runs/' . $runUuid];

		if ($sync === false || $status === FlowRun::STATUS_SUSPENDED) {
			$answer = FlowRun::STATUS_QUEUED;
			if ($sync === true) {
				$answer = FlowRun::STATUS_SUSPENDED;
			}

			$this->recordRun(object: $object, definition: $definition, run: $runUuid, inputs: $inputs, changes: [], outcome: $answer);

			return $this->answer(
				status: Http::STATUS_ACCEPTED,
				body: ['run' => $runUuid, 'status' => $answer, 'action' => $definition->key, 'kind' => $definition->kind, 'links' => $links]
			);
		}

		$changes = $this->macros->changesOf(run: $run, flowUuid: $flowUuid);
		$this->recordRun(object: $object, definition: $definition, run: $runUuid, inputs: $inputs, changes: $changes, outcome: $status);

		if ($status !== FlowRun::STATUS_COMPLETED) {
			$error = (string)$run->getError();
			if ($error === '') {
				$error = sprintf('The run ended %s.', $status);
			}

			return $this->answer(
				status: Http::STATUS_UNPROCESSABLE_ENTITY,
				body: [
					'error' => $error,
					'run' => $runUuid,
					'outcome' => $status,
					'action' => $definition->key,
					'links' => $links,
				]
			);
		}

		return $this->answer(
			status: Http::STATUS_OK,
			body: [
				'run' => $runUuid,
				'outcome' => $status,
				'action' => $definition->key,
				'kind' => $definition->kind,
				'next' => $this->macros->nextForRun(flowUuid: $flowUuid, run: $run),
				'links' => $links,
			]
		);
	}//end flowAnswer()

	/**
	 * Whether a key names a lifecycle transition of this schema.
	 *
	 * @param Schema       $schema The schema.
	 * @param ObjectEntity $object The object, for provider and graph modes.
	 * @param string       $key    The key.
	 *
	 * @return bool True when it does.
	 */
	private function isTransition(Schema $schema, ObjectEntity $object, string $key): bool {
		if ($this->transitions->declaredInputs(schema: $schema, action: $key) !== null) {
			return true;
		}

		try {
			foreach ($this->transitions->availableActions((string)$object->getUuid()) as $move) {
				if ((string)($move['action'] ?? '') === $key) {
					return true;
				}
			}
		} catch (Throwable) {
			return false;
		}

		return false;
	}//end isTransition()

	/**
	 * Write the `action.run` entry.
	 *
	 * @param ObjectEntity                                  $object     The subject.
	 * @param ObjectActionDefinition                        $definition The action.
	 * @param string|null                                   $run        The run.
	 * @param array<string, mixed>                          $inputs     The storable inputs.
	 * @param array<int, array{step: string, kind: string}> $changes    The changes.
	 * @param string                                        $outcome    The outcome.
	 *
	 * @return void
	 */
	private function recordRun(
		ObjectEntity $object,
		ObjectActionDefinition $definition,
		?string $run,
		array $inputs,
		array $changes,
		string $outcome,
	): void {
		[$storable] = $this->inputs->split(inputs: $inputs, writeOnly: $definition->writeOnlyInputs());

		$this->audit->record(
			object: $object,
			action: $definition->key,
			kind: (string)$definition->kind,
			run: $run,
			inputsDigest: $this->inputs->digest(storable: $storable),
			changes: $changes,
			outcome: $outcome,
			userId: $this->gate->userId()
		);
	}//end recordRun()

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
