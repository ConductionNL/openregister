<?php

/**
 * An administrator's decision to take an app's authorization from its file.
 *
 * REQ-LCA-003 keeps a conflicting part local until a person decides it, and
 * records the decision with its actor. An unattended `occ upgrade` therefore
 * never replaces an authorization block the instance changed. An
 * administrator who runs `occ openregister:descriptors:list --import` IS that
 * person deciding: they asked for the app's real configuration, and for an
 * access policy that means the file's policy, whole.
 *
 * 🔑 A SCOPE, NOT A PARAMETER. The re-import runs through the app's OWN
 * SettingsService where one exists (it re-applies the app's post-import
 * access rules), and that service calls `importFromApp()` with a signature
 * OpenRegister does not own. A parameter would stop at the app's door. The
 * scope reaches the guard on whichever path the import takes, and it ends when
 * the operation does, including when it throws.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ShippedBaseline
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/reimport-real-register-configuration/specs/register-descriptor-admin/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ShippedBaseline;

/**
 * Holds, for the length of one operation, the decision to take shipped authorization.
 *
 * @spec openspec/changes/reimport-real-register-configuration/specs/register-descriptor-admin/spec.md
 */
final class ShippedAuthorizationDecision {

	/**
	 * Where the decision came from, while one is in effect; null otherwise.
	 *
	 * @var string|null
	 */
	private static ?string $via = null;

	/**
	 * The authorization blocks the decision replaced during the last run.
	 *
	 * @var array<int, array{schema: string, path: string}>
	 */
	private static array $replaced = [];

	/**
	 * Static holder only.
	 *
	 * @return void
	 */
	private function __construct() {
	}//end __construct()

	/**
	 * Run an operation under the decision to take shipped authorization.
	 *
	 * @param string   $via       What decided it, recorded on the audit trail.
	 * @param callable $operation The import to run.
	 *
	 * @return mixed What the operation returns.
	 *
	 * @spec openspec/changes/reimport-real-register-configuration/specs/register-descriptor-admin/spec.md
	 */
	public static function run(string $via, callable $operation): mixed {
		$previous       = self::$via;
		self::$via      = $via;
		self::$replaced = [];

		try {
			return $operation();
		} finally {
			self::$via = $previous;
		}
	}//end run()

	/**
	 * What decided it, when a decision is in effect.
	 *
	 * @return string|null The source of the decision, or null when none is in effect.
	 *
	 * @spec openspec/changes/reimport-real-register-configuration/specs/register-descriptor-admin/spec.md
	 */
	public static function via(): ?string {
		return self::$via;
	}//end via()

	/**
	 * Note that the decision replaced a block the instance had changed.
	 *
	 * @param string $slug The schema slug.
	 * @param string $root The authorization block root path.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/reimport-real-register-configuration/specs/register-descriptor-admin/spec.md
	 */
	public static function noteReplaced(string $slug, string $root): void {
		self::$replaced[] = ['schema' => $slug, 'path' => $root];
	}//end noteReplaced()

	/**
	 * The blocks the last run replaced, so the caller can report them.
	 *
	 * @return array<int, array{schema: string, path: string}> Schema slug and block root, per replaced block.
	 *
	 * @spec openspec/changes/reimport-real-register-configuration/specs/register-descriptor-admin/spec.md
	 */
	public static function replaced(): array {
		return self::$replaced;
	}//end replaced()
}//end class
