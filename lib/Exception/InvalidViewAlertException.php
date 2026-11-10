<?php

/**
 * A view's declared count alert that does not read.
 *
 * Carries the FIELD as well as the message, so the views controller can answer
 * 422 naming the input to fix instead of the generic 400 every other refused
 * view save gets. It stays an InvalidArgumentException, so every caller that
 * already catches one (the alert sweep, the tests of the validator) is
 * unchanged.
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/saved-view-count-alert/specs/saved-search-views/spec.md#requirement-a-view-may-declare-a-count-alert
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Exception;

use InvalidArgumentException;

/**
 * A malformed view alert, naming its field.
 */
class InvalidViewAlertException extends InvalidArgumentException {

	/**
	 * Constructor.
	 *
	 * @param string $field   The field to fix, such as `alert.operator`.
	 * @param string $message What is wrong with it, starting with the field.
	 *
	 * @spec openspec/changes/saved-view-count-alert/specs/saved-search-views/spec.md#requirement-a-view-may-declare-a-count-alert
	 */
	public function __construct(
		private readonly string $field,
		string $message,
	) {
		parent::__construct(message: $message);
	}//end __construct()

	/**
	 * The field to fix.
	 *
	 * @return string The field, such as `alert.operator`.
	 *
	 * @spec openspec/changes/saved-view-count-alert/specs/saved-search-views/spec.md#requirement-a-view-may-declare-a-count-alert
	 */
	public function getField(): string {
		return $this->field;
	}//end getField()

	/**
	 * The HTTP status a save refused for this reason answers with.
	 *
	 * @return int 422: the alert does not read.
	 *
	 * @spec openspec/specs/saved-search-views/spec.md
	 */
	public function getStatus(): int {
		return 422;
	}//end getStatus()

	/**
	 * Extra fields for the refusal's response body.
	 *
	 * @return array<string, mixed> None for a malformed alert.
	 *
	 * @spec openspec/specs/saved-search-views/spec.md
	 */
	public function getDetails(): array {
		return [];
	}//end getDetails()
}//end class
