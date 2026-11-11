<?php

/**
 * DefinitionWriteRightsInterface: who may write a register or schema definition.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

/**
 * The methods of DefinitionWriteRights that callers outside its component use.
 * Callers depend on this interface, not on the class.
 */
interface DefinitionWriteRightsInterface {

	/**
	 * Whether the signed-in user is an administrator.
	 *
	 * @return bool False without a signed-in user.
	 *
	 * @spec openspec/specs/mcp-discovery/spec.md#requirement-a-definition-tool-holds-the-caller-to-the-rights-of-the-rest-routes
	 */
	public function isAdmin(): bool;

	/**
	 * Whether the signed-in user may manage a definition with this authorization.
	 *
	 * Without a `manage` rule only an administrator may. An entry is a group id
	 * or an array with a `group`.
	 *
	 * @param array|null $authorization The authorization block of the definition.
	 *
	 * @return bool True for an administrator or a member of a named group.
	 *
	 * @spec openspec/specs/mcp-discovery/spec.md#requirement-a-definition-tool-holds-the-caller-to-the-rights-of-the-rest-routes
	 */
	public function mayManage(?array $authorization): bool;
}//end interface
