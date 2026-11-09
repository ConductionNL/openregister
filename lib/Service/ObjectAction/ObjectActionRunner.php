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
use OCA\OpenRegister\Exception\PropertyAlreadySetException;
use OCA\OpenRegister\Service\Flow\FlowNextHint;
use OCP\AppFramework\Http;
use Throwable;

/**
 * Executes a declared action or a lifecycle transition on one object.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The single run path joins the declaration, the
 *   gate, the inputs, the three bodies and the audit; splitting it would make two run paths.
 * @SuppressWarnings(PHPMD.StaticAccess) ObjectActionDefinition is a stateless reader.
 *
 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-running-an-action-validates-inputs-checks-the-right-and-the-precondition-then-executes-its-body
 */
class ObjectActionRunner implements ObjectActionRunnerInterface {

	/**
	 * The error code of a precondition refusal.
	 *
	 * @var string
	 */
	public const PRECONDITION_UNMET = 'action-precondition-unmet';

	/**
	 * The refusal of a key no action answers to.
	 *
	 * @var string
	 */
	private const NOT_FOUND = 'No action "%s" runs on this object.';

	/**
	 * The schema's actions, inherited ones included.
	 *
	 * @var ObjectActionDefinitions
	 */
	private readonly ObjectActionDefinitions $definitions;

	/**
	 * The right and the precondition.
	 *
	 * @var ObjectActionGate
	 */
	private readonly ObjectActionGate $gate;

	/**
	 * Defaults, validation and digest.
	 *
	 * @var ObjectActionInputs
	 */
	private readonly ObjectActionInputs $inputs;

	/**
	 * Runs a transition or a self-assign body.
	 *
	 * @var ObjectActionWriters
	 */
	private readonly ObjectActionWriters $writers;

	/**
	 * Writes the `action.run` entry.
	 *
	 * @var ObjectActionRunRecorder
	 */
	private readonly ObjectActionRunRecorder $recorder;

	/**
	 * Runs a flow or steps body.
	 *
	 * @var ObjectActionFlowRun
	 */
	private readonly ObjectActionFlowRun $flowRun;

	/**
	 * Constructor.
	 *
	 * @param ObjectActionRunnerChecks $checks   Definitions, gate and inputs: what is asked before a body runs.
	 * @param ObjectActionWriters      $writers  Runs a transition or a self-assign body.
	 * @param ObjectActionRunRecorder  $recorder Writes the `action.run` entry.
	 * @param ObjectActionFlowRun      $flowRun  Runs a flow or steps body.
	 */
	public function __construct(
		ObjectActionRunnerChecks $checks,
		ObjectActionWriters $writers,
		ObjectActionRunRecorder $recorder,
		ObjectActionFlowRun $flowRun,
	) {
		$this->definitions = $checks->definitions;
		$this->gate        = $checks->gate;
		$this->inputs      = $checks->inputs;
		$this->writers     = $writers;
		$this->recorder    = $recorder;
		$this->flowRun     = $flowRun;
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
		$inputs     = $checked['inputs'];
		$execute    = fn (): array => $this->execute(object: $object, schema: $schema, definition: $definition, inputs: $inputs, subject: $subject);
		if ($definition->elevate === []) {
			return $execute();
		}

		// Opened only now: the person's right on the subject and `when` have
		// passed, so the elevation covers the body and nothing before it.
		return $this->gate->elevated(definition: $definition, operation: $execute);
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
		$definition = $this->resolve(schema: $schema, object: $object, key: $key);
		if ($definition === null) {
			return $this->refused(answer: $this->answer(status: Http::STATUS_NOT_FOUND, body: ['error' => sprintf(self::NOT_FOUND, $key)]));
		}

		$refusal = $this->rightRefusal(schema: $schema, object: $object, key: $key, definition: $definition);
		if ($refusal === null) {
			$inputs  = $this->inputs->withDefaults(schema: $definition->inputs, given: $given, object: $object, userId: $this->gate->userId());
			$refusal = $this->inputRefusal(object: $object, key: $key, definition: $definition, inputs: $inputs);
			if ($refusal === null) {
				return ['refusal' => null, 'definition' => $definition, 'inputs' => $inputs];
			}
		}

		return $this->refused(answer: $refusal);
	}//end check()

