<?php

/**
 * OpenRegister notes path
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Notifications\Controller\Support
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

namespace OCA\OpenRegister\Notifications\Controller\Support;

/**
 * The register, schema and object id a notes request addressed, as the path carried them.
 *
 * @spec openspec/specs/object-interactions/spec.md
 */
final class NotePath {
	/**
	 * Constructor.
	 *
	 * @param string $register The register slug or identifier.
	 * @param string $schema   The schema slug or identifier.
	 * @param string $id       The object id.
	 */
	public function __construct(
		public readonly string $register,
		public readonly string $schema,
		public readonly string $id,
	) {
	}//end __construct()
}//end class
