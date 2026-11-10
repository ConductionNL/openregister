<?php

/**
 * RegisterDescriptorServiceInterface: what callers outside the Service component use of RegisterDescriptorService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
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

namespace OCA\OpenRegister\Service;

/**
 * The descriptor inventory and re-import the descriptor endpoint and occ command call on RegisterDescriptorService.
 */
interface RegisterDescriptorServiceInterface {
	/**
	 * The register exists at the version the app ships.
	 *
	 * @var string
	 */
	public const STATE_CURRENT = 'current';

	/**
	 * The register exists but predates the descriptor the app now ships.
	 *
	 * @var string
	 */
	public const STATE_BEHIND = 'behind';

	/**
	 * The app declares a register that does not exist on this instance.
	 *
	 * @var string
	 */
	public const STATE_ABSENT = 'absent';

	/**
	 * Who asked for a re-import from the command line.
	 *
	 * @var string
	 */
	public const VIA_OCC = 'occ openregister:descriptors:list --import';

	/**
	 * Every register any installed app declares, with whether it landed.
	 *
	 * One row per declared register — an app shipping several descriptors, as
	 * OpenRegister itself does, contributes several rows.
	 *
	 * @return array<int, array<string, string|null>> Rows: appId, slug, title, state, installedVersion, shippedVersion, descriptor.
	 *
	 * @spec openspec/specs/register-descriptor-admin/spec.md
	 */
	public function inventory(): array;

	/**
	 * Re-import an app's REAL register configuration, forced.
	 *
	 * The same configuration an install or upgrade imports: the app's
	 * `<app>_register.json` with its `register.d` fragments, through the app's
	 * own SettingsService where it has a forced entry point (see
	 * {@see AppRegisterImportRunner}). An app with no single base document,
	 * as OpenRegister itself, re-imports the one non-mock descriptor that
	 * declares the slug. Never a mock. Forced, because the version gate is
	 * exactly what keeps a register change without an app-version bump from
	 * ever arriving.
	 *
	 * 🔴 AN ADMINISTRATOR ASKING FOR THE REAL CONFIGURATION DECIDES THE ACCESS
	 * POLICY. The import runs under {@see ShippedAuthorizationDecision}, so
	 * each schema's authorization block is the file's, whole, also where the
	 * instance had changed it. Each block replaced that way is recorded on the
	 * audit trail and counted here, so it is never silent.
	 *
	 * 🔴 THE COUNTS COME FROM THE DATABASE, BEFORE AND AFTER. An import's own
	 * return value differs per app and says what it processed, not what
	 * changed; the counts compare what the instance holds.
	 *
	 * @param string $appId The app that ships the configuration.
	 * @param string $slug  A register slug the configuration declares.
	 * @param string $via   Who asked, recorded with every authorization block the decision replaces.
	 *
	 * @return array<string, mixed> outcome, reason, path, via, version, counts{registers, schemas, authorizationReplaced}, replaced,
	 *                              and, when the import recorded a job, importJobId and importAppId (the configuration id it ran under).
	 *
	 * @spec openspec/changes/reimport-real-register-configuration/specs/register-descriptor-admin/spec.md
	 */
	public function reimport(string $appId, string $slug, string $via = self::VIA_OCC): array;

	/**
	 * Import the SAMPLE (mock) descriptor an app ships for one register slug.
	 *
	 * Only a descriptor marked `x-openregister.type: mock` is eligible. Before
	 * this was split out, the one re-import picked the first descriptor that
	 * declared the slug in glob order, and `portaliq_mock_register.json` sorts
	 * before `portaliq_register.json`: the "re-import" of portaliq's register
	 * seeded demo objects and set the register to the mock's version 0.27.0.
	 *
	 * 🔴 A MOCK DECLARES THE REAL REGISTER'S SLUG, so importing it writes that
	 * register's version and description from the mock. That is what the
	 * operator asks for with `--mock`, and the help text says so.
	 *
	 * 🔴 ALWAYS FORCED, and the force is the whole point. `ImportHandler`
	 * short-circuits on `$force === false && version_compare($shipped,
	 * $existing, '<=')`, which is precisely the condition an administrator
	 * presses this in: a register that is absent, or that failed to write, while
	 * the version counter says it is current. An unforced re-import would report
	 * success and do nothing in every case that motivates the action.
	 *
	 * @param string $appId The app that ships the descriptor.
	 * @param string $slug  The register slug within it.
	 *
	 * `counts` reports what actually landed, so `imported` cannot be said over a
	 * no-op.
	 *
	 * @return array<string, mixed> `outcome` and `reason`; `counts` ({declared, imported, skipped}) once the import ran,
	 *                              with `importJobId` and `importAppId`: the job it recorded and the configuration
	 *                              id it ran under.
	 *
	 * @spec openspec/specs/register-descriptor-admin/spec.md
	 */
	public function reimportMock(string $appId, string $slug): array;
}//end interface
