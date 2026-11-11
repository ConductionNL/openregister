<?php

/**
 * RegisterSlugAliasesInterface: the declared slug map, as a constant.
 *
 * Code outside the Support component reads the declared slug history of a
 * renamed fleet register through this interface constant instead of naming
 * the concrete RegisterSlugAliases class. The DO-NOT-DERIVE rules on
 * {@see RegisterSlugAliases} apply to this map unchanged.
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
interface RegisterSlugAliasesInterface {

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
	public const ALIASES = [
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
}//end interface
