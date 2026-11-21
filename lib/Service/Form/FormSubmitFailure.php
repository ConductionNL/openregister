<?php

/**
 * OpenRegister Form Submit Failure
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Form
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Form;

use Throwable;

/**
 * The write of a submit that was refused, and why, as the compensation records it.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Form
 */
final class FormSubmitFailure {

	/**
	 * Constructor.
	 *
	 * @param string    $failedAt The write that was refused.
	 * @param Throwable $reason   Why.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $failedAt,
		public readonly Throwable $reason,
	) {

	}//end __construct()
}//end class