	/**
	 * Step 2: the refusal when the caller lacks the right, or a self-assign is already taken.
	 *
	 * The right is the action key; a lifecycle transition the schema does not
	 * declare as an action runs under `update`.
	 *
	 * @param Schema                 $schema     The schema.
	 * @param ObjectEntity           $object     The subject.
	 * @param string                 $key        The action key.
	 * @param ObjectActionDefinition $definition The resolved action.
	 *
	 * @return array{status: int, body: array<string, mixed>}|null The refusal, or null when the right holds.
	 */
	private function rightRefusal(Schema $schema, ObjectEntity $object, string $key, ObjectActionDefinition $definition): ?array {
		$right = $key;
		if ($this->definitions->find(schema: $schema, key: $key, register: $object->getRegister()) === null) {
			$right = 'update';
		}

		if ($this->gate->mayRun(schema: $schema, right: $right, definition: $definition, object: $object) === false) {
			return $this->answer(status: Http::STATUS_FORBIDDEN, body: ['error' => sprintf('You may not perform "%s" on this object.', $key)]);
		}

		if ($this->gate->selfAssignOpen(definition: $definition, object: $object) === false) {
			return $this->unmet(message: 'This object is already assigned.', key: $key);
		}

		return null;
	}//end rightRefusal()

	/**
	 * Steps 3 and 4: the refusal when `when` does not hold, or the inputs do not fit.
	 *
	 * @param ObjectEntity           $object     The subject.
	 * @param string                 $key        The action key.
	 * @param ObjectActionDefinition $definition The resolved action.
	 * @param array<string, mixed>   $inputs     The inputs, defaults resolved.
	 *
	 * @return array{status: int, body: array<string, mixed>}|null The refusal, or null when both pass.
	 */
	private function inputRefusal(ObjectEntity $object, string $key, ObjectActionDefinition $definition, array $inputs): ?array {
		if ($this->gate->whenHolds(definition: $definition, object: $object, inputs: $inputs) === false) {
			return $this->unmet(message: $this->gate->reason(definition: $definition), key: $key);
		}

		$errors = $this->inputErrors(definition: $definition, inputs: $inputs);
		if ($errors !== []) {
			return $this->answer(
				status: Http::STATUS_UNPROCESSABLE_ENTITY,
				body: ['error' => 'invalid-inputs', 'message' => 'The inputs do not fit this action.', 'errors' => $errors, 'action' => $key]
			);
		}

		return null;
	}//end inputRefusal()

