<?php

/**
 * LeafAvailability: what a contributed leaf needs before it is offered.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/app-leaf-provider-registration/specs/leaf-provider-registration/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration;

/**
 * The app and permission a leaf depends on. Both null means always available.
 *
 * @spec openspec/specs/integration-leaf-foundation/spec.md
 */
final class LeafAvailability {

	/**
	 * Constructor.
	 *
	 * @param string|null $requiredApp        NC app id that must be installed/enabled, or null when
	 *                                        always-available.
	 * @param string|null $requiresPermission Optional permission string gating visibility.
	 */
	public function __construct(
		public readonly ?string $requiredApp = null,
		public readonly ?string $requiresPermission = null,
	) {
	}//end __construct()
}//end class
