<?php

/**
 * OpenRegister Register Mapper Helper
 *
 * This file contains pure, dependency-free helper routines extracted from the
 * RegisterMapper class in the OpenRegister application.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Db;

/**
 * RegisterMapperHelper provides pure helper routines for RegisterMapper
 *
 * Stateless, dependency-free helpers that operate purely on their arguments.
 * Extracted from RegisterMapper to keep that class below the class-length
 * threshold without changing behaviour.
 *
 * @category Mapper
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */
class RegisterMapperHelper {


	/**
	 * Increment the patch component of a semantic version string.
	 *
	 * BUG-DB-12: the previous naive `explode('.')` + `(int)` bump turned a
	 * pre-release like `1.0.0-beta` into `1.0.1`, silently dropping the
	 * `-beta` suffix. This parser preserves any pre-release/build suffix and
	 * pads missing segments so a bare `1` or `1.2` still bumps cleanly.
	 *
	 * @param string $version The current version string (e.g. `1.0.0-beta`).
	 *
	 * @return string The version with its patch component incremented.
	 */
	public function bumpPatchVersion(string $version): string {
		// Capture: major.minor.patch followed by an optional -prerelease/+build suffix.
		if (preg_match('/^(\d+)(?:\.(\d+))?(?:\.(\d+))?(.*)$/', trim($version), $matches) === 1) {
			$major = (int)$matches[1];
			// Groups 2-4 are always present: the trailing `(.*)` always matches,
			// so PHP fills the earlier optional groups with '' rather than
			// omitting them. (int)'' is 0, so the old `?? 0` was a no-op.
			$minor = (int)$matches[2];
			$patch = (int)$matches[3];
			$suffix = $matches[4];

			return $major . '.' . $minor . '.' . ($patch + 1) . $suffix;
		}

		// Fall back to a safe default when the version is unparsable.
		return '0.0.1';
	}//end bumpPatchVersion()


	/**
	 * Decode the persisted `schemas` column into a flat ID list.
	 *
	 * Accepts the column's raw value (typically a JSON array) and
	 * returns the contained schema IDs. Tolerates legacy shapes
	 * (comma-separated string) and unexpected types by returning [].
	 *
	 * @param mixed $raw The raw column value
	 *
	 * @return array<int,int|string>
	 */
	public function decodeSchemasField(mixed $raw): array {
		if (is_array($raw) === true) {
			return $raw;
		}

		if (is_string($raw) === true && $raw !== '') {
			$decoded = json_decode($raw, true);
			if (is_array($decoded) === true) {
				return $decoded;
			}

			// Legacy comma-separated fallback.
			return array_filter(array_map('trim', explode(',', $raw)));
		}

		return [];
	}//end decodeSchemasField()


}//end class
