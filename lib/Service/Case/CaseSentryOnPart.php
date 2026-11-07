<?php

/**
 * OpenRegister CaseSentryOnPart
 *
 * The on-part of a case sentry: whether it has occurred, and whether the
 * editor should have accepted it.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Case
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-cases/spec.md#requirement-sentries-are-entry-and-exit-criteria-over-existing-engine-primitives
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Case;

use OCA\OpenRegister\Exception\CaseValidationException;
use OCA\OpenRegister\Service\Flow\EventCatalogService;

/**
 * Evaluates and validates a sentry's `{event, item?}` on-part.
 *
 * @spec openspec/specs/flow-cases/spec.md#requirement-sentries-are-entry-and-exit-criteria-over-existing-engine-primitives
 */
final class CaseSentryOnPart {

	/**
	 * Constructor.
	 *
	 * @param EventCatalogService $catalog The closed event catalog.
	 */
	public function __construct(
		private readonly EventCatalogService $catalog,
	) {
	}//end __construct()

	/**
	 * Whether an on-part has occurred: a plan-item event against current
	 * state (monotonic), any other event against the one being handled.
	 *
	 * @param mixed            $onPart The on-part: `{event, item?}`.
	 * @param CaseSentryMoment $moment The plan, event and document the pass reads.
	 *
	 * @return boolean True when it has.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-sentries-are-entry-and-exit-criteria-over-existing-engine-primitives
	 */
	public function occurred(mixed $onPart, CaseSentryMoment $moment): bool {
		$name = self::onPartEvent(onPart: $onPart);
		if ($name === '') {
			return false;
		}

		if (array_key_exists($name, CaseSentryEvaluator::ITEM_EVENTS) === true) {
			return self::itemEventOccurred(onPart: $onPart, moment: $moment);
		}

		return $moment->event !== null && in_array($moment->event, $this->catalog->aliasesFor(dispatched: $name), true);
	}//end occurred()

	/**
	 * Whether the item a plan-item on-part names is in the state its event stands for.
	 *
	 * @param array<string, mixed> $onPart The on-part, already known to name a plan-item event.
	 * @param CaseSentryMoment     $moment The plan, event and document the pass reads.
	 *
	 * @return boolean True when the named item holds that state.
	 */
	private static function itemEventOccurred(array $onPart, CaseSentryMoment $moment): bool {
		$key = self::onPartItem(onPart: $onPart);
		if ($key === '') {
			return false;
		}

		return $moment->tree->keyHasState(key: $key, state: CaseSentryEvaluator::ITEM_EVENTS[self::onPartEvent(onPart: $onPart)]);
	}//end itemEventOccurred()

	/**
	 * The trimmed event an on-part names, or '' when it is not an object or names none.
	 *
	 * @param mixed $onPart The on-part.
	 *
	 * @return string The event id.
	 */
	private static function onPartEvent(mixed $onPart): string {
		if (is_array($onPart) === false) {
			return '';
		}

		return trim((string)($onPart['event'] ?? ''));
	}//end onPartEvent()

	/**
	 * The trimmed item key an on-part names, or ''.
	 *
	 * @param array<string, mixed> $onPart The on-part.
	 *
	 * @return string The item key.
	 */
	private static function onPartItem(array $onPart): string {
		return trim((string)($onPart['item'] ?? ''));
	}//end onPartItem()

	/**
	 * Save-time check of one on-part.
	 *
	 * @param mixed  $onPart The on-part.
	 * @param string $label  The sentry, for the message.
	 *
	 * @return void
	 *
	 * @throws CaseValidationException Naming the unknown event or the missing item.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-sentries-are-entry-and-exit-criteria-over-existing-engine-primitives
	 */
	public function validate(mixed $onPart, string $label): void {
		$name = self::onPartEvent(onPart: $onPart);
		if ($name === '') {
			throw new CaseValidationException(message: sprintf('%s has an on-part without an event.', $label));
		}

		$this->validateKnownEvent(name: $name, label: $label);
		if (array_key_exists($name, CaseSentryEvaluator::ITEM_EVENTS) === true && self::onPartItem(onPart: $onPart) === '') {
			throw new CaseValidationException(
				message: sprintf("%s names plan-item event '%s' without naming the item.", $label, $name)
			);
		}
	}//end validate()

	/**
	 * Refuse an event the catalog does not know.
	 *
	 * @param string $name  The event id.
	 * @param string $label The sentry, for the message.
	 *
	 * @return void
	 *
	 * @throws CaseValidationException Naming the unknown event.
	 */
	private function validateKnownEvent(string $name, string $label): void {
		if (in_array($name, $this->catalog->knownTriggerIds(), true) === false) {
			throw new CaseValidationException(
				message: sprintf("%s names event '%s', which is not in the event catalog.", $label, $name)
			);
		}
	}//end validateKnownEvent()
}//end class
