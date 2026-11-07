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
 * @spec openspec/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rules;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Calculation\EvaluationException;
use OCA\OpenRegister\Service\Rules\Support\RuleTrialEngine;
use OCA\OpenRegister\Service\Rules\Support\RuleTrialLookups;
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
 *
 * @spec openspec/specs/flow-engine/spec.md
 */
final class RuleTrialService implements RuleTrialServiceInterface {

	/**
	 * The refusal when the schema declares no such rule and none was supplied.
	 *
	 * @var string
	 */
	public const CODE_UNKNOWN_RULE = 'rule-unknown';

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
	 * @param RuleTrialEngine $engine The evaluator, the tracer and the dialect a rule is tried through.
	 * @param RuleTrialLookups $lookups The register and object lookups for a named object.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly RuleInventoryService $inventory,
		private readonly RuleTrialEngine $engine,
		private readonly RuleTrialLookups $lookups,
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
	 * @spec openspec/specs/flow-engine/spec.md
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
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	public function tryObject(Schema $schema, string $ruleId, string $register, string $objectId,
		?array $override = null): array {
		$rule = $this->resolve(schema: $schema, ruleId: $ruleId, override: $override);
		if ($rule instanceof RuleDescriptor === false) {
			return $rule;
		}

		$object = $this->storedObject(schema: $schema, register: $register, objectId: $objectId);
		if ($object === null) {
			return $this->refusal(
				code: self::CODE_OBJECT_NOT_FOUND,
				message: sprintf('Object "%s" could not be read in register "%s".', $objectId, $register)
			);
		}

		return $this->run(rule: $rule, payload: ($object->getObject() ?? []), objectUuid: ($object->getUuid() ?? null));
	}//end tryObject()

	/**
	 * The stored object to try a rule against, read through the scoped mappers.
	 *
	 * @param Schema $schema The schema the object belongs to.
	 * @param string $register Register id, uuid or slug.
	 * @param string $objectId Object id, uuid, slug or uri.
	 *
	 * @return ObjectEntity|null The object, or null when it cannot be read.
	 */
	private function storedObject(Schema $schema, string $register, string $objectId): ?ObjectEntity {
		try {
			return $this->lookups->objectMapper->findInRegisterSchemaTable(
				identifier: $objectId,
				register: $this->lookups->registerMapper->find($register),
				schema: $schema
			);
		} catch (Throwable $e) {
			return null;
		}
	}//end storedObject()

	/**
	 * The rule to try: the declared one, or the unsaved one the caller supplied.
	 *
	 * @param Schema $schema The schema the rule belongs to.
	 * @param string $ruleId The derived rule id.
	 * @param array<string, mixed>|null $override An unsaved declaration.
	 *
	 * @return RuleDescriptor|array<string, mixed> The rule, or the refusal to return.
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	private function resolve(Schema $schema, string $ruleId, ?array $override): RuleDescriptor|array {
		$declared = $this->inventory->find(schema: $schema, ruleId: $ruleId);
		if ($override !== null && isset($override['expression']) === true) {
			return $this->draft(schema: $schema, declared: $declared, expression: $override['expression']);
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
	 * The unsaved rule a caller is trying.
	 *
	 * The caller is trying something that is not saved. It borrows the
	 * declared rule's identity so the response is about the rule the author
	 * is editing, and nothing at all is written.
	 *
	 * @param Schema $schema The schema the rule belongs to.
	 * @param RuleDescriptor|null $declared The declared rule it stands in for, when there is one.
	 * @param mixed $expression The unsaved expression.
	 *
	 * @return RuleDescriptor The draft rule.
	 */
	private function draft(Schema $schema, ?RuleDescriptor $declared, mixed $expression): RuleDescriptor {
		return new RuleDescriptor(
			kind: ($declared?->getKind() ?? RuleVocabulary::KIND_CALCULATION),
			schemaSlug: (string)($schema->getSlug() ?? ''),
			key: ($declared?->getKey() ?? 'draft'),
			label: ($declared?->getKey() ?? 'draft'),
			source: 'draft',
			actions: [RuleVocabulary::ACTION_SET_VALUE],
			condition: $expression
		);
	}//end draft()

	/**
	 * Evaluate one rule against one payload.
	 *
	 * @param RuleDescriptor $rule The rule to evaluate.
	 * @param array<string, mixed> $payload The object payload.
	 * @param string|null $objectUuid The object evaluated, when it was a stored one.
	 *
	 * @return array<string, mixed> The trial result.
	 *
	 * @spec openspec/specs/flow-engine/spec.md
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
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	private function runCalculation(RuleDescriptor $rule, array $payload, ?string $objectUuid): array {
		try {
			// No SequenceContext: a trial must never reserve a running number.
			$value = $this->engine->evaluator->evaluate($payload, $rule->getCondition());
		} catch (EvaluationException $e) {
			$errored = RuleTrace::errored(message: $e->getMessage());
			return $this->result(rule: $rule, trace: $errored, writes: [], objectUuid: $objectUuid);
		}

		return $this->result(rule: $rule, trace: RuleTrace::fired(), writes: [$rule->getKey() => $value], objectUuid: $objectUuid);
	}//end runCalculation()

	/**
	 * Evaluate a transition condition and report whether it would refuse.
	 *
	 * THE SAME CLASS THE SAVE PATH ASKS. Task 4.2 moved
	 * LifecycleConditionEvaluator onto ConditionDialect and this with it, in
	 * one change, because a trial that understood a dialect the save path
	 * does not would answer "it fires" about a rule that refuses every
	 * transition in production. That is worse than not offering the dialect
	 * at all, and it is why the two are never moved separately.
	 *
	 * @param RuleDescriptor $rule The condition.
	 * @param array<string, mixed> $payload The object payload.
	 * @param string|null $objectUuid The object evaluated, when it was a stored one.
	 *
	 * @return array<string, mixed> The trial result.
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	private function runCondition(RuleDescriptor $rule, array $payload, ?string $objectUuid): array {
		$document = $this->conditionDocument(rule: $rule, payload: $payload);
		$verdict = RuleVocabulary::VERDICT_NO_MATCH;
		if ($this->engine->dialect->holds(node: $rule->getCondition(), document: $document) === true) {
			$verdict = RuleVocabulary::VERDICT_FIRED;
		}

		$trace = $this->engine->tracer->trace(condition: $rule->getCondition(), document: $document, verdict: $verdict);

		return $this->result(rule: $rule, trace: $trace, writes: [], objectUuid: $objectUuid);
	}//end runCondition()

	/**
	 * The document a transition condition is evaluated against.
	 *
	 * The same four-key document the save path builds, with the transition
	 * half left empty: a trial is asking about the object, and inventing a
	 * transition it is not making would answer a question nobody asked.
	 *
	 * @param RuleDescriptor $rule The condition.
	 * @param array<string, mixed> $payload The object payload.
	 *
	 * @return array<string, mixed> The document.
	 */
	private function conditionDocument(RuleDescriptor $rule, array $payload): array {
		return [
			'object' => $payload,
			'previous' => $payload,
			'user' => ['uid' => '', 'groups' => []],
			'transition' => ['action' => $rule->getKey(), 'from' => '', 'to' => ''],
		];
	}//end conditionDocument()

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
	 * @spec openspec/specs/flow-engine/spec.md
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
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	private function refusal(string $code, string $message): array {
		return ['ok' => false, 'committed' => false, 'error' => ['code' => $code, 'message' => $message]];
	}//end refusal()
}//end class
