<?php

/**
 * Sentries over the engine's existing primitives, and nothing new.
 *
 * A sentry is `{id, on, if}`. Its **on-part** names an event from the flow
 * event catalog (`case.item.completed` about another plan item, or any
 * object event); its **if-part** is a JSONLogic expression evaluated through
 * {@see FlowExpression::isTrue()}, which already answers FALSE for an
 * expression it cannot evaluate. AND within a sentry, OR across the array.
 *
 * NO operator vocabulary of its own. The reference implementation carried
 * `eq|neq|gt|gte|lt|lte|in|notIn|truthy|falsy`
 * (`procest/lib/Service/Cmmn/SentryEvaluator.php:178-196`); adopting it would
 * have made a fourth condition dialect in a fleet already paying for three
 * (openregister#2787). A case author who knows flow expressions knows sentry
 * expressions.
 *
 * Plan-item on-parts are resolved against CURRENT state, not an event log:
 * `completed`/`terminated`/`disabled` are terminal, so "the event has
 * occurred" and "the item is in that state" are the same question (design
 * D-4). Object on-parts are not monotonic and are resolved against the
 * event being handled.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Case
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-cases/spec.md#requirement-sentries-are-entry-and-exit-criteria-over-existing-engine-primitives
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Case;

use OCA\OpenRegister\Db\CaseItem;
use OCA\OpenRegister\Exception\CaseValidationException;
use OCA\OpenRegister\Service\Flow\EventCatalogService;
use OCA\OpenRegister\Service\Flow\FlowExpression;
use OCA\OpenRegister\Service\Flow\FlowItems;

/**
 * Evaluates and validates entry and exit criteria.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) FlowExpression is the engine's
 * stateless expression facade; calling it statically IS the reuse.
 *
 * @spec openspec/specs/flow-cases/spec.md#requirement-sentries-are-entry-and-exit-criteria-over-existing-engine-primitives
 */
class CaseSentryEvaluator {

	/**
	 * The cause_ref recorded when an item enters on empty entry criteria.
	 */
	public const DEFAULT_ENTRY = 'entry:default';

	/**
	 * The three plan-item events; the catalog carries the same three.
	 *
	 * @var array<string, string> event id => plan-item state.
	 */
	public const ITEM_EVENTS = [
		'case.item.completed' => CaseItem::STATE_COMPLETED,
		'case.item.terminated' => CaseItem::STATE_TERMINATED,
		'case.item.disabled' => CaseItem::STATE_DISABLED,
	];

	/**
	 * Reads and refuses on-parts.
	 *
	 * @var CaseSentryOnPart
	 */
	private readonly CaseSentryOnPart $onParts;

	/**
	 * Constructor.
	 *
	 * @param EventCatalogService $catalog The closed event catalog.
	 */
	public function __construct(
		EventCatalogService $catalog,
	) {
		$this->onParts = new CaseSentryOnPart(catalog: $catalog);
	}//end __construct()

	/**
	 * Refuse a criteria array the editor should not have accepted.
	 *
	 * A sentry naming an event outside `knownTriggerIds()` is refused naming
	 * the event; an if-part that {@see FlowExpression::isValid()} rejects is
	 * refused naming the sentry; a plan-item event without an `item` is
	 * refused. Save time, not run time: the editor fails, not the case.
	 *
	 * @param mixed $criteria The stored criteria (a list of sentries).
	 * @param string $where Which criteria, for the message (`entry`/`exit` of a key).
	 *
	 * @return void
	 *
	 * @throws CaseValidationException On the first refused sentry.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-sentries-are-entry-and-exit-criteria-over-existing-engine-primitives
	 */
	public function validateCriteria(mixed $criteria, string $where): void {
		if ($criteria === null) {
			return;
		}

		if (is_array($criteria) === false) {
			throw new CaseValidationException(message: sprintf('%s criteria must be a list of sentries.', $where));
		}

		foreach ($criteria as $index => $sentry) {
			$this->validateSentry(sentry: $sentry, label: sprintf('%s sentry #%d', $where, (int)$index + 1));
		}
	}//end validateCriteria()

