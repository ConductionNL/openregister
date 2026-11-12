<?php

/**
 * ManifestServiceInterface: what callers outside the Service component ask of ManifestService.
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
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

/**
 * Enriches an app manifest with the current user's schema data.
 */
interface ManifestServiceInterface {

	/**
	 * Return the manifest enriched with a `runtime.user` block.
	 *
	 * @param array<string, mixed> $manifest Parsed manifest.json from the calling app.
	 *
	 * @return array<string, mixed> Enriched manifest (original if no `currentUserSchema`).
	 *
	 * @spec openspec/specs/openregister-app-manifest/spec.md#req-or-man-013
	 */
	public function getEnrichedManifest(array $manifest): array;
}//end interface
