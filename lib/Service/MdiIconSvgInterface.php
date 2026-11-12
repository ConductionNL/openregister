<?php

/**
 * OpenRegister MDI icon SVG source
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
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

namespace OCA\OpenRegister\Service;

/**
 * Renders a curated MDI icon reference to an SVG document, for callers
 * outside the Service component (the icon endpoint).
 *
 * @spec openspec/specs/unified-search-provider/spec.md
 */
interface MdiIconSvgInterface {
	/**
	 * Render an MDI icon reference to a standalone 24×24 SVG document.
	 *
	 * @param string|null $icon The schema icon reference (e.g. "Dog", "mdi-dog").
	 *
	 * @return string|null The SVG markup, or null when the icon is empty or not
	 *                     in the curated set.
	 */
	public function svg(?string $icon): ?string;
}//end interface
