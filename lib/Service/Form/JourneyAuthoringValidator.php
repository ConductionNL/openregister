<?php

/**
 * JourneyAuthoringValidator: judge a journey body when its author saves it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Form
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Form;

use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCP\IL10N;
use Throwable;

/**
 * Every problem with a journey: access, steps, next rules and each step's writes.
 *
 * Access is required and never inferred. A step is `form`, `review` or
 * `confirmation`, with a unique id. A `next` rule names a declared step and
 * its `when` uses only the operators of `$defs.visibleWhen`. A write names a
 * schema the author can see, and its mapping is judged against that schema by
 * the same FormDestinationValidator a form's mapping is, so a mapped property
 * the schema lacks is refused naming the property and the schema. Writing is
 * a privileged act: the author's own RBAC and tenancy decide which schemas
 * exist.
 *
 * @spec openspec/changes/or-form-and-journey-registry/specs/form-and-journey-registry/spec.md#requirement-a-journey-must-declare-steps-branching-writes-and-access
 */
class JourneyAuthoringValidator {

	/**
	 * Finding code: access absent or not an accepted value.
	 *
	 * @var string
	 */
	public const ACCESS_MISSING = 'access-missing';

	/**
	 * Finding code: no steps.
	 *
	 * @var string
	 */
	public const STEPS_MISSING = 'steps-missing';

	/**
	 * Finding code: a step without an id, or with an id used before.
	 *
	 * @var string
	 */
	public const STEP_ID = 'step-id';

	/**
	 * Finding code: a step type that is not accepted.
	 *
	 * @var string
	 */
	public const STEP_TYPE = 'step-type';

	/**
	 * Finding code: a next rule naming an undeclared step.
	 *
	 * @var string
	 */
	public const NEXT_TARGET = 'next-target';

	/**
	 * Finding code: a next rule using an operator `$defs.visibleWhen` does not accept.
	 *
	 * @var string
	 */
	public const NEXT_OPERATOR = 'next-operator';

	/**
	 * Finding code: a write whose schema the author cannot see.
	 *
	 * @var string
	 */
	public const WRITE_SCHEMA = 'write-schema-unknown';

