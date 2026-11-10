<?php

/**
 * OpenRegister ObjectSlugClaims
 *
 * The slugs a batch wants and the objects whose stored slug it reads.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Handler
 * @package  OCA\OpenRegister\Service\Object\SaveObject
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object\SaveObject;

/**
 * Slugs to find the holders of and objects to read the stored slug of, in one round trip.
 *
 * @spec openspec/specs/object-lifecycle/spec.md#requirement-bulk-save-must-apply-the-same-slug-rules-as-a-single-save
 */
final class ObjectSlugClaims {

	/**
	 * Hold the slugs and the objects.
	 *
	 * @param list<string> $slugs Slugs to look up.
	 * @param list<string> $uuids Objects whose stored slug is wanted.
	 */
	public function __construct(
		public readonly array $slugs,
		public readonly array $uuids,
	) {
	}//end __construct()
}//end class
