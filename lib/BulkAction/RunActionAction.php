<?php

/**
 * Run a declared object action on every object of a large selection.
 *
 * A selection above {@see \OCA\OpenRegister\Controller\ObjectActionsController::SELECTION_LIMIT}
 * becomes a bulk job of this action, so it gets the preview, progress, retry
 * and audit of `bulk-action-jobs` instead of a request that times out half way.
 * Each member goes through the single-object run path: the preview asks the
 * same four questions the run asks (action, right, `when`, inputs), and the
 * commit runs the action as the person who started the job.
 *
 * Parameters: `{action, inputs, register, schema}`, the last two as the
 * caller named them, which the run records as its subject.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category BulkAction
 * @package  OCA\OpenRegister\BulkAction
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-selections-run-per-object-and-large-selections-become-a-bulk-job
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BulkAction;

use InvalidArgumentException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionCall;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionRequest;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionRunnerInterface;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionTarget;
use OCA\OpenRegister\Db\Schema;
use OCP\AppFramework\Http;
use OCP\IUser;

/**
 * The bulk action that runs a declared object action per member.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) BulkActionResult's named constructors are the outcome
 *   vocabulary every bulk action answers in.
 *
 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-selections-run-per-object-and-large-selections-become-a-bulk-job
 */
class RunActionAction implements RunActionActionInterface {

	/**
	 * Constructor.
	 *
	 * @param ObjectActionRunnerInterface $runner The single-object run path.
	 * @param BulkObjectAccess            $access Loads a member's schema and runs the commit as the job's actor.
	 */
	public function __construct(
		private readonly ObjectActionRunnerInterface $runner,
		private readonly BulkObjectAccess $access,
	) {
	}//end __construct()

	/**
	 * The bulk action id.
	 *
	 * @return string The id.
	 *
	 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-selections-run-per-object-and-large-selections-become-a-bulk-job
	 */
	public function getId(): string {
		return self::ID;
	}//end getId()

	/**
	 * The label.
	 *
	 * @return string The label.
	 *
	 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-selections-run-per-object-and-large-selections-become-a-bulk-job
	 */
	public function getLabel(): string {
		return 'Run an action';
	}//end getLabel()

	/**
	 * The description.
	 *
	 * @return string The description.
	 *
	 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-selections-run-per-object-and-large-selections-become-a-bulk-job
	 */
	public function getDescription(): string {
		return 'Runs one declared action on every selected object, each as if it was pressed on that object.';
	}//end getDescription()

	/**
	 * No reason is asked: each run is audited as the action it is.
	 *
	 * @return bool False.
	 *
	 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-selections-run-per-object-and-large-selections-become-a-bulk-job
	 */
	public function requiresJustification(): bool {
		return false;
	}//end requiresJustification()

	/**
	 * No selection-wide guards; each member is checked on its own.
	 *
	 * @return array<int, string> The guards.
	 *
	 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-selections-run-per-object-and-large-selections-become-a-bulk-job
	 */
	public function getGuards(): array {
		return [];
	}//end getGuards()

	/**
	 * Refuse parameters that name no action or carry inputs that are not an object.
	 *
	 * @param array<string, mixed> $parameters The parameters.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When they are unusable.
	 *
	 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-selections-run-per-object-and-large-selections-become-a-bulk-job
	 */
	public function validateParameters(array $parameters): void {
		if (is_string($parameters['action'] ?? null) === false || trim((string)$parameters['action']) === '') {
			throw new InvalidArgumentException('The action '.self::ID.' needs the declared action to run in "action".');
		}

		if (array_key_exists('inputs', $parameters) === true && is_array($parameters['inputs']) === false) {
			throw new InvalidArgumentException('The action '.self::ID.' needs "inputs" as an object.');
		}
	}//end validateParameters()

	/**
	 * Rehearse or run the action on one member.
	 *
	 * @param ObjectEntity         $object     The member.
	 * @param array<string, mixed> $parameters `{action, inputs, register, schema}`.
	 * @param bool                 $commit     False to rehearse, true to run.
	 * @param IUser|null           $actor      The person who started the job.
	 *
	 * @return BulkActionResult The outcome.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The interface fixes the signature.
	 *
	 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-selections-run-per-object-and-large-selections-become-a-bulk-job
	 */
	public function apply(ObjectEntity $object, array $parameters, bool $commit, ?IUser $actor = null): BulkActionResult {
		return $this->applyStep(step: new BulkStep(object: $object, parameters: $parameters, commit: $commit, actor: $actor));
	}//end apply()

