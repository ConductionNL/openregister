<?php

/**
 * OpenRegister draft listing
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
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

namespace OCA\OpenRegister\Service\Object;

/**
 * Which drafts a list shows: the DraftListLens mode (EXCLUDE, ONLY, ANY or NONE)
 * and the caller whose own drafts EXCLUDE still lets through.
 *
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-list-must-hide-drafts-unless-asked
 */
final class DraftListing {
	/**
	 * Constructor.
	 *
	 * @param string      $mode  One of DraftListLens::EXCLUDE, ONLY, ANY and NONE.
	 * @param string|null $owner The caller, already quoted or bound by the emitter; null for nobody.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-list-must-hide-drafts-unless-asked
	 */
	public function __construct(
		public readonly string $mode,
		public readonly ?string $owner=null,
	) {
	}//end __construct()
}//end class
