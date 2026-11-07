<?php

/**
 * OpenRegister CodedValueMatch
 *
 * A submitted coded value together with the concept it resolved to.
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
 * A submitted value and its resolved concept.
 */
final class CodedValueMatch {

	/**
	 * Constructor.
	 *
	 * @param string              $value   The submitted value.
	 * @param array<string,mixed> $concept The resolved concept's data.
	 */
	public function __construct(
		public readonly string $value,
		public readonly array $concept,
	) {

	}//end __construct()

	/**
	 * The concept's uri, falling back to the submitted value when it carries none.
	 *
	 * @return string The uri.
	 */
	public function uri(): string {
		return (string) ($this->concept['uri'] ?? $this->value);
	}//end uri()
}//end class
