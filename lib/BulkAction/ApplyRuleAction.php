<?php

/**
 * OpenRegister ApplyRuleAction
 *
 * The bulk action a rule replay runs: evaluate one declared rule against one
 * existing object, and write what it derives.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category BulkAction
 * @package  OCA\OpenRegister\BulkAction
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BulkAction;

use InvalidArgumentException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Calculation\CalculationEvaluator;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Rules\RuleDescriptor;
use OCA\OpenRegister\Service\Rules\RuleInventoryService;
use OCA\OpenRegister\Service\Rules\RuleVocabulary;
use OCP\IUser;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Applying a rule to objects that already exist.
 *
 * D-5: the dry run and the replay are the same evaluation with one flag. This
 * action is the replay's per-object half, and `$commit` is that flag: the
 * preview and the commit call the same method, so a rehearsal cannot report
 * one thing and the run do another.
 *
 * ONLY A CALCULATION IS REPLAYABLE. A state field block decides what a render
 * shows, a lifecycle condition guards a move nobody is making, and a flow is a
 * run with a log of its own. Applying any of the three to a stored object
 * would be inventing an event. They are skipped with the reason said out loud
 * rather than reported as applied, which is the same line
 * {@see \OCA\OpenRegister\Service\Rules\RuleTrialService} draws for the dry run.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The action composes the inventory,
 *   the evaluator and the write path, which are the three halves of applying a rule.
 *
 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
 */
class ApplyRuleAction implements BulkActionInterface {

	/**
	 * The action id.
	 *
	 * @var string
	 */
	public const ID = 'openregister:apply-rule';

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService The object write path.
	 * @param SchemaMapper $schemaMapper Resolves the schema the rule is declared on.
	 * @param RuleInventoryService $inventory Finds the rule as declared.
	 * @param CalculationEvaluator $evaluator The JSON-AST evaluator.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly SchemaMapper $schemaMapper,
		private readonly RuleInventoryService $inventory,
		private readonly CalculationEvaluator $evaluator,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The action's stable id.
	 *
	 * @return string The action id.
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	public function getId(): string {
		return self::ID;
	}//end getId()

