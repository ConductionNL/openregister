<?php

/**
 * OpenRegister ExpressionDefaultException
 *
 * The refusal an unevaluable expression default produces, naming the property.
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
 * @spec openspec/changes/rules-engine-operability/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rules;

use RuntimeException;

/**
 * A create refused because a derived default could not be derived.
 *
 * It names the property, because "validation failed" about a value the caller
 * never sent is the least actionable message the save path can produce.
 *
 * @spec openspec/changes/rules-engine-operability/specs/object-lifecycle/spec.md
 */
final class ExpressionDefaultException extends RuntimeException {

	/**
	 * Constructor.
	 *
	 * @param string $property The property whose default failed.
	 * @param string $message The refusal, naming the property.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly string $property,
		string $message,
	) {
		parent::__construct(message: $message, code: 422);
	}//end __construct()

	/**
	 * The property whose default failed.
	 *
	 * @return string The property name.
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/object-lifecycle/spec.md
	 */
	public function getProperty(): string {
		return $this->property;
	}//end getProperty()

	/**
	 * The refusal in the shape a client reads.
	 *
	 * @return array<string, mixed> The refusal envelope.
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/object-lifecycle/spec.md
	 */
	public function toArray(): array {
		return [
			'code' => ExpressionDefaultResolver::CODE_UNEVALUABLE,
			'property' => $this->property,
			'message' => $this->getMessage(),
		];
	}//end toArray()
}//end class