	/**
	 * Wire the validator.
	 *
	 * @param SchemaMapperInterface             $schemas      Finds a write's schema, under the author's RBAC and tenancy.
	 * @param FormDestinationValidatorInterface $destinations Judges a write's mapping against its schema.
	 * @param LocalConditionEvaluator           $conditions   Names unknown operators.
	 * @param IL10N                             $l10n         Translates findings.
	 */
	public function __construct(
		private readonly SchemaMapperInterface $schemas,
		private readonly FormDestinationValidatorInterface $destinations,
		private readonly LocalConditionEvaluator $conditions,
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * Every problem with a journey body; empty when it may be saved.
	 *
	 * @param array<string, mixed> $body The journey body.
	 *
	 * @return list<array<string, mixed>> The findings, each with `code` and `message`, and `step` where one is concerned.
	 *
	 * @spec openspec/changes/or-form-and-journey-registry/specs/form-and-journey-registry/spec.md#requirement-a-journey-must-declare-steps-branching-writes-and-access
	 */
	public function validate(array $body): array {
		$findings = [];
		$journey  = new JourneyDefinition(id: '', body: $body);
		if ($journey->access() === null) {
			$findings[] = [
				'code' => self::ACCESS_MISSING,
				'message' => $this->l10n->t(
					'A journey declares its access as one of %s; it is never inferred.',
					[implode(', ', JourneyDefinition::ACCESS)]
				),
			];
		}

		$steps = array_values((array)($body['steps'] ?? []));
		if ($steps === []) {
			$findings[] = ['code' => self::STEPS_MISSING, 'message' => $this->l10n->t('A journey declares at least one step.')];
		}

		$seen = [];
		foreach ($steps as $index => $step) {
			$findings = array_merge($findings, $this->stepFindings(step: $step, index: $index, seen: $seen, journey: $journey));
		}

		return $findings;
	}//end validate()

	/**
	 * The findings of one step.
	 *
	 * @param mixed                $step    The step.
	 * @param int                  $index   Its position.
	 * @param array<string, true>  $seen    The ids already declared; this step's id is added.
	 * @param JourneyDefinition    $journey The journey, for the declared step ids.
	 *
	 * @return list<array<string, mixed>> The findings.
	 */
	private function stepFindings(mixed $step, int $index, array &$seen, JourneyDefinition $journey): array {
		$id = '';
		if (is_array($step) === true && is_string($step['id'] ?? null) === true) {
			$id = $step['id'];
		}

		$label = $id;
		if ($id === '') {
			$label = '#' . ($index + 1);
		}

		if ($id === '' || isset($seen[$id]) === true) {
			return [
				[
					'step' => $label,
					'code' => self::STEP_ID,
					'message' => $this->l10n->t('Step %s needs an id no other step uses.', [$label]),
				],
			];
		}

		$seen[$id] = true;
		$findings  = [];
		if (in_array($step['type'] ?? null, JourneyDefinition::STEP_TYPES, true) === false) {
			$findings[] = [
				'step' => $id,
				'code' => self::STEP_TYPE,
				'message' => $this->l10n->t('Step %1$s is of type %2$s.', [$id, implode(', ', JourneyDefinition::STEP_TYPES)]),
			];
		}

		foreach ((array)($step['next'] ?? []) as $rule) {
			$findings = array_merge($findings, $this->ruleFindings(rule: $rule, stepId: $id, journey: $journey));
		}

		foreach ($journey->writesOf(stepId: $id) as $write) {
			$findings = array_merge($findings, $this->writeFindings(write: $write, stepId: $id));
		}

		return $findings;
	}//end stepFindings()

	/**
	 * The findings of one next rule.
	 *
	 * @param mixed             $rule    The rule `{ when?, step }`.
	 * @param string            $stepId  The step declaring it.
	 * @param JourneyDefinition $journey The journey.
	 *
	 * @return list<array<string, mixed>> The findings.
	 */
	private function ruleFindings(mixed $rule, string $stepId, JourneyDefinition $journey): array {
		$findings = [];
		$target   = '';
		if (is_array($rule) === true && is_string($rule['step'] ?? null) === true) {
			$target = $rule['step'];
		}

		if ($journey->hasStep(stepId: $target) === false) {
			$findings[] = [
				'step' => $stepId,
				'code' => self::NEXT_TARGET,
				'message' => $this->l10n->t('A next rule of step %1$s leads to %2$s, which this journey does not declare.', [$stepId, $target]),
			];
		}

		$when = [];
		if (is_array($rule) === true && is_array($rule['when'] ?? null) === true) {
			$when = $rule['when'];
		}

		$unknown = $this->conditions->unknownOperators(condition: $when);
		if ($unknown !== []) {
			$findings[] = [
				'step' => $stepId,
				'code' => self::NEXT_OPERATOR,
				'message' => $this->l10n->t(
					'A next rule of step %1$s uses %2$s; the accepted operators are %3$s.',
					[$stepId, implode(', ', $unknown), implode(', ', LocalConditionEvaluator::OPERATORS)]
				),
			];
		}

		return $findings;
	}//end ruleFindings()

	/**
	 * The findings of one write: its schema must exist for the author, its mapping must fit it.
	 *
	 * @param array<string, mixed> $write  The write `{ register, schema, mapping?, as? }`.
	 * @param string               $stepId The step declaring it.
	 *
	 * @return list<array<string, mixed>> The findings, each carrying the step.
	 */
	private function writeFindings(array $write, string $stepId): array {
		$schemaRef = trim((string)($write['schema'] ?? ''));
		try {
			$schema = $this->schemas->find($schemaRef, _rbac: true, _multitenancy: true);
		} catch (Throwable) {
			return [
				[
					'step' => $stepId,
					'code' => self::WRITE_SCHEMA,
					'message' => $this->l10n->t('Step %1$s writes into schema %2$s, which does not exist.', [$stepId, $schemaRef]),
				],
			];
		}

		$mapping = [];
		if (is_array($write['mapping'] ?? null) === true) {
			$mapping = $write['mapping'];
		}

		$findings = [];
		foreach ($this->destinations->validate(mapping: $mapping, destination: new FormDestination(schema: $schema)) as $finding) {
			$findings[] = array_merge(['step' => $stepId], $finding);
		}

		return $findings;
	}//end writeFindings()
}//end class
