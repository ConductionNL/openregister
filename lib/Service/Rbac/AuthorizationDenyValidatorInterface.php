<?php

/**
 * The deny-against-grants check of an authorization block, as the save paths use it.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use OCA\OpenRegister\Exception\AuthorizationBlockException;

/**
 * Only the method the register and schema save paths in lib/Db use.
 */
interface AuthorizationDenyValidatorInterface {

	/**
	 * Refuse a block whose deny contradicts its own grants.
	 *
	 * @param array|null $authorization The block as written, roles NOT expanded.
	 * @param string     $subject       What the block belongs to, for the message.
	 *
	 * @return void
	 *
	 * @throws AuthorizationBlockException When the block contradicts itself.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function assertStorable(?array $authorization, string $subject): void;
}//end interface