	/**
	 * Preview or run the declared action on the step's object.
	 *
	 * @param BulkStep $step The member, the action and its inputs, preview or commit, and the actor.
	 *
	 * @return BulkActionResult The member's outcome.
	 */
	private function applyStep(BulkStep $step): BulkActionResult {
		$schema = $this->schemaOf(object: $step->object);
		if ($schema === null) {
			return BulkActionResult::refused(rule: 'no-schema');
		}

		if ($step->commit === false) {
			return $this->preview(step: $step, schema: $schema);
		}

		return $this->run(step: $step, schema: $schema);
	}//end applyStep()

	/**
	 * Ask the run's checks (action, right, `when`, inputs) and run nothing.
	 *
	 * @param BulkStep $step   The member, the action and its inputs.
	 * @param Schema   $schema The member's schema.
	 *
	 * @return BulkActionResult Applied, or the refusal the run would give.
	 */
	private function preview(BulkStep $step, Schema $schema): BulkActionResult {
		$checked = $this->runner->rehearse(
			call: new ObjectActionCall(object: $step->object, schema: $schema, key: (string)($step->parameters['action'] ?? '')),
			given: (array)($step->parameters['inputs'] ?? [])
		);

		return $this->resultOf(answer: ($checked['refusal'] ?? ['status' => Http::STATUS_OK, 'body' => []]));
	}//end preview()

	/**
	 * Run the action on the member, as the person who started the job when there is one.
	 *
	 * @param BulkStep $step   The member, the action and its inputs, and the actor.
	 * @param Schema   $schema The member's schema.
	 *
	 * @return BulkActionResult The member's outcome.
	 */
	private function run(BulkStep $step, Schema $schema): BulkActionResult {
		$parameters = $step->parameters;
		$target = new ObjectActionTarget(
			object: $step->object,
			schema: $schema,
			register: (string)($parameters['register'] ?? $step->object->getRegister()),
			schemaRef: (string)($parameters['schema'] ?? $step->object->getSchema())
		);
		$request = new ObjectActionRequest(key: (string)($parameters['action'] ?? ''), given: (array)($parameters['inputs'] ?? []));
		$run     = fn (): array => $this->runner->run(target: $target, request: $request);
		if ($step->actor === null) {
			return $this->resultOf(answer: $run());
		}

		return $this->resultOf(answer: $this->access->objects->runAs($step->actor, $run));
	}//end run()

	/**
	 * The member's schema, read the way the single-object run reads it.
	 *
	 * Read without RBAC or multitenancy: the declared action's own checks
	 * decide who may run it. A schema that cannot be read is null.
	 *
	 * @param ObjectEntity $object The member.
	 *
	 * @return Schema|null The schema, or null.
	 */
	private function schemaOf(ObjectEntity $object): ?Schema {
		try {
			return $this->access->schemas->find((string)$object->getSchema(), _multitenancy: false, _rbac: false);
		} catch (\Throwable) {
			return null;
		}
	}//end schemaOf()

	/**
	 * The bulk outcome of a run's answer.
	 *
	 * @param array{status: int, body: array<string, mixed>} $answer The answer.
	 *
	 * @return BulkActionResult The outcome.
	 */
	private function resultOf(array $answer): BulkActionResult {
		$status = $answer['status'];
		$reason = (string)($answer['body']['message'] ?? ($answer['body']['error'] ?? ''));

		if ($status === Http::STATUS_OK || $status === Http::STATUS_ACCEPTED) {
			return BulkActionResult::applied();
		}

		if ($status === Http::STATUS_CONFLICT) {
			return BulkActionResult::skipped(reason: $reason);
		}

		if ($status === Http::STATUS_FORBIDDEN || $status === Http::STATUS_NOT_FOUND) {
			return BulkActionResult::refused(rule: $reason);
		}

		return BulkActionResult::failed(message: $reason);
	}//end resultOf()
}//end class
