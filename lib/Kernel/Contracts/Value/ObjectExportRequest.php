<?php

/**
 * ObjectExportRequest: what one export or import template covers, and for whom.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts\Value
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Value;

use OCA\OpenRegister\Kernel\Contracts\RegisterInterface;
use OCA\OpenRegister\Kernel\Contracts\SchemaInterface;
use OCP\IUser;

/**
 * The register, schema, filters and user of one export through ObjectExporterInterface.
 *
 * Without a register and schema an export covers every object the user may
 * read; with a register and no schema it covers each schema of that register.
 * An import template needs a schema; it ignores the filters.
 *
 * @spec exclude ADR-011 parameter object of the exporter contract, not a capability of its own
 */
final class ObjectExportRequest {

	/**
	 * Constructor
	 *
	 * @param RegisterInterface|null $register    The register to export, or null for all.
	 * @param SchemaInterface|null   $schema      The schema to export, or null for all schemas of the register.
	 * @param array                  $filters     The object filters to apply.
	 * @param IUser|null             $currentUser The user the export runs for (permissions, admin columns).
	 */
	public function __construct(
		public readonly ?RegisterInterface $register = null,
		public readonly ?SchemaInterface $schema = null,
		public readonly array $filters = [],
		public readonly ?IUser $currentUser = null,
	) {
	}//end __construct()
}//end class
