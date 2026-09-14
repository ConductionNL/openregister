<?php

/**
 * TimelineValidationException: a timeline write that does not fit its kind.
 *
 * Carries the per-field reasons, so the controller answers 400 naming every
 * field that is wrong rather than the first one it met. A caller fixing a form
 * should need one round trip, not four.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\Timeline
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Timeline;

use Exception;

/**
 * A timeline write refused because it does not fit its declaration.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Timeline
 */
class TimelineValidationException extends Exception {

	/**
	 * The reason per field.
	 *
	 * @var array<string,string>
	 */
	private array $errors;

	/**
	 * Constructor.
	 *
	 * @param array<string,string> $errors The reason per field.
	 *
	 * @return void
	 */
	public function __construct(array $errors) {
		$this->errors = $errors;

		parent::__construct(implode('; ', $errors));
	}//end __construct()

	/**
	 * The reason per field.
	 *
	 * @return array<string,string> The errors.
	 */
	public function getErrors(): array {
		return $this->errors;
	}//end getErrors()
}//end class
