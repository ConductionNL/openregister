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
use OCA\OpenRegister\Service\Flow\MacroActionResolver;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionRunner;
use OCA\OpenRegister\Service\ObjectService;
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
class RunActionAction implements BulkActionInterface {

	/**
	 * The bulk action id.
	 *
	 * @var string
	 */
	public const ID = 'openregister:run-action';

	/**
	 * Constructor.
	 *
	 * @param ObjectActionRunner  $runner  The single-object run path.
	 * @param MacroActionResolver $schemas Loads a member's schema.
	 * @param ObjectService       $objects Runs the commit as the job's actor.
	 */
	public function __construct(
		private readonly ObjectActionRunner $runner,
		private readonly MacroActionResolver $schemas,
		private readonly ObjectService $objects,
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
		$schema = $this->schemas->loadSchema(schema: (string)$object->getSchema());
		if ($schema === null) {
			return BulkActionResult::refused(rule: 'no-schema');
		}

		$key = (string)($parameters['action'] ?? '');
		$inputs = (array)($parameters['inputs'] ?? []);

		if ($commit === false) {
			$checked = $this->runner->check(object: $object, schema: $schema, key: $key, given: $inputs);

			return $this->resultOf(answer: ($checked['refusal'] ?? ['status' => Http::STATUS_OK, 'body' => []]));
		}

		$run = fn (): array => $this->runner->run(
			object: $object,
			schema: $schema,
			key: $key,
			given: $inputs,
			subject: [
				'uuid' => (string)$object->getUuid(),
				'register' => (string)($parameters['register'] ?? $object->getRegister()),
				'schema' => (string)($parameters['schema'] ?? $object->getSchema()),
			]
		);

		if ($actor === null) {
			return $this->resultOf(answer: $run());
		}

		return $this->resultOf(answer: $this->objects->runAs($actor, $run));
	}//end apply()

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
