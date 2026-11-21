<?php

/**
 * OpenRegister Schema Import — BundledImporters.
 *
 * Builds the Schema.org and GGM importers over the committed, versioned
 * snapshot resources under one resource root.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\SchemaImport
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

namespace OCA\OpenRegister\Service\SchemaImport;

/**
 * The importers over the bundled standards snapshots.
 *
 * @spec openspec/specs/schema-import/spec.md
 */
final class BundledImporters {
	/**
	 * The importers over the snapshots shipped in lib/Resources.
	 *
	 * @return array<int, SchemaDialectImporter> The Schema.org and GGM importers.
	 *
	 * @spec openspec/specs/schema-import/spec.md
	 */
	public static function bundled(): array {
		return self::at(root: dirname(__DIR__, 2) . '/Resources');
	}//end bundled()

	/**
	 * The importers over the snapshots under a resource root (tests point it at fixtures).
	 *
	 * @param string $root The resource root holding schemaorg/ and ggm/.
	 *
	 * @return array<int, SchemaDialectImporter> The Schema.org and GGM importers.
	 *
	 * @spec openspec/specs/schema-import/spec.md
	 */
	public static function at(string $root): array {
		$schemaOrgVersion = self::readVersion(versionFile: $root . '/schemaorg/version.json', fallback: 'schemaorg-unknown');
		$ggmVersion       = self::readVersion(versionFile: $root . '/ggm/version.json', fallback: 'ggm-unknown');

		return [
			new SchemaOrgImporter(new SchemaOrgSnapshot($root . '/schemaorg/schemaorg-current-https.jsonld', $schemaOrgVersion)),
			new GgmImporter(new GgmSnapshot($root . '/ggm/ggm-snapshot.json', $ggmVersion)),
		];
	}//end at()

	/**
	 * Read a snapshot version from its version.json, with a fallback.
	 *
	 * @param string $versionFile The version.json path.
	 * @param string $fallback    The fallback version when the file is unreadable.
	 *
	 * @return string The version identifier.
	 */
	private static function readVersion(string $versionFile, string $fallback): string {
		if (is_file($versionFile) === false) {
			return $fallback;
		}

		$decoded = json_decode((string)file_get_contents($versionFile), associative: true);
		if (is_array($decoded) === true && isset($decoded['version']) === true && is_string($decoded['version']) === true) {
			return $decoded['version'];
		}

		return $fallback;
	}//end readVersion()
}//end class