	/**
	 * A check that refused.
	 *
	 * @param array{status: int, body: array<string, mixed>} $answer The refusal.
	 *
	 * @return array{refusal: array{status: int, body: array<string, mixed>}, definition: null, inputs: array<string, mixed>} The check.
	 */
	private function refused(array $answer): array {
		return ['refusal' => $answer, 'definition' => null, 'inputs' => []];
	}//end refused()

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
	 * Step 5: execute the body its kind names.
	 *
	 * @param ObjectEntity           $object     The subject.
	 * @param Schema                 $schema     Its schema.
	 * @param ObjectActionDefinition $definition The checked action.
	 * @param array<string, mixed>   $inputs     The inputs, defaults resolved.
	 * @param array<string, mixed>   $subject    `{uuid, register, schema}`.
	 *
	 * @return array{status: int, body: array<string, mixed>} The answer.
	 */
	private function execute(ObjectEntity $object, Schema $schema, ObjectActionDefinition $definition, array $inputs, array $subject): array {
		if ($definition->kind === ObjectActionDefinition::KIND_TRANSITION) {
			return $this->runTransition(object: $object, definition: $definition, inputs: $inputs);
		}

		if ($definition->kind === ObjectActionDefinition::KIND_SELF_ASSIGN) {
			return $this->runSelfAssign(object: $object, definition: $definition);
		}

		return $this->flowRun->run(object: $object, schema: $schema, definition: $definition, inputs: $inputs, subject: $subject);
	}//end execute()

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
			$saved = $this->writers->transitions->transition(objectId: (string)$object->getUuid(), action: (string)$definition->body, data: $inputs);
		} catch (NotAuthorizedException $e) {
			return $this->answer(status: Http::STATUS_FORBIDDEN, body: ['error' => $e->getMessage(), 'action' => $definition->key]);
		} catch (Throwable $e) {
			return $this->answer(status: Http::STATUS_UNPROCESSABLE_ENTITY, body: ['error' => $e->getMessage(), 'action' => $definition->key]);
		}

		$completed = new ObjectActionRunOutcome(run: null, changes: [], outcome: FlowRun::STATUS_COMPLETED);
		$this->recorder->record(object: $saved, definition: $definition, inputs: $inputs, outcome: $completed);

		return $this->savedAnswer(definition: $definition, saved: $saved);
	}//end runTransition()

	/**
	 * Run a `selfAssign` body: the caller's uid into the one declared property.
	 *
	 * @param ObjectEntity           $object     The object, its property checked empty.
	 * @param ObjectActionDefinition $definition The action.
	 *
	 * @return array{status: int, body: array<string, mixed>} The answer.
	 *
	 * @spec openspec/changes/declared-self-assign-action/specs/declared-actions/spec.md
	 */
	private function runSelfAssign(ObjectEntity $object, ObjectActionDefinition $definition): array {
		try {
			$saved = $this->writers->selfAssign->assign(object: $object, definition: $definition);
		} catch (PropertyAlreadySetException $e) {
			return $this->unmet(message: $e->getMessage(), key: $definition->key);
		} catch (Throwable $e) {
			return $this->answer(status: Http::STATUS_UNPROCESSABLE_ENTITY, body: ['error' => $e->getMessage(), 'action' => $definition->key]);
		}

		$changes = [['step' => (string)$definition->selfAssignField(), 'kind' => ObjectActionDefinition::KIND_SELF_ASSIGN]];
		$completed = new ObjectActionRunOutcome(run: null, changes: $changes, outcome: FlowRun::STATUS_COMPLETED);
		$this->recorder->record(object: $saved, definition: $definition, inputs: [], outcome: $completed);

		return $this->savedAnswer(definition: $definition, saved: $saved);
	}//end runSelfAssign()

	/**
	 * The answer to a run that wrote the object itself, a transition or a self-assign.
	 *
	 * @param ObjectActionDefinition $definition The action.
	 * @param ObjectEntity           $saved      The saved object.
	 *
	 * @return array{status: int, body: array<string, mixed>} The answer.
	 */
	private function savedAnswer(ObjectActionDefinition $definition, ObjectEntity $saved): array {
		return $this->answer(
			status: Http::STATUS_OK,
			body: [
				'run' => null,
				'outcome' => FlowRun::STATUS_COMPLETED,
				'action' => $definition->key,
				'kind' => (string)$definition->kind,
				'next' => ($definition->next ?? FlowNextHint::STAY),
				'object' => $saved->jsonSerialize(),
			]
		);
	}//end savedAnswer()

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
		if ($this->writers->transitions->declaredInputs(schema: $schema, action: $key) !== null) {
			return true;
		}

		try {
			foreach ($this->writers->transitions->availableActions((string)$object->getUuid()) as $move) {
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
	 * The 409 of a precondition that does not hold.
	 *
	 * @param string $message Why it does not hold.
	 * @param string $key     The action key.
	 *
	 * @return array{status: int, body: array<string, mixed>} The answer.
	 */
	private function unmet(string $message, string $key): array {
		return $this->answer(status: Http::STATUS_CONFLICT, body: ['error' => self::PRECONDITION_UNMET, 'message' => $message, 'action' => $key]);
	}//end unmet()

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
