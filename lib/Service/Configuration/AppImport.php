<?php

/**
 * One app's configuration import.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Configuration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Configuration;

/**
 * The app, its configuration data, the version it ships and whether the import is forced.
 *
 * @spec openspec/specs/data-import-export/spec.md
 */
final class AppImport {

	/**
	 * Constructor.
	 *
	 * @param string               $appId   The importing app.
	 * @param array<string, mixed> $data    The configuration data, as arrays.
	 * @param string               $version The configuration version.
	 * @param bool                 $force   Whether to import regardless of version.
	 */
	public function __construct(
		public readonly string $appId,
		public readonly array $data,
		public readonly string $version,
		public readonly bool $force,
	) {
	}//end __construct()

	/**
	 * The `x-openregister` extension block, as declared.
	 *
	 * @return mixed
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function extension(): mixed {
		return ($this->data['x-openregister'] ?? []);
	}//end extension()

	/**
	 * The OAS `info` block, as declared.
	 *
	 * @return mixed
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function info(): mixed {
		return ($this->data['info'] ?? []);
	}//end info()
}//end class
