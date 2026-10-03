<?php

/**
 * An anonymisation that was refused, or stopped, with the reason in the message.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Exception
 * @package  OCA\OpenRegister\Service\Archival
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/anonymising-as-an-archival-outcome/specs/retention-management/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Archival;

use RuntimeException;
use Throwable;

/**
 * Thrown when an anonymisation may not run, or could not finish.
 */
class AnonymisationRefusedException extends RuntimeException {

	/**
	 * Build the refusal.
	 *
	 * @param string         $message  Why, in words somebody can act on.
	 * @param Throwable|null $previous The underlying failure, when there was one.
	 */
	public function __construct(string $message, ?Throwable $previous = null) {
		parent::__construct(message: $message, code: 0, previous: $previous);
	}//end __construct()
}//end class
