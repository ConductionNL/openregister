<?php

/**
 * OpenRegister ConsentPropertyWrite
 *
 * One write to one consent-shaped property: what was declared, what the
 * caller sent and what was stored before.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Consent
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/consent-evidence-envelope/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Consent;

/**
 * One write to one consent-shaped property.
 *
 * {@see ConsentEnvelopeEvaluator} judges it append-only and fills the
 * evidence of every newly appended entry. A value that is not an array
 * reads as no entries, exactly as the evaluator always read it.
 *
 * @spec openspec/specs/consent-evidence-envelope/spec.md
 */
final class ConsentPropertyWrite {

	/**
	 * Name the write.
	 *
	 * @param string               $name       The property name (used only in the refusal message).
	 * @param array<string, mixed> $annotation The property's `x-openregister-consent` declaration.
	 * @param mixed                $incoming   The caller-submitted value for this property.
	 * @param mixed                $persisted  The previously persisted value for this property.
	 *
	 * @spec openspec/specs/consent-evidence-envelope/spec.md
	 */
	public function __construct(
		public readonly string $name,
		public readonly array $annotation,
		public readonly mixed $incoming,
		public readonly mixed $persisted,
	) {
	}//end __construct()

	/**
	 * The submitted entries, re-indexed; none when the value is not an array.
	 *
	 * @return array<int, mixed>
	 */
	public function incomingEntries(): array {
		return self::entries(value: $this->incoming);
	}//end incomingEntries()

	/**
	 * The stored entries, re-indexed; none when the value is not an array.
	 *
	 * @return array<int, mixed>
	 */
	public function persistedEntries(): array {
		return self::entries(value: $this->persisted);
	}//end persistedEntries()

	/**
	 * Normalise a property value into a re-indexed array.
	 *
	 * @param mixed $value The raw value.
	 *
	 * @return array<int, mixed>
	 */
	private static function entries(mixed $value): array {
		if (is_array($value) === false) {
			return [];
		}

		return array_values($value);
	}//end entries()
}//end class