	/**
	 * Save-time check of one sentry.
	 *
	 * @param mixed  $sentry The sentry as stored.
	 * @param string $label  Which sentry, for the message.
	 *
	 * @return void
	 *
	 * @throws CaseValidationException On the first refused part.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-sentries-are-entry-and-exit-criteria-over-existing-engine-primitives
	 */
	private function validateSentry(mixed $sentry, string $label): void {
		self::validateSentryShape(sentry: $sentry, label: $label);
		if (array_key_exists('on', $sentry) === true) {
			$this->onParts->validate(onPart: $sentry['on'], label: $label);
		}

		if (array_key_exists('if', $sentry) === true && FlowExpression::isValid(logic: $sentry['if']) === false) {
			throw new CaseValidationException(message: sprintf('%s has an if-part that is not a valid expression.', $label));
		}
	}//end validateSentry()

	/**
	 * Refuse a sentry that is not an object, or declares neither part.
	 *
	 * @param mixed  $sentry The sentry as stored.
	 * @param string $label  Which sentry, for the message.
	 *
	 * @return void
	 *
	 * @throws CaseValidationException When the sentry has no usable shape.
	 *
	 * @psalm-assert array $sentry
	 * @phpstan-assert array<mixed> $sentry
	 */
	private static function validateSentryShape(mixed $sentry, string $label): void {
		if (is_array($sentry) === false) {
			throw new CaseValidationException(message: sprintf('%s must be an object with an on-part and/or an if-part.', $label));
		}

		if (array_key_exists('on', $sentry) === false && array_key_exists('if', $sentry) === false) {
			throw new CaseValidationException(message: sprintf('%s has neither an on-part nor an if-part.', $label));
		}
	}//end validateSentryShape()

	/**
	 * Which entry sentry admits an item now, if any.
	 *
	 * Empty entry criteria mean "satisfied as soon as the parent is active";
	 * the parent check is the CALLER's (the tree knows the parent), this
	 * method answers the criteria alone.
	 *
	 * @param CaseItem $item The item.
	 * @param CasePlanTree $tree The plan.
	 * @param array<string, mixed> $object The anchoring object's data.
	 * @param string|null $event The event being handled, or null.
	 * @param array<string, mixed> $payload The event's payload.
	 *
	 * @return string|null The admitting sentry's id, {@see DEFAULT_ENTRY}, or null when none fires.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-sentries-are-entry-and-exit-criteria-over-existing-engine-primitives
	 */
	public function entrySentry(CaseItem $item, CasePlanTree $tree, array $object, ?string $event = null, array $payload = []): ?string {
		$criteria = ($item->getEntryCriteria() ?? []);
		if ($criteria === []) {
			return self::DEFAULT_ENTRY;
		}

		$data = $this->dataFor(tree: $tree, object: $object, event: $event, payload: $payload);
		return $this->firingSentry(criteria: $criteria, moment: new CaseSentryMoment(tree: $tree, event: $event, data: $data));
	}//end entrySentry()

	/**
	 * Which exit sentry exits an item now, if any. Empty exit criteria never
	 * fire.
	 *
	 * @param CaseItem $item The item.
	 * @param CasePlanTree $tree The plan.
	 * @param array<string, mixed> $object The anchoring object's data.
	 * @param string|null $event The event being handled, or null.
	 * @param array<string, mixed> $payload The event's payload.
	 *
	 * @return string|null The firing sentry's id, or null.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-sentries-are-entry-and-exit-criteria-over-existing-engine-primitives
	 */
	public function exitSentry(CaseItem $item, CasePlanTree $tree, array $object, ?string $event = null, array $payload = []): ?string {
		$criteria = ($item->getExitCriteria() ?? []);
		if ($criteria === []) {
			return null;
		}

		$data = $this->dataFor(tree: $tree, object: $object, event: $event, payload: $payload);
		return $this->firingSentry(criteria: $criteria, moment: new CaseSentryMoment(tree: $tree, event: $event, data: $data));
	}//end exitSentry()

