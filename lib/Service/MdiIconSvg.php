<?php

/**
 * MdiIconSvg: the entry the icon endpoint calls to render a curated MDI icon.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

/**
 * Renders a curated MDI icon reference to an SVG document for the icon endpoint.
 *
 * The curated set and the rendering stay in MdiIconRenderer; this is the
 * entry callers outside the Service component use, so they do not name it.
 *
 * @spec openspec/specs/unified-search-provider/spec.md
 */
final class MdiIconSvg implements MdiIconSvgInterface {

	/**
	 * Render an MDI icon reference to a standalone 24×24 SVG document.
	 *
	 * @param string|null $icon The schema icon reference (e.g. "Dog", "mdi-dog").
	 *
	 * @return string|null The SVG markup, or null when the icon is empty or not
	 *                     in the curated set.
	 *
	 * @spec openspec/specs/unified-search-provider/spec.md
	 */
	public static function of(?string $icon): ?string {
		return MdiIconRenderer::svg(icon: $icon);
	}//end of()

	/**
	 * Render an MDI icon reference to a standalone 24×24 SVG document.
	 *
	 * @param string|null $icon The schema icon reference (e.g. "Dog", "mdi-dog").
	 *
	 * @return string|null The SVG markup, or null when the icon is empty or not
	 *                     in the curated set.
	 */
	public function svg(?string $icon): ?string {
		return self::of(icon: $icon);
	}//end svg()
}//end class
