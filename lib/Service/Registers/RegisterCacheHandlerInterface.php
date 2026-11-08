<?php

/**
 * RegisterCacheHandlerInterface: the part of RegisterCacheHandler that the registers controller uses.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Registers
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

namespace OCA\OpenRegister\Service\Registers;

/**
 * The methods of RegisterCacheHandler that callers outside its component use.
 * Callers depend on this interface, not on the class.
 */
interface RegisterCacheHandlerInterface {

	/**
	 * Invalidate cache for a specific register
	 *
	 * Drops every cached lookup keyed on the given register ID so that any
	 * follow-up read in the same PHP worker re-fetches the register from the
	 * database. The next read MUST observe the new state.
	 *
	 * Called by the runtime-schema-api CRUD path
	 * (RegistersController::create/update/patch/destroy) after a successful
	 * mapper round-trip and before the controller returns.
	 *
	 * @param int $registerId The register ID to invalidate.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function invalidate(int $registerId): void;
}//end interface
