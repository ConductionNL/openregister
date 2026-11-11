<?php

/**
 * OpenRegister CodedValueCheck
 *
 * One pass of {@see CodedValueGuard} over a submitted object: the object, the
 * instant windows are judged at, and the exclusive groups already held by a
 * value earlier in the pass. The held groups span every coded property of
 * the object, which is what lets two properties on one scheme clash.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Vocabulary
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/skos-concept-registers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Vocabulary;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * The state of one coded-value check over a submitted object.
 */
final class CodedValueCheck {

	/**
	 * The label of the first value holding each scheme+group key.
	 *
	 * @var array<string,string>
	 */
	private array $heldLabels = [];

	/**
	 * The instant to judge windows at.
	 *
	 * @var DateTimeInterface
	 */
	public readonly DateTimeInterface $instant;

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed>    $object The submitted object data.
	 * @param DateTimeInterface|null $asOf   The instant to judge windows at; now when null.
	 */
	public function __construct(
		private readonly array $object,
		?DateTimeInterface $asOf = null,
	) {
		$this->instant = ($asOf ?? new DateTimeImmutable());
	}//end __construct()

	/**
	 * The check of one coded property of the object.
	 *
	 * @param string                   $property    The property name.
	 * @param CodedPropertyDeclaration $declaration The property's coded declaration.
	 *
	 * @return CodedValueSubject The property's view of this check.
	 */
	public function subjectFor(string $property, CodedPropertyDeclaration $declaration): CodedValueSubject {
		return new CodedValueSubject(property: $property, declaration: $declaration, check: $this);
	}//end subjectFor()

	/**
	 * The value the object submits for a property, or null when absent.
	 *
	 * @param string $property The property name.
	 *
	 * @return mixed The submitted value.
	 */
	public function submitted(string $property): mixed {
		return ($this->object[$property] ?? null);
	}//end submitted()

	/**
	 * Claim a scheme+group key for a value, unless an earlier value holds it.
	 *
	 * @param string $key   The scheme+group key.
	 * @param string $label The claiming value's label.
	 *
	 * @return string|null The earlier holder's label, or null when the claim succeeded.
	 */
	public function claim(string $key, string $label): ?string {
		$holder = ($this->heldLabels[$key] ?? null);
		if ($holder === null) {
			$this->heldLabels[$key] = $label;
		}

		return $holder;
	}//end claim()
}//end class
