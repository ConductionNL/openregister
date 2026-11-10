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

use OCA\OpenRegister\Db\MagicMapper\MagicTableRowReaderInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Kernel\Contracts\ObjectRef;
use OCA\OpenRegister\Service\Calculation\EvaluationException;
use OCA\OpenRegister\Service\Rules\Support\RuleTrialEngine;
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
	 * @param RuleTargets     $targets The declared rules and the register and table a named object is read from.
	 * @param RuleTrialEngine $engine  The evaluator, the tracer and the dialect a rule is tried through.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly RuleTargets $targets,
		private readonly RuleTrialEngine $engine,
	) {
	}//end __construct()

	/**
	 * Evaluate a rule against a sample payload, committing nothing.
	 *
	 * @param RuleTrialRequest     $request The rule to try: schema, rule id and optional unsaved declaration.
	 * @param array<string, mixed> $sample  The object payload to evaluate against.
	 *
	 * @return array<string, mixed> The verdict, the trace and the writes it would make.
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	public function trySample(RuleTrialRequest $request, array $sample): array {
		$rule = $this->resolve(request: $request);
		if ($rule instanceof RuleDescriptor === false) {
			return $rule;
		}

		return $this->run(run: new RuleTrialRun(rule: $rule, payload: $sample));
	}//end trySample()

	/**
	 * Evaluate a rule against an object that already exists, committing nothing.
	 *
	 * The object is read through the RBAC- and tenancy-scoped mapper, so a
	 * caller who may not read it gets a not-found refusal rather than its
	 * values. That lookup is this path's per-object authorisation guard.
	 *
	 * @param RuleTrialRequest $request The rule to try.
	 * @param ObjectRef        $ref     The register and object to read; the schema is the request's.
	 *
	 * @return array<string, mixed> The verdict, the trace and the writes it would make.
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	public function tryObject(RuleTrialRequest $request, ObjectRef $ref): array {
		$rule = $this->resolve(request: $request);
		if ($rule instanceof RuleDescriptor === false) {
			return $rule;
		}

		$object = $this->storedObject(schema: $request->schema, ref: $ref);
		if ($object === null) {
			$message = sprintf('Object "%s" could not be read in register "%s".', $ref->id, (string)$ref->register);
			return $this->refusal(code: self::CODE_OBJECT_NOT_FOUND, message: $message);
		}

		return $this->run(run: new RuleTrialRun(rule: $rule, payload: ($object->getObject() ?? []), objectUuid: ($object->getUuid() ?? null)));
	}//end tryObject()

	/**
	 * The stored object to try a rule against, read through the scoped mappers.
	 *
	 * @param Schema $schema The schema the object belongs to.
	 * @param ObjectRef $ref The register (id, uuid or slug) and object (id, uuid, slug or uri).
	 *
	 * @return ObjectEntity|null The object, or null when it cannot be read.
	 */
	private function storedObject(Schema $schema, ObjectRef $ref): ?ObjectEntity {
		try {
			return $this->rows()->findInRegisterSchemaTable(
				identifier: $ref->id,
				register: $this->targets->catalog->registers()->find($ref->register),
				schema: $schema
			);
		} catch (Throwable $e) {
			return null;
		}
	}//end storedObject()

	/**
	 * The row reader a stored object is read through: the magic table mapper, in its row-reader role.
	 *
	 * @return MagicTableRowReaderInterface The reader.
	 */
	private function rows(): MagicTableRowReaderInterface {
		return $this->targets->catalog->tables();
	}//end rows()

	/**
	 * The rule to try: the declared one, or the unsaved one the caller supplied.
	 *
	 * @param RuleTrialRequest $request The rule to try.
	 *
	 * @return RuleDescriptor|array<string, mixed> The rule, or the refusal to return.
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	private function resolve(RuleTrialRequest $request): RuleDescriptor|array {
		$declared = $this->targets->rules->find(schema: $request->schema, ruleId: $request->ruleId);
		if ($request->override !== null && isset($request->override['expression']) === true) {
			return $this->draft(request: $request, declared: $declared);
		}

		if ($declared === null) {
			return $this->refusal(
				code: self::CODE_UNKNOWN_RULE,
				message: sprintf('Schema "%s" declares no rule "%s".', $request->schemaSlug(), $request->ruleId)
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
	 * @param RuleTrialRequest $request The rule to try, carrying the unsaved expression.
	 * @param RuleDescriptor|null $declared The declared rule it stands in for, when there is one.
	 *
	 * @return RuleDescriptor The draft rule.
	 */
	private function draft(RuleTrialRequest $request, ?RuleDescriptor $declared): RuleDescriptor {
		return new RuleDescriptor(
			origin: new RuleOrigin(
				kind: ($declared?->getKind() ?? RuleVocabulary::KIND_CALCULATION),
				schemaSlug: $request->schemaSlug(),
				key: ($declared?->getKey() ?? 'draft'),
				source: 'draft'
			),
			label: ($declared?->getKey() ?? 'draft'),
			effect: new RuleEffect(actions: [RuleVocabulary::ACTION_SET_VALUE], condition: $request->override['expression'])
		);
	}//end draft()

	/**
	 * Evaluate one rule against one payload.
	 *
	 * @param RuleTrialRun $run The rule, the payload and the object it came from.
	 *
	 * @return array<string, mixed> The trial result.
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	private function run(RuleTrialRun $run): array {
		if ($run->rule->getKind() === RuleVocabulary::KIND_CALCULATION) {
			return $this->runCalculation(run: $run);
		}

		if ($run->rule->getKind() === RuleVocabulary::KIND_LIFECYCLE_CONDITION) {
			return $this->runCondition(run: $run);
		}

		// A state field block decides what a RENDER shows and a flow is a run of
		// its own with its own log; neither is a verdict about one payload, and
		// answering one anyway would be a number that looks like an answer.
		return $this->refusal(
			code: self::CODE_NOT_EVALUABLE,
			message: sprintf('A rule of kind "%s" cannot be tried against a single payload.', $run->rule->getKind())
		);
	}//end run()

	/**
	 * Evaluate a calculation and report the value it would have written.
	 *
	 * @param RuleTrialRun $run The calculation, the payload and the object it came from.
	 *
	 * @return array<string, mixed> The trial result.
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	private function runCalculation(RuleTrialRun $run): array {
		try {
			// No SequenceContext: a trial must never reserve a running number.
			$value = $this->engine->evaluator->evaluate($run->payload, $run->rule->getCondition());
		} catch (EvaluationException $e) {
			return $this->result(run: $run, trace: RuleTrace::errored(message: $e->getMessage()));
		}

		return array_replace($this->result(run: $run, trace: RuleTrace::fired()), ['writes' => [$run->rule->getKey() => $value]]);
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
	 * @param RuleTrialRun $run The condition, the payload and the object it came from.
	 *
	 * @return array<string, mixed> The trial result.
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	private function runCondition(RuleTrialRun $run): array {
		$condition = $run->rule->getCondition();
		$document = $this->conditionDocument(rule: $run->rule, payload: $run->payload);
		$verdict = RuleVocabulary::VERDICT_NO_MATCH;
		if ($this->engine->dialect->holds(node: $condition, document: $document) === true) {
			$verdict = RuleVocabulary::VERDICT_FIRED;
		}

		return $this->result(run: $run, trace: $this->engine->tracer->trace(judgement: new ConditionJudgement(condition: $condition, document: $document, verdict: $verdict)));
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
	 * Shape one trial, with no writes; a calculation that fired adds its own.
	 *
	 * @param RuleTrialRun $run The rule that was tried and the object it was tried on.
	 * @param RuleTrace $trace The verdict and its deciding operand.
	 *
	 * @return array<string, mixed> The trial result.
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	private function result(RuleTrialRun $run, RuleTrace $trace): array {
		return [
			'ok' => ($trace->getVerdict() !== RuleVocabulary::VERDICT_ERROR),
			'committed' => false,
			'ruleId' => $run->rule->getId(),
			'kind' => $run->rule->getKind(),
			'objectUuid' => $run->objectUuid,
			'trace' => $trace->jsonSerialize(),
			'writes' => [],
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
