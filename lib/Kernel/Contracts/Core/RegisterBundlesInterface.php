<?php

/**
 * RegisterBundlesInterface: a register's configuration bundle in and out, as the register API asks it.
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

use OCA\OpenRegister\Db\Register;
use OCP\AppFramework\Http\JSONResponse;

/**
 * Reads an uploaded configuration bundle, checks it stays inside its register, imports it, and exports a register as one.
 *
 * The register API (Core) asks; configuration import and export
 * (Configuration) answers and binds its adapter to this contract in its own
 * bindings.
 *
 * @spec openspec/specs/data-import-export/spec.md
 */
interface RegisterBundlesInterface {

	/**
	 * The bundle a request uploaded, or the error response to return.
	 *
	 * @param array<string, mixed>   $params The request parameters.
	 * @param array<int, mixed>|null $files  The uploaded files.
	 *
	 * @return array<string, mixed>|JSONResponse The decoded bundle, or the error response.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function uploadedBundle(array $params, ?array $files): array|JSONResponse;

	/**
	 * Why this bundle may not be imported into this register by a manager.
	 *
	 * @param Register             $register The register the bundle was uploaded to.
	 * @param array<string, mixed> $bundle   The bundle.
	 *
	 * @return string|null The reason, or null when the bundle stays inside the register.
	 *
	 * @spec openspec/specs/data-import-export/spec.md#requirement-a-bundle-uploaded-by-a-register-manager-stays-inside-that-register
	 */
	public function refusalFor(Register $register, array $bundle): ?string;

	/**
	 * Import a bundle that belongs to no stored configuration.
	 *
	 * @param array<string, mixed> $bundle The decoded bundle.
	 * @param BundleImport         $import Who imports it, for which app and version, and whether to force.
	 *
	 * @return array<string, mixed> What the import created or updated, by kind.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function importBundle(array $bundle, BundleImport $import): array;

	/**
	 * A register as a configuration bundle.
	 *
	 * @param Register $register       The register to export.
	 * @param bool     $includeObjects Whether the bundle carries the register's objects.
	 *
	 * @return array<string, mixed> The bundle.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function exportRegister(Register $register, bool $includeObjects): array;
}//end interface
