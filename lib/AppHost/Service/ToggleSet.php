<?php

/**
 * One app and the feature toggles it declares.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\AppHost\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\AppHost\Service;

/**
 * The app whose toggles are read or written, with the toggles it declares
 * (ADR-011 Rule 2).
 *
 * @spec openspec/changes/feature-toggle-surface/specs/apphost-settings-plane/spec.md
 */
final class ToggleSet {
	/**
	 * Constructor.
	 *
	 * @param string            $app          The app.
	 * @param array<int, mixed> $declarations The declared toggles.
	 */
	public function __construct(
		public readonly string $app,
		public readonly array $declarations = [],
	) {
	}//end __construct()
}//end class
