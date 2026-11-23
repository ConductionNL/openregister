<?php

/**
 * OpenRegister grant declaration
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

/**
 * An authorization block and role definitions as written on a register or schema, checked against the verbs that exist.
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */
final class GrantDeclaration {
	/**
	 * Constructor.
	 *
	 * @param array|null $authorization   The block as written.
	 * @param mixed      $roleDefinitions The register's role definitions, when there are any.
	 * @param string     $subject         What this belongs to, for the message.
	 */
	public function __construct(
		public readonly ?array $authorization,
		public readonly mixed $roleDefinitions,
		public readonly string $subject,
	) {
	}//end __construct()
}//end class
