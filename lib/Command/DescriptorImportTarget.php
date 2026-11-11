<?php

/**
 * DescriptorImportTarget — the app and register a descriptor re-import names.
 *
 * @category Command
 * @package  OCA\OpenRegister\Command
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
 * @spec openspec/specs/register-descriptor-admin/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Command;

/**
 * The `--app` and `--import` pair of one descriptor re-import.
 *
 * @spec openspec/specs/register-descriptor-admin/spec.md
 */
final class DescriptorImportTarget {

	/**
	 * Constructor.
	 *
	 * @param string $appId The app whose configuration is re-imported.
	 * @param string $slug  The register slug named by --import.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/register-descriptor-admin/spec.md
	 */
	public function __construct(
		public readonly string $appId,
		public readonly string $slug,
	) {
	}//end __construct()
}//end class
