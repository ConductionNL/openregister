<?php

/**
 * FleetAppCatalogue — the fleet rename table: candidate ids and read namespaces.
 *
 * The data half of FleetAppId. It answers from the table alone and never asks
 * the instance what is installed; FleetAppProbe does that.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Support
 * @package  OCA\OpenRegister\Support\FleetApp
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Support\FleetApp;

/**
 * The candidate ids and namespaces of every renamed fleet app.
 */
final class FleetAppCatalogue {

	/**
	 * Candidate ids per canonical app, NEWEST FIRST.
	 *
	 * Order is the contract: the first entry that is installed wins, so a
	 * fully migrated instance resolves to the new id and a beta/main instance
	 * falls back to the old one. Adding a new rename means prepending, never
	 * replacing — dropping the old id here is what silently breaks the
	 * integrations this class exists to protect.
	 *
	 * @var array<string, list<string>>
	 */
	private const CANDIDATES = [
		'integriq' => ['integriq', 'openconnector'],
		'filinq' => ['filinq', 'docudesk'],
		'thematiq' => ['thematiq', 'nldesign'],
		'stackiq' => ['stackiq', 'softwarecatalog'],
		'larpinq' => ['larpinq', 'larpingapp'],
		'dossiq' => ['dossiq', 'procest'],
		'learniq' => ['learniq', 'scholiq'],
		'decidiq' => ['decidiq', 'decidesk'],
		'buildiq' => ['buildiq', 'openbuild'],
		'keepiq' => ['keepiq', 'doriath'],
	];

	/**
	 * PHP namespace per candidate id, for the apps OpenRegister names by FQCN.
	 *
	 * A cross-app FQCN is the second half of the rename, and it is the half
	 * that cannot be guessed: `openbuild` shipped `OCA\OpenBuilt` and
	 * `larpingapp` shipped `OCA\DsoNextcloud` before `OCA\LarpingApp`, so
	 * `ucfirst($appId)` is not the rule and never was. Each entry below was
	 * READ from that app's own `appinfo/info.xml` (new) or from a literal this
	 * repo already resolved successfully against a running instance (old).
	 *
	 * Deliberately narrower than {@see self::CANDIDATES}: a canonical name is
	 * listed here only when BOTH its namespaces have been read. An app absent
	 * from this map resolves to null rather than to a guess, because a wrong
	 * FQCN fails exactly as silently as a stale one and looks fixed.
	 *
	 * @var array<string, array<string, string>>
	 */
	private const NAMESPACES = [
		'integriq' => [
			'integriq' => 'Integriq',
			'openconnector' => 'OpenConnector',
		],
		'keepiq' => [
			'keepiq' => 'Keepiq',
			'doriath' => 'Doriath',
		],
	];

	/**
	 * Every id a canonical app may be installed under, newest first.
	 *
	 * @param string $canonical Canonical (new) app name.
	 *
	 * @return list<string> The candidate ids; the canonical name alone when it was never renamed.
	 */
	public static function candidates(string $canonical): array {
		return (self::CANDIDATES[$canonical] ?? [$canonical]);
	}//end candidates()

	/**
	 * Whether the namespaces of a canonical app were read.
	 *
	 * @param string $canonical Canonical (new) app name.
	 *
	 * @return bool True when the app has a namespace table.
	 */
	public static function hasNamespaces(string $canonical): bool {
		return isset(self::NAMESPACES[$canonical]);
	}//end hasNamespaces()

	/**
	 * The ids that have a read namespace, newest first.
	 *
	 * @param string $canonical Canonical (new) app name.
	 *
	 * @return list<string> The ids; empty when no namespace was read.
	 */
	public static function namespacedIds(string $canonical): array {
		return array_keys((self::NAMESPACES[$canonical] ?? []));
	}//end namespacedIds()

	/**
	 * The read namespace of one candidate id, with a trailing backslash.
	 *
	 * @param string $canonical Canonical (new) app name.
	 * @param string $id One candidate id of that app.
	 *
	 * @return string|null The namespace, or null when it was not read.
	 */
	public static function namespaceForId(string $canonical, string $id): ?string {
		$namespace = (self::NAMESPACES[$canonical][$id] ?? null);
		if ($namespace === null) {
			return null;
		}

		return 'OCA\\' . $namespace . '\\';
	}//end namespaceForId()

}//end class
