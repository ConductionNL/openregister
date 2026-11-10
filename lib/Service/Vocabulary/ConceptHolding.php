<?php

/**
 * OpenRegister ConceptHolding
 *
 * The concept a delete asks about, as the values an object would hold it
 * by: the scheme it belongs to, its uri for properties that store uris and
 * its notation for properties that store notations. The delete guard looks
 * for exactly these values in every schema bound to the scheme.
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
 * A concept as the stored values that would hold it.
 *
 * @spec openspec/specs/skos-concept-registers/spec.md
 */
final class ConceptHolding {

	/**
	 * Constructor.
	 *
	 * @param string $schemeUri The uri of the scheme the concept belongs to.
	 * @param string $uri       The concept's uri, held by properties that store uris.
	 * @param string $notation  The concept's notation, held by properties that store notations.
	 */
	public function __construct(
		public readonly string $schemeUri,
		public readonly string $uri,
		public readonly string $notation,
	) {

	}//end __construct()

	/**
	 * The holding of a concept's decoded object data, trimmed.
	 *
	 * @param array<string,mixed> $concept   The concept's decoded object data.
	 * @param string              $schemeUri The uri of the scheme the concept belongs to.
	 *
	 * @return self The holding.
	 *
	 * @spec openspec/specs/skos-concept-registers/spec.md
	 */
	public static function fromConcept(array $concept, string $schemeUri): self {
		return new self(
			schemeUri: $schemeUri,
			uri: trim((string)($concept['uri'] ?? '')),
			notation: trim((string)($concept['notation'] ?? ''))
		);
	}//end fromConcept()

	/**
	 * Whether the concept has neither a uri nor a notation, so nothing can hold it.
	 *
	 * @return boolean True when there is no value to look for.
	 *
	 * @spec openspec/specs/skos-concept-registers/spec.md
	 */
	public function isAnonymous(): bool {
		return $this->uri === '' && $this->notation === '';
	}//end isAnonymous()
}//end class
