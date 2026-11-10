<?php

/**
 * The profile object a manifest's runtime user is built from.
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

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * The profile object a manifest's `runtime.user` block is built from, with the user and schema it was found for.
 *
 * @spec openspec/specs/openregister-app-manifest/spec.md#req-or-man-013
 */
final class ManifestProfile {
	/**
	 * Hold the values.
	 *
	 * @param string       $userId     Nextcloud user ID (always included as `id`).
	 * @param string       $schemaSlug Schema slug (used for schema re-lookup to read calcs).
	 * @param ObjectEntity $profile    The user's profile object entity.
	 *
	 * @spec openspec/specs/openregister-app-manifest/spec.md#req-or-man-013
	 */
	public function __construct(
		public readonly string $userId,
		public readonly string $schemaSlug,
		public readonly ObjectEntity $profile,
	) {
	}//end __construct()
}//end class
