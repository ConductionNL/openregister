<?php

/**
 * RegisterSchemaPairsInterface: the register/schema pairs that hold objects.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

/**
 * Lists every register/schema pair with a magic table, for code outside Core that
 * walks all pairs (the cross-register report) without typing against the Db locator.
 *
 * The Db MagicTableLocatorInterface extends this port, so its binding serves both.
 */
interface RegisterSchemaPairsInterface {
	/**
	 * Get all register/schema pairs that have magic tables in the database.
	 *
	 * @return array<array{registerId: int, schemaId: int}> Array of register/schema ID pairs
	 *
	 * @spec openspec/changes/rapportage-bi-export/specs/rapportage-bi-export/spec.md#requirement-the-system-must-provide-a-general-purpose-aggregation-api
	 */
	public function getAllRegisterSchemaPairs(): array;
}//end interface
