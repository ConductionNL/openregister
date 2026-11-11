<?php

/**
 * One write of the feature toggles: the merged map before and after it.
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
 * The merged toggle map before and after one override write, as the
 * settings trail records it (ADR-011 Rule 2).
 *
 * @spec openspec/changes/feature-toggle-surface/specs/apphost-settings-plane/spec.md
 */
final class ToggleChange {
	/**
	 * Constructor.
	 *
	 * @param array<string, bool> $before The merged map before the write.
	 * @param array<string, bool> $after  The merged map after the write.
	 */
	public function __construct(
		public readonly array $before,
		public readonly array $after,
	) {
	}//end __construct()
}//end class
