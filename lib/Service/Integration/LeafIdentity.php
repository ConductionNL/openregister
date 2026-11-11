<?php

/**
 * LeafIdentity: who a contributed leaf is and how the admin UI names it.
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
 * The identity and presentation of one leaf: its id, label, icon and group.
 *
 * @spec openspec/specs/integration-leaf-foundation/spec.md
 */
final class LeafIdentity {

	/**
	 * Constructor.
	 *
	 * @param string      $id    Stable kebab-case id, unique across the registry and
	 *                           equal to the JS registration id.
	 * @param string      $label Human-readable label (already translated by the app).
	 * @param string      $icon  Material Design Icons name (no `mdi-` prefix).
	 * @param string|null $group Optional group used to cluster leaves in admin UI.
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $label,
		public readonly string $icon,
		public readonly ?string $group = null,
	) {
	}//end __construct()
}//end class
