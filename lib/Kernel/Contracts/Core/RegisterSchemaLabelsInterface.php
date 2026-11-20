<?php
/**
 * RegisterSchemaLabelsInterface: the names and icon a register and schema show next to an object.
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

/**
 * How Core labels an object with its register and schema.
 *
 * Core search shows the register and schema titles and the schema's icon on each
 * result; Configuration renders them (titles with fallbacks, the icon through its
 * MDI renderer) and binds its answer in its own bindings.
 *
 * @spec openspec/changes/schema-scoped-smart-picker/design.md#d1
 */
interface RegisterSchemaLabelsInterface {

	/**
	 * A register's display title, or a translated fallback when it has none or cannot be loaded.
	 *
	 * @param int $registerId The register ID.
	 *
	 * @return string The title.
	 *
	 * @spec openspec/changes/schema-scoped-smart-picker/design.md#d1
	 */
	public function resolveRegisterName(int $registerId): string;

	/**
	 * A schema's display title, or a translated fallback when it has none or cannot be loaded.
	 *
	 * @param int $schemaId The schema ID.
	 *
	 * @return string The title.
	 *
	 * @spec openspec/changes/schema-scoped-smart-picker/design.md#d1
	 */
	public function resolveSchemaName(int $schemaId): string;

	/**
	 * The URL of a schema's configured icon, or null when it has none.
	 *
	 * @param int $schemaId The schema ID.
	 *
	 * @return string|null The icon URL.
	 *
	 * @spec openspec/changes/schema-scoped-smart-picker/design.md#d2
	 */
	public function resolveMdiIconUrl(int $schemaId): ?string;
}//end interface
