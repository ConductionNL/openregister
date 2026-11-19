<?php

/**
 * MigrationPackDefinitionsInterface: a stored migration pack's definition, as the register import asks it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts\Core
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Core;

use OCP\AppFramework\Db\DoesNotExistException;

/**
 * The definition of a stored migration pack, looked up by its slug.
 *
 * The register import (Core) asks; the migration packs (Configuration)
 * answer and bind their adapter to this contract in their own bindings.
 *
 * @spec openspec/specs/register-service-extensions/spec.md
 */
interface MigrationPackDefinitionsInterface {

	/**
	 * The definition of the pack with this slug.
	 *
	 * @param string $packSlug The pack's slug.
	 *
	 * @return array<string, mixed> The pack definition.
	 *
	 * @throws DoesNotExistException When no pack has this slug.
	 *
	 * @spec openspec/specs/register-service-extensions/spec.md
	 */
	public function definitionBySlug(string $packSlug): array;
}//end interface
