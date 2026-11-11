<?php

/**
 * OpenRegister ApplicationMove
 *
 * Moving schemas and registers from one application id to another.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
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

namespace OCA\OpenRegister\Service;

/**
 * The application id rows are owned by now, and the one they move to.
 *
 * @spec openspec/specs/data-import-export/spec.md
 */
final class ApplicationMove {

	/**
	 * Name the move.
	 *
	 * @param string $from The current application id.
	 * @param string $to   The new application id.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function __construct(
		public readonly string $from,
		public readonly string $to,
	) {
	}//end __construct()
}//end class
