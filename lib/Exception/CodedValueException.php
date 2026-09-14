<?php

/**
 * OpenRegister CodedValueException
 *
 * A write refused because of what a code list says: a value outside its
 * validity window, two values of one exclusive group, or a broader value
 * under a leaf-only property.
 *
 * All three are unprocessable content rather than a malformed body, so this
 * carries 422 and the controllers answer with it. The distinction matters to
 * a client: 400 says "you sent nonsense", 422 says "what you sent is
 * well-formed and the vocabulary refuses it", and only the second is
 * actionable by the person filling the form.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/code-list-lifecycle-and-hierarchy/specs/skos-concept-registers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Exception;

use RuntimeException;

/**
 * A refusal that names the property, the concept and the rule.
 */
class CodedValueException extends RuntimeException {

	/**
	 * Constructor.
	 *
	 * @param string $message The refusal, in words, naming what was refused.
	 * @param array<string,string> $errors The refusals keyed by property name.
	 */
	public function __construct(
		string $message,
		private readonly array $errors = [],
	) {
		parent::__construct(message: $message, code: 422);
	}//end __construct()

	/**
	 * The refusals keyed by property name.
	 *
	 * @return array<string,string> The per-property messages.
	 *
	 * @spec openspec/changes/code-list-lifecycle-and-hierarchy/specs/skos-concept-registers/spec.md
	 */
	public function getErrors(): array {
		return $this->errors;
	}//end getErrors()
}//end class