	/**
	 * The label an operator reads.
	 *
	 * @return string The label.
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	public function getLabel(): string {
		return 'Apply a rule';
	}//end getLabel()

	/**
	 * One sentence saying what the action does.
	 *
	 * @return string The description.
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	public function getDescription(): string {
		return 'Runs one declared rule over objects that already exist and writes what it derives. '
			. 'Objects the rule already agrees with are skipped.';
	}//end getDescription()

	/**
	 * A replay is a deliberate act, and it says why it was made.
	 *
	 * The replay writes a derived value over data a caseworker may have typed.
	 * That is the class of change D-7 of `bulk-action-jobs` requires a written
	 * reason for.
	 *
	 * @return bool True.
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	public function requiresJustification(): bool {
		return true;
	}//end requiresJustification()

	/**
	 * The guards the engine enforces for this action.
	 *
	 * A rule is declared on one schema, so a selection spanning more than one
	 * is a selection the rule cannot mean.
	 *
	 * @return array<int, string> The homogeneity guard.
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	public function getGuards(): array {
		return [self::GUARD_HOMOGENEITY];
	}//end getGuards()

	/**
	 * Check the parameters before a job is created.
	 *
	 * @param array<string, mixed> $parameters The parameters the caller sent.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the rule is not named.
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	public function validateParameters(array $parameters): void {
		$schema = ($parameters['schema'] ?? null);
		$ruleId = ($parameters['ruleId'] ?? null);

		if (is_string($schema) === false || $schema === '' || is_string($ruleId) === false || $ruleId === '') {
			throw new InvalidArgumentException(
				'The action ' . self::ID . ' needs a "schema" and the "ruleId" of a rule that schema declares.'
			);
		}

		if ($this->ruleFor(parameters: $parameters) === null) {
			throw new InvalidArgumentException(
				sprintf('Schema "%s" declares no rule "%s".', $schema, $ruleId)
			);
		}
	}//end validateParameters()

	/**
	 * Rehearse or make the write for one object.
	 *
	 * @param ObjectEntity $object The object to act on.
	 * @param array<string, mixed> $parameters The job's parameters.
	 * @param bool $commit False to rehearse, true to write.
	 * @param IUser|null $actor The user the job runs as.
	 *
	 * @return BulkActionResult What happened, or what would happen.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) One executor for the rehearsal and
	 *   the commit is the design property of D-5.
	 * @SuppressWarnings(PHPMD.StaticAccess) BulkActionResult's named constructors are
	 *   its only constructor: the class is immutable and its private __construct exists
	 *   so an outcome cannot be built without saying which of the four it is.
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	public function apply(ObjectEntity $object, array $parameters, bool $commit, ?IUser $actor = null): BulkActionResult {
		$rule = $this->ruleFor(parameters: $parameters);
		if ($rule === null) {
			return BulkActionResult::failed(
				message: sprintf('The rule "%s" is no longer declared.', (string)($parameters['ruleId'] ?? ''))
			);
		}

		if ($rule->getKind() !== RuleVocabulary::KIND_CALCULATION) {
			return BulkActionResult::skipped(
				reason: sprintf(
					'A rule of kind "%s" derives no value for a stored object, so there is nothing to replay.',
					$rule->getKind()
				)
			);
		}

		if ($rule->isEnabled() === false) {
			return BulkActionResult::skipped(reason: 'The rule is switched off.');
		}

		$current = $object->getObject();
		if (is_array($current) === false) {
			$current = [];
		}

		try {
			// No SequenceContext: a replay must never reserve a running number.
			$value = $this->evaluator->evaluate($current, $rule->getCondition());
		} catch (Throwable $failure) {
			return BulkActionResult::failed(message: $failure->getMessage());
		}

		$key = $rule->getKey();
		if (array_key_exists($key, $current) === true && $current[$key] === $value) {
			return BulkActionResult::skipped(reason: 'The object already carries the value this rule derives.');
		}

		if ($commit === false) {
			return BulkActionResult::applied();
		}

		return $this->write(object: $object, patch: [$key => $value], actor: $actor);
	}//end apply()

	/**
	 * Write the derived value onto one object.
	 *
	 * @param ObjectEntity $object The object.
	 * @param array<string, mixed> $patch The merge patch.
	 * @param IUser|null $actor The user the job runs as.
	 *
	 * @return BulkActionResult The outcome.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) See apply().
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	private function write(ObjectEntity $object, array $patch, ?IUser $actor): BulkActionResult {
		try {
			$this->objectService->patchObject(
				objectId: (string)$object->getUuid(),
				data: $patch,
				register: $object->getRegister(),
				schema: $object->getSchema(),
				currentUser: $actor
			);
		} catch (Throwable $failure) {
			$this->logger->warning(
				message: '[ApplyRuleAction] Write failed for object',
				context: [
					'action' => self::ID,
					'object' => $object->getUuid(),
					'error' => $failure->getMessage(),
				]
			);

			return BulkActionResult::failed(message: $failure->getMessage());
		}

		return BulkActionResult::applied();
	}//end write()

	/**
	 * The rule the job's parameters name, or null when they name none.
	 *
	 * @param array<string, mixed> $parameters The job's parameters.
	 *
	 * @return RuleDescriptor|null The rule, or null.
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	private function ruleFor(array $parameters): ?RuleDescriptor {
		try {
			$schema = $this->schemaMapper->find((string)($parameters['schema'] ?? ''));

			return $this->inventory->find(schema: $schema, ruleId: (string)($parameters['ruleId'] ?? ''));
		} catch (Throwable $failure) {
			return null;
		}
	}//end ruleFor()
}//end class
