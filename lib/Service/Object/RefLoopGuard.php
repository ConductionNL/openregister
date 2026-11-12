<?php

/**
 * Ends a JSON Schema reference that comes back to itself without moving through the data.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/object-validation/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\KeywordValidators\AbstractKeywordValidator;
use Opis\JsonSchema\ValidationContext;

/**
 * Sits on one schema node with a reference and refuses to enter it twice at one place in the data.
 *
 * A reference that descends into the data arrives at a new place each time, so real recursion is
 * untouched. One that comes back at the same place adds no constraint and would never end, so
 * the second visit passes.
 *
 * @spec openspec/specs/object-validation/spec.md
 */
final class RefLoopGuard extends AbstractKeywordValidator {

	/**
	 * The places in the data this node is being validated at.
	 *
	 * @var array<string, true>
	 */
	private array $active = [];

	/**
	 * Validate the node, unless it is already being validated at this place.
	 *
	 * @param ValidationContext $context The validation context.
	 *
	 * @return ValidationError|null The error, or null when the data passes.
	 *
	 * @spec openspec/specs/object-validation/spec.md
	 */
	public function validate(ValidationContext $context): ?ValidationError {
		$place = implode("\0", $context->fullDataPath());
		if (isset($this->active[$place]) === true) {
			return null;
		}

		$this->active[$place] = true;
		try {
			return $this->next?->validate($context);
		} finally {
			unset($this->active[$place]);
		}
	}//end validate()
}//end class
