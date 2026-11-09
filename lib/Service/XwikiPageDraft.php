<?php

/**
 * OpenRegister XwikiPageDraft
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
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/integration-xwiki/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

/**
 * A new xWiki page to create: the space it goes in and its title.
 *
 * @spec openspec/specs/integration-xwiki/spec.md
 */
final class XwikiPageDraft {

	/**
	 * Constructor.
	 *
	 * @param string $space Target xWiki space.
	 * @param string $title New page title.
	 */
	public function __construct(
		public readonly string $space,
		public readonly string $title,
	) {

	}//end __construct()
}//end class
