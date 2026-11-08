<?php

/**
 * AuthorizationDenyValidatorInterface: what callers outside the component use of AuthorizationDenyValidator.
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
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use OCA\OpenRegister\Exception\AuthorizationBlockException;

/**
 * Declares only what callers outside the component use of AuthorizationDenyValidator; the rest
 * of the class stays its own.
 */
interface AuthorizationDenyValidatorInterface {

	/**
	 * Refuse the block, or return.
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
