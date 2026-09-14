<?php

/**
 * OpenRegister RuleTrialService
 *
 * Evaluates a rule against one object or one sample payload without
 * committing, and returns the verdict with the writes it would have made.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rules
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

namespace OCA\OpenRegister\Service\Rules;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Calculation\CalculationEvaluator;
use OCA\OpenRegister\Service\Calculation\EvaluationException;
use OCA\OpenRegister\Service\Flow\FlowExpression;
use Throwable;

/**
 * Try a rule before you trust it.
 *
 * D-5. The dry run and the replay are the same evaluation with one flag, so
 * this is written as "evaluate, never commit" rather than as a second engine
 * that resembles the first. What it returns is exactly what the save path would
 * have done: the verdict, the operand that decided it, and the writes.
 *
 * NOTHING IS WRITTEN ON ANY PATH HERE. No object is saved, no schema is saved,
 * and a `sequence` node yields null rather than reserving a number, because the
 * evaluator is called with no sequence context. A trial that burned a running
 * number would make "try it and see" cost an identifier every time.
 *
 * A rule may also be supplied unsaved: an author experimenting with a condition
 * hands the condition in and never touches the schema, which is what makes
 * "saving a rule is not a condition of trying it" true rather than aspirational.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The trial resolves a named object
 *   through the three mappers and evaluates through both condition dialects; each is one
 *   collaborator on the one dry-run path.
 */
final class RuleTrialService {

	/**
	 * The refusal when the schema declares no such rule and none was supplied.
	 *
	 * @var string
	 */
	public const CODE_UNKNOWN_RULE = 'rule-unknown';

	/**
	 * The refusal when the named object cannot be read.
	 *
	 * @var string
	 */
	public const CODE_OBJECT_NOT_FOUND = 'rule-trial-object-not-found';

	/**
	 * The refusal when the rule's kind cannot be tried against one object.
	 *
	 * @var string
	 */
	public const CODE_NOT_EVALUABLE = 'rule-trial-not-evaluable';

