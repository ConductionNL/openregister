<?php

/**
 * BundleImport: who imports a configuration bundle, for which app and version, and whether to force it.
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
 * The terms of one bundle import through the register API.
 *
 * @spec openspec/specs/data-import-export/spec.md
 */
final class BundleImport {
	/**
	 * Hold the values.
	 *
	 * @param string|null $owner   The importing user's uid, or null.
	 * @param string|null $appId   The app the imported configuration belongs to, or null.
	 * @param string|null $version The bundle version, or null.
	 * @param bool        $force   Whether to import over a newer or equal version.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function __construct(
		public readonly ?string $owner,
		public readonly ?string $appId,
		public readonly ?string $version,
		public readonly bool $force,
	) {
	}//end __construct()
}//end class
