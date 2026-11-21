<?php

/**
 * OpenRegister CollectivePageDraft
 *
 * The page to create in a collective before linking it to an object.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
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
 * A page to create in NC Collectives: the collective it goes into and its title.
 *
 * @spec openspec/specs/generic-integrations/spec.md
 */
final class CollectivePageDraft {

	/**
	 * Constructor.
	 *
	 * @param int    $collectiveId The collective to create the page in.
	 * @param string $title        The page title (trimmed and checked by the service).
	 */
	public function __construct(
		public readonly int $collectiveId,
		public readonly string $title,
	) {
	}//end __construct()
}//end class
