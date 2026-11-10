<?php

/**
 * OpenRegister ConfigurationAction
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
 * An action on one stored configuration and how its failure is reported:
 * the verb of the failure log and the message of the 500.
 *
 * @spec openspec/specs/data-import-export/spec.md
 */
final class ConfigurationAction {
	/**
	 * Describe the action.
	 *
	 * @param int    $id      The configuration id
	 * @param string $verb    The verb of the failure log ("Failed to <verb> configuration <id>")
	 * @param string $failure The message of the 500, or with $generic its log context
	 * @param bool   $generic Whether the 500 is the generic one, with $failure only logged
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $verb,
		public readonly string $failure,
		public readonly bool $generic=false,
	) {
	}//end __construct()
}//end class