	/**
	 * The document an if-part is evaluated against: `dataFor()`'s shape
	 * extended with a `case` key. Additive, so a flow expression author
	 * already knows every other key.
	 *
	 * @param CasePlanTree $tree The plan.
	 * @param array<string, mixed> $object The anchoring object's data.
	 * @param string|null $event The event being handled.
	 * @param array<string, mixed> $payload The event's payload.
	 *
	 * @return array<string, mixed> The data document.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-sentries-are-entry-and-exit-criteria-over-existing-engine-primitives
	 */
	public function dataFor(CasePlanTree $tree, array $object, ?string $event, array $payload): array {
		$data = FlowExpression::dataFor(
			item: [FlowItems::JSON => $object],
			context: ['event' => $event, 'payload' => $payload],
			subject: $object
		);
		$data['case'] = ['items' => $tree->stateMap(), 'object' => $object, 'event' => $event, 'payload' => $payload];

		return $data;
	}//end dataFor()

	/**
	 * OR across the array: the first sentry that fires names itself.
	 *
	 * @param array<int, mixed> $criteria The sentries.
	 * @param CaseSentryMoment  $moment   The plan, event and document the pass reads.
	 *
	 * @return string|null The firing sentry's id, or null.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-sentries-are-entry-and-exit-criteria-over-existing-engine-primitives
	 */
	private function firingSentry(array $criteria, CaseSentryMoment $moment): ?string {
		foreach ($criteria as $index => $sentry) {
			if (is_array($sentry) === true && $this->fires(sentry: $sentry, moment: $moment) === true) {
				return (string)($sentry['id'] ?? ('sentry:' . ((int)$index + 1)));
			}
		}

		return null;
	}//end firingSentry()

	/**
	 * AND within a sentry. A malformed sentry (neither part, or an if-part
	 * naming no field) never fires.
	 *
	 * @param array<string, mixed> $sentry The sentry.
	 * @param CaseSentryMoment     $moment The plan, event and document the pass reads.
	 *
	 * @return boolean True when both present parts hold.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-sentries-are-entry-and-exit-criteria-over-existing-engine-primitives
	 */
	private function fires(array $sentry, CaseSentryMoment $moment): bool {
		$hasOn = array_key_exists('on', $sentry);
		if ($hasOn === false && array_key_exists('if', $sentry) === false) {
			return false;
		}

		if ($hasOn === true && $this->onParts->occurred(onPart: $sentry['on'], moment: $moment) === false) {
			return false;
		}

		return $this->ifPartHolds(sentry: $sentry, data: $moment->data);
	}//end fires()

	/**
	 * Whether a sentry's if-part, when present, names a field and evaluates to true.
	 *
	 * @param array<string, mixed> $sentry The sentry.
	 * @param array<string, mixed> $data   The evaluation document.
	 *
	 * @return boolean True when there is no if-part, or it holds.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-sentries-are-entry-and-exit-criteria-over-existing-engine-primitives
	 */
	private function ifPartHolds(array $sentry, array $data): bool {
		if (array_key_exists('if', $sentry) === false) {
			return true;
		}

		if ($this->namesField(logic: $sentry['if']) === false) {
			return false;
		}

		return FlowExpression::isTrue(logic: $sentry['if'], data: $data);
	}//end ifPartHolds()

	/**
	 * Whether an if-part is a rule that reads at least one field: an operator
	 * object (not a list literal, which JSONLogic evaluates to itself and
	 * would fire on being non-empty) with `{"var": ...}` somewhere inside.
	 * A literal `true` names no field and is malformed by the spec's rule.
	 *
	 * @param mixed $logic The if-part.
	 *
	 * @return boolean True when it is a rule object containing a `var`.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-sentries-are-entry-and-exit-criteria-over-existing-engine-primitives
	 */
	private function namesField(mixed $logic): bool {
		if (is_array($logic) === false || $logic === [] || array_is_list($logic) === true) {
			return false;
		}

		return $this->containsVar(logic: $logic);
	}//end namesField()

	/**
	 * Whether a `var` operator occurs anywhere in an expression tree.
	 *
	 * @param mixed $logic Any node of the expression.
	 *
	 * @return boolean True when a `var` key occurs at any depth.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-sentries-are-entry-and-exit-criteria-over-existing-engine-primitives
	 */
	private function containsVar(mixed $logic): bool {
		if (is_array($logic) === false) {
			return false;
		}

		if (array_key_exists('var', $logic) === true) {
			return true;
		}

		foreach ($logic as $child) {
			if ($this->containsVar(logic: $child) === true) {
				return true;
			}
		}

		return false;
	}//end containsVar()
}//end class
