<?php

/**
 * OpenRegister row failure
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
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

namespace OCA\OpenRegister\Service\Object;

/**
 * One row of a batch that could not be saved.
 *
 * `$index` is the row's position in the submitted batch. A create that fails
 * has no UUID yet, so without the position the caller cannot tell which of
 * its rows was refused (issue #2778).
 *
 * @spec openspec/specs/reference-existence-validation/spec.md
 */
final class RowFailure {
	/**
	 * Constructor.
	 *
	 * @param string|null $uuid           UUID of the input row, or null when not yet assigned.
	 * @param string      $message        Human-readable failure message.
	 * @param string      $exceptionClass Fully-qualified class name of the exception.
	 * @param int|null    $index          Position of the row in the submitted batch, when known.
	 */
	public function __construct(
		public readonly ?string $uuid,
		public readonly string $message,
		public readonly string $exceptionClass,
		public readonly ?int $index = null,
	) {
	}//end __construct()
}//end class
