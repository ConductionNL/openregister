<?php

/**
 * OpenRegister RedactionNotPublishableException
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/redaction-release-safeguards/tasks.md#task-1.4
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Exception;

use Exception;

/**
 * An anonymised copy whose redaction verdict does not permit publication.
 */
class RedactionNotPublishableException extends Exception {

	/**
	 * Constructor.
	 *
	 * @param string       $message The message for the operator.
	 * @param string       $verdict The verdict that stopped it.
	 * @param list<string> $routes  The routes a value was found on.
	 *
	 * @spec openspec/changes/redaction-release-safeguards/tasks.md#task-1.4
	 */
	public function __construct(
		string $message,
		public readonly string $verdict,
		public readonly array $routes,
	) {
		parent::__construct(message: $message);
	}//end __construct()
}//end class
