<?php

/**
 * OpenRegister grant rows query
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
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

namespace OCA\OpenRegister\Service\Rbac;

use OCA\OpenRegister\Kernel\Contracts\RegisterSchemaRef;

/**
 * Which register and schema an object's grant rows are read in, and which principal types to keep.
 *
 * @spec openspec/changes/an-object-lists-its-grants-with-their-origin/specs/rbac-scopes/spec.md
 */
final class GrantRowsQuery {
	/**
	 * Constructor.
	 *
	 * @param RegisterSchemaRef $table          The register and schema as the request named them.
	 * @param string[]          $principalTypes Keep only these principal types; empty keeps all.
	 */
	public function __construct(
		public readonly RegisterSchemaRef $table,
		public readonly array $principalTypes = [],
	) {
	}//end __construct()
}//end class
