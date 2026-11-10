<?php

/**
 * OpenRegister RepositoryCallFailure
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
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

namespace OCA\OpenRegister\Controller\Support;

/**
 * How a failed admin repository call is reported: the line it logs and the
 * context label of its generic 500.
 *
 * @spec openspec/specs/data-import-export/spec.md
 */
final class RepositoryCallFailure {
	/**
	 * Describe the failure report.
	 *
	 * @param string $log     The log message, without prefix and reason
	 * @param string $context The context label of the 500
	 */
	public function __construct(
		public readonly string $log,
		public readonly string $context,
	) {
	}//end __construct()
}//end class
