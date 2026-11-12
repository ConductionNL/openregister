<?php

/**
 * LeafRendering: where and how the render surface of a contributed leaf renders.
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
 * The render-surface face of a leaf: its surfaces, reference type, render mode
 * and load strategy. A leaf without a render surface keeps the defaults.
 *
 * @spec openspec/specs/integration-leaf-foundation/spec.md
 */
final class LeafRendering {

	/**
	 * Constructor.
	 *
	 * @param array<int,string> $surfaces      Render surfaces the leaf targets (subset of
	 *                                         LeafDescriptor::VALID_SURFACES).
	 * @param string|null       $referenceType Optional marker so a schema reference property can target
	 *                                         this leaf's single-entity widget (ADR-019 / AD-18).
	 * @param string            $renderMode    `component` (an SFC interpreted by the host's Vue runtime)
	 *                                         or `mount` (a `mount`/`unmount` pair invoked against a bare
	 *                                         DOM element). One of LeafDescriptor::VALID_RENDER_MODES.
	 * @param string|null       $loadStrategy  How the bundle reaches the page: one of
	 *                                         LeafDescriptor::VALID_LOAD_STRATEGIES, or null when unstated.
	 *                                         Null is silence, not a claim, and is never refused.
	 */
	public function __construct(
		public readonly array $surfaces = [],
		public readonly ?string $referenceType = null,
		public readonly string $renderMode = LeafDescriptor::RENDER_MODE_COMPONENT,
		public readonly ?string $loadStrategy = null,
	) {
	}//end __construct()
}//end class
