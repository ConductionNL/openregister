<?php

/**
 * RegisterSlugAliases: the declared slugs each fleet register has answered to.
 *
 * Nine fleet apps ship a repair step that renames their OpenRegister register's
 * slug. The step is per-instance and runs at repair time, so on any given day
 * some instances carry the new slug and some still carry the old one. Both are
 * live across the estate at once, and a consumer that hardcodes either is wrong
 * on half of it.
 *
 * This class is the DECLARED list of what each register has been called. It is
 * declared rather than derived, and that is the whole point of the file.
 *
 * DO NOT DERIVE THESE FROM THE APP RENAME MAP. The register slug and the app id
 * are different strings that happen to coincide most of the time. `stackiq`
 * renamed the register `voorzieningen`; its own former app id
 * `softwarecatalog` was never a register slug on any instance. A resolver keyed
 * on app-id history offers `softwarecatalog`, matches nothing, and reports the
 * register absent on precisely the instances where it is present.
 *
 * Nor is this list the same shape as {@see FleetAppId}, which answers a
 * different question with a different source of truth: `IAppManager` for app
 * ids, the `openregister_registers` table for slugs. An instance can run the
 * app under its new id while its register row still carries the old slug,
 * because two separate repair steps move them and either can run first.
 *
 * Each entry is NEWEST FIRST. Adding a rename means PREPENDING the new slug,
 * never replacing the old one: dropping the old slug here is what silently
 * breaks the consumers this class exists to protect.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Support
 * @package  OCA\OpenRegister\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Support;

/**
 * The declared slug history of every renamed fleet register.
 *
 * @spec openspec/specs/register-slug-resolution/spec.md
 */
final class RegisterSlugAliases {

	/**
	 * Canonical register slug => every slug that register has answered to,
	 * newest first.
	 *
	 * Transcribed from the `SLUG_MAP` (or `OLD_SLUG`/`NEW_SLUG` pair) of the
	 * nine shipped repair steps, which are the only authority for what a
	 * register was called before:
	 *
	 *   integriq   openconnector/lib/Repair/MigrateRegisterSlug.php
	 *   buildiq    openbuild/lib/Repair/MigrateRegisterSlug.php
	 *   decidiq    decidesk/lib/Repair/MigrateRegisterSlug.php
	 *   humaniq    hrmq/lib/Repair/MigrateRegisterSlug.php
	 *   larpinq    larpingapp/lib/Repair/MigrateRegisterSlug.php
	 *   planninq   planix/lib/Repair/MigrateRegisterSlug.php
	 *   stackiq    softwarecatalog/lib/Repair/MigrateRegisterSlug.php
	 *   dossiq     procest/lib/Repair/MigrateRegisterSlug.php
	 *   learniq    scholiq/lib/Repair/RenameRegisterSlug.php
	 *
	 * Note the last line. Eight steps are called `MigrateRegisterSlug`; learniq
	 * calls its one `RenameRegisterSlug`. A sweep that searched the single
	 * filename reported learniq as never migrated, which is why this list is
	 * transcribed from read files rather than from a `find` result.
	 *
	 * @var array<string, list<string>>
	 */
	private const ALIASES = [
		'integriq'       => ['integriq', 'openconnector'],
		'buildiq'        => ['buildiq', 'openbuild'],
		'decidiq'        => ['decidiq', 'decidesk'],
		'humaniq'        => ['humaniq', 'hrmq'],
		'larpinq'        => ['larpinq', 'larpingapp'],
		'planninq'       => ['planninq', 'planix'],
		'stackiq'        => ['stackiq', 'voorzieningen'],
		'dossiq'         => ['dossiq', 'procest'],
		'dossiq-default' => ['dossiq-default', 'procest-default'],
		'learniq'        => ['learniq', 'scholiq'],
	];

	/**
	 * The candidate slugs for a canonical register slug, newest first.
	 *
	 * A slug with no recorded rename returns itself, so a caller never has to
	 * ask whether the register it wants is one of the renamed ones.
	 *
	 * @param string $canonical The canonical (current) register slug.
	 *
	 * @return list<string> Candidate slugs, newest first, canonical always first.
	 *
	 * @spec openspec/specs/register-slug-resolution/spec.md
	 */
	public static function candidatesFor(string $canonical): array {
		$key = strtolower(trim($canonical));
		if ($key === '') {
			return [];
		}

		return (self::ALIASES[$key] ?? [$key]);
	}//end candidatesFor()

	/**
	 * Every slug that is superseded, mapped to the canonical slug replacing it.
	 *
	 * Used by the guard that refuses a superseded slug written as a literal.
	 *
	 * @return array<string, string> Superseded slug => canonical slug.
	 *
	 * @spec openspec/specs/register-slug-resolution/spec.md
	 */
	public static function supersededSlugs(): array {
		$superseded = [];
		foreach (self::ALIASES as $canonical => $candidates) {
			foreach ($candidates as $candidate) {
				if ($candidate === $canonical) {
					continue;
				}

				$superseded[$candidate] = $canonical;
			}
		}

		return $superseded;
	}//end supersededSlugs()
}//end class
