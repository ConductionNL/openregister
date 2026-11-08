<?php

/**
 * LeafRegistryInterface: the leaf catalogue the capabilities and the leaf-script listener read.
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
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration;

/**
 * The methods of LeafRegistry that callers outside its component use.
 * Callers depend on this interface, not on the class.
 */
interface LeafRegistryInterface {

	/**
	 * Every collected leaf descriptor.
	 *
	 * @return array<int, LeafDescriptor> The descriptors.
	 *
	 * @spec openspec/changes/app-leaf-provider-registration/specs/leaf-provider-registration/spec.md
	 */
	public function getDescriptors(): array;

	/**
	 * Render the discovery rows for the OCS capabilities surface.
	 *
	 * Each row carries the leaf's id, label, requiredApp, surfaces, kinds,
	 * renderMode and current usability — everything a manifest app or admin UI
	 * needs to discover the leaf (including HOW a render-surface leaf renders)
	 * WITHOUT loading its JS bundle. Usability is derived from
	 * the installed/enabled state of the required app: a leaf whose required app
	 * is disabled is reported present but not currently usable.
	 *
	 * @return array<int, array<string,mixed>> The discovery rows.
	 *
	 * @spec openspec/changes/app-leaf-provider-registration/specs/leaf-discovery-parity/spec.md
	 */
	public function describeForCapabilities(): array;
}//end interface