	/**
	 * Constructor.
	 *
	 * @param RuleInventoryService $inventory Finds the rule as declared.
	 * @param CalculationEvaluator $evaluator The JSON-AST evaluator.
	 * @param ConditionTracer $tracer Names the operand that decided.
	 * @param RegisterMapper $registerMapper Register lookup for a named object.
	 * @param MagicMapper $objectMapper Object lookup in the register and schema table.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly RuleInventoryService $inventory,
		private readonly CalculationEvaluator $evaluator,
		private readonly ConditionTracer $tracer,
		private readonly RegisterMapper $registerMapper,
		private readonly MagicMapper $objectMapper,
	) {
	}//end __construct()

	/**
	 * Evaluate a rule against a sample payload, committing nothing.
	 *
	 * @param Schema $schema The schema the rule belongs to.
	 * @param string $ruleId The derived rule id.
	 * @param array<string, mixed> $sample The object payload to evaluate against.
	 * @param array<string, mixed>|null $override An unsaved declaration to try in the rule's place.
	 *
	 * @return array<string, mixed> The verdict, the trace and the writes it would make.
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	public function trySample(Schema $schema, string $ruleId, array $sample, ?array $override = null): array {
		$rule = $this->resolve(schema: $schema, ruleId: $ruleId, override: $override);
		if ($rule instanceof RuleDescriptor === false) {
			return $rule;
		}

		return $this->run(rule: $rule, payload: $sample);
	}//end trySample()

	/**
	 * Evaluate a rule against an object that already exists, committing nothing.
	 *
	 * The object is read through the RBAC- and tenancy-scoped mapper, so a
	 * caller who may not read it gets a not-found refusal rather than its
	 * values. That lookup is this path's per-object authorisation guard.
	 *
	 * @param Schema $schema The schema the rule belongs to.
	 * @param string $ruleId The derived rule id.
	 * @param string $register Register id, uuid or slug.
	 * @param string $objectId Object id, uuid, slug or uri.
	 * @param array<string, mixed>|null $override An unsaved declaration to try in the rule's place.
	 *
	 * @return array<string, mixed> The verdict, the trace and the writes it would make.
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	public function tryObject(
		Schema $schema,
		string $ruleId,
		string $register,
		string $objectId,
		?array $override = null,
	): array {
		$rule = $this->resolve(schema: $schema, ruleId: $ruleId, override: $override);
		if ($rule instanceof RuleDescriptor === false) {
			return $rule;
		}

		try {
			$registerEntity = $this->registerMapper->find($register);
			$object = $this->objectMapper->findInRegisterSchemaTable(
				identifier: $objectId,
				register: $registerEntity,
				schema: $schema
			);
		} catch (Throwable $e) {
			return $this->refusal(
				code: self::CODE_OBJECT_NOT_FOUND,
				message: sprintf('Object "%s" could not be read in register "%s".', $objectId, $register)
			);
		}

		return $this->run(rule: $rule, payload: ($object->getObject() ?? []), objectUuid: ($object->getUuid() ?? null));
	}//end tryObject()

	/**
	 * The rule to try: the declared one, or the unsaved one the caller supplied.
	 *
	 * @param Schema $schema The schema the rule belongs to.
	 * @param string $ruleId The derived rule id.
	 * @param array<string, mixed>|null $override An unsaved declaration.
	 *
	 * @return RuleDescriptor|array<string, mixed> The rule, or the refusal to return.
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	private function resolve(Schema $schema, string $ruleId, ?array $override): RuleDescriptor|array {
		$declared = $this->inventory->find(schema: $schema, ruleId: $ruleId);

		if ($override !== null && isset($override['expression']) === true) {
			// The caller is trying something that is not saved. It borrows the
			// declared rule's identity so the response is about the rule the
			// author is editing, and nothing at all is written.
			return new RuleDescriptor(
				kind: ($declared?->getKind() ?? RuleVocabulary::KIND_CALCULATION),
				schemaSlug: (string)($schema->getSlug() ?? ''),
				key: ($declared?->getKey() ?? 'draft'),
				label: ($declared?->getKey() ?? 'draft'),
				source: 'draft',
				actions: [RuleVocabulary::ACTION_SET_VALUE],
				condition: $override['expression']
			);
		}

		if ($declared === null) {
			return $this->refusal(
				code: self::CODE_UNKNOWN_RULE,
				message: sprintf('Schema "%s" declares no rule "%s".', (string)($schema->getSlug() ?? ''), $ruleId)
			);
		}

		return $declared;
	}//end resolve()

	/**
	 * Evaluate one rule against one payload.
	 *
	 * @param RuleDescriptor $rule The rule to evaluate.
	 * @param array<string, mixed> $payload The object payload.
	 * @param string|null $objectUuid The object evaluated, when it was a stored one.
	 *
	 * @return array<string, mixed> The trial result.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) FlowExpression is the engine's stateless
	 *   JSONLogic facade; calling it statically IS the reuse.
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	private function run(RuleDescriptor $rule, array $payload, ?string $objectUuid = null): array {
		if ($rule->getKind() === RuleVocabulary::KIND_CALCULATION) {
			return $this->runCalculation(rule: $rule, payload: $payload, objectUuid: $objectUuid);
		}

		if ($rule->getKind() === RuleVocabulary::KIND_LIFECYCLE_CONDITION) {
			return $this->runCondition(rule: $rule, payload: $payload, objectUuid: $objectUuid);
		}

		// A state field block decides what a RENDER shows and a flow is a run of
		// its own with its own log; neither is a verdict about one payload, and
		// answering one anyway would be a number that looks like an answer.
		return $this->refusal(
			code: self::CODE_NOT_EVALUABLE,
			message: sprintf('A rule of kind "%s" cannot be tried against a single payload.', $rule->getKind())
		);
	}//end run()

	/**
	 * Evaluate a calculation and report the value it would have written.
	 *
	 * @param RuleDescriptor $rule The calculation.
	 * @param array<string, mixed> $payload The object payload.
	 * @param string|null $objectUuid The object evaluated, when it was a stored one.
	 *
	 * @return array<string, mixed> The trial result.
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	private function runCalculation(RuleDescriptor $rule, array $payload, ?string $objectUuid): array {
		try {
			// No SequenceContext: a trial must never reserve a running number.
			$value = $this->evaluator->evaluate($payload, $rule->getCondition());
		} catch (EvaluationException $e) {
			return $this->result(
				rule: $rule,
				trace: RuleTrace::errored(message: $e->getMessage()),
				writes: [],
				objectUuid: $objectUuid
			);
		}

		return $this->result(
			rule: $rule,
			trace: RuleTrace::fired(),
			writes: [$rule->getKey() => $value],
			objectUuid: $objectUuid
		);
	}//end runCalculation()

	/**
	 * Evaluate a transition condition and report whether it would refuse.
	 *
	 * @param RuleDescriptor $rule The condition.
	 * @param array<string, mixed> $payload The object payload.
	 * @param string|null $objectUuid The object evaluated, when it was a stored one.
	 *
	 * @return array<string, mixed> The trial result.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) FlowExpression is the engine's stateless
	 *   JSONLogic facade; calling it statically IS the reuse.
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	private function runCondition(RuleDescriptor $rule, array $payload, ?string $objectUuid): array {
		// The same four-key document the save path builds, with the transition
		// half left empty: a trial is asking about the object, and inventing a
		// transition it is not making would answer a question nobody asked.
		$document = [
			'object' => $payload,
			'previous' => $payload,
			'user' => ['uid' => '', 'groups' => []],
			'transition' => ['action' => $rule->getKey(), 'from' => '', 'to' => ''],
		];

		$holds = FlowExpression::isTrue(logic: $rule->getCondition(), data: $document);
		$verdict = ($holds === true ? RuleVocabulary::VERDICT_FIRED : RuleVocabulary::VERDICT_NO_MATCH);

		return $this->result(
			rule: $rule,
			trace: $this->tracer->trace(condition: $rule->getCondition(), document: $document, verdict: $verdict),
			writes: [],
			objectUuid: $objectUuid
		);
	}//end runCondition()

	/**
	 * Shape one successful trial.
	 *
	 * @param RuleDescriptor $rule The rule that was tried.
	 * @param RuleTrace $trace The verdict and its deciding operand.
	 * @param array<string, mixed> $writes The writes the rule would have made.
	 * @param string|null $objectUuid The object evaluated, when it was a stored one.
	 *
	 * @return array<string, mixed> The trial result.
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	private function result(RuleDescriptor $rule, RuleTrace $trace, array $writes, ?string $objectUuid): array {
		return [
			'ok' => ($trace->getVerdict() !== RuleVocabulary::VERDICT_ERROR),
			'committed' => false,
			'ruleId' => $rule->getId(),
			'kind' => $rule->getKind(),
			'objectUuid' => $objectUuid,
			'trace' => $trace->jsonSerialize(),
			'writes' => $writes,
		];
	}//end result()

	/**
	 * Shape one refusal.
	 *
	 * @param string $code The refusal's code.
	 * @param string $message The sentence naming what was refused.
	 *
	 * @return array<string, mixed> The refusal.
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	private function refusal(string $code, string $message): array {
		return ['ok' => false, 'committed' => false, 'error' => ['code' => $code, 'message' => $message]];
	}//end refusal()
}//end class
