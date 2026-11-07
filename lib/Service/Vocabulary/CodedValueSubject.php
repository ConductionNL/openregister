<?php

/**
 * OpenRegister CodedValueSubject
 *
 * One coded property inside a {@see CodedValueCheck}: its name, its
 * declaration and, once read, the scheme's concepts its values are judged
 * against.
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

/**
 * A coded property being checked.
 */
final class CodedValueSubject {

	/**
	 * Constructor.
	 *
	 * @param string                            $property      The property name.
	 * @param CodedPropertyDeclaration          $declaration   The property's coded declaration.
	 * @param CodedValueCheck                   $check         The pass this property belongs to.
	 * @param array<string,array<string,mixed>> $conceptsByUri The scheme's concepts, keyed by uri.
	 */
	public function __construct(
		public readonly string $property,
		public readonly CodedPropertyDeclaration $declaration,
		public readonly CodedValueCheck $check,
		public readonly array $conceptsByUri = [],
	) {

	}//end __construct()

	/**
	 * The same subject with the scheme's concepts read.
	 *
	 * @param array<string,array<string,mixed>> $conceptsByUri The scheme's concepts, keyed by uri.
	 *
	 * @return self The subject carrying them.
	 */
	public function withConcepts(array $conceptsByUri): self {
		return new self(
			property: $this->property,
			declaration: $this->declaration,
			check: $this->check,
			conceptsByUri: $conceptsByUri
		);
	}//end withConcepts()

	/**
	 * Pair a submitted value of this property with the concept it resolved to.
	 *
	 * @param string              $value   The submitted value.
	 * @param array<string,mixed> $concept The resolved concept's data.
	 *
	 * @return CodedValueMatch The pair.
	 */
	public function matched(string $value, array $concept): CodedValueMatch {
		return new CodedValueMatch(value: $value, concept: $concept);
	}//end matched()

	/**
	 * The value the object submits for this property, or null when absent.
	 *
	 * @return mixed The submitted value.
	 */
	public function submitted(): mixed {
		return $this->check->submitted(property: $this->property);
	}//end submitted()
}//end class
