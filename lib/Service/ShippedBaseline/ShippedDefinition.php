<?php

/**
 * What an app shipped for one subject, and which release shipped it.
 *
 * A definition without the version it came from cannot answer "which release
 * did this instance diverge from", so the three travel together.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ShippedBaseline
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ShippedBaseline;

/**
 * A shipped definition with the app and app version it came from.
 *
 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
 */
final class ShippedDefinition {

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $definition What the app ships.
	 * @param string               $app        The app.
	 * @param string               $appVersion The app version.
	 */
	public function __construct(
		public readonly array $definition,
		public readonly string $app,
		public readonly string $appVersion,
	) {
	}//end __construct()
}//end class
