<?php

/**
 * OpenRegister AppHost — one lookup of an app's bundled manifest.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\AppHost\Observability
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\AppHost\Observability;

/**
 * Which app's bundled manifest to read, where it is installed, and the log tag of the loader asking.
 *
 * @spec openspec/specs/apphost-observability/spec.md
 */
final class ManifestLookup {
	/**
	 * Constructor.
	 *
	 * @param string $appId   The app id, for the warning.
	 * @param string $appPath The app's install path.
	 * @param string $tag     The log tag of the calling loader.
	 */
	public function __construct(
		public readonly string $appId,
		public readonly string $appPath,
		public readonly string $tag,
	) {
	}//end __construct()
}//end class
