<?php

/**
 * RegisterDescriptorService — what every app declares, and whether it landed.
 *
 * Eighteen of the fleet's apps ship a register descriptor and import it through
 * `ConfigurationService::importFromApp()` from a Repair step. Repair steps run
 * on install and `occ upgrade`, and `occ upgrade` reports "No upgrade required"
 * the moment `installed_version` matches `info.xml` — so once an app settles,
 * its descriptor can never be imported again. There was no way to re-run one and
 * no way to see whether one had succeeded, and the steps are documented to never
 * throw: a failed import leaves an instance that looks healthy and is missing a
 * register.
 *
 * This service supplies the two things that were absent: an inventory that
 * includes the apps whose register never landed, and a forced re-import.
 *
 * 🔴 IT ENUMERATES DECLARING APPS, NOT RESOLVED REGISTERS. Reading the resolver
 * keys or the configuration rows would list only what already imported — the
 * interesting row, the app whose seed never ran, has neither. An inventory built
 * that way reproduces exactly the silence it exists to break.
 *
 * ADR-005 is complemented, not contradicted: seeding stays in Repair steps, and
 * Rule 2 already requires them to be idempotent, which is what makes a second
 * caller safe.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/register-descriptor-admin/specs/register-descriptor-admin/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Service\Configuration\DescriptorConfigIds;
use OCA\OpenRegister\Service\ShippedBaseline\ShippedAuthorizationDecision;
use OCP\App\IAppManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Inventories app-shipped register descriptors and re-imports them on demand.
 *
 * @spec openspec/changes/register-descriptor-admin/specs/register-descriptor-admin/spec.md
 */
class RegisterDescriptorService {
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
	 * The re-import ran one descriptor file, for an app without one base document.
	 *
	 * @var string
	 */
	public const PATH_DESCRIPTOR = 'descriptor';

	/**
	 * App-relative directory every fleet app keeps its descriptors in.
	 *
	 * @var string
	 */
	private const DESCRIPTOR_DIR = '/lib/Settings';

	/**
	 * Constructor.
	 *
	 * @param IAppManager          $appManager           Enumerates installed apps and resolves their paths.
	 * @param RegisterMapper       $registerMapper       Reads the registers that actually exist.
	 * @param ConfigurationService    $configurationService Performs the mock import.
	 * @param LoggerInterface         $logger               Records unreadable descriptors.
	 * @param CountedRegisterImport   $counted              Runs a re-import under the decision and counts what changed.
	 * @param AppRegisterImportRunner $importRunner         Runs the app's real, forced import.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly RegisterMapper $registerMapper,
		private readonly ConfigurationService $configurationService,
		private readonly LoggerInterface $logger,
		private readonly CountedRegisterImport $counted,
		private readonly AppRegisterImportRunner $importRunner,
	) {
	}//end __construct()

	/**
	 * Every register any installed app declares, with whether it landed.
	 *
	 * One row per declared register — an app shipping several descriptors, as
	 * OpenRegister itself does, contributes several rows.
	 *
	 * @return array<int, array<string, string|null>> Rows: appId, slug, title, state, installedVersion, shippedVersion, descriptor.
	 *
	 * @spec openspec/changes/register-descriptor-admin/specs/register-descriptor-admin/spec.md
	 */
	public function inventory(): array {
		$installed = $this->installedRegisterVersions();
		$rows      = [];

		foreach ($this->appManager->getInstalledApps() as $appId) {
			foreach ($this->descriptorsFor(appId: $appId) as $descriptor) {
				// 🔴 A MOCK IS NOT A MISSING REGISTER. `x-openregister.type: mock`
				// marks a descriptor that exists to be imported ON DEMAND — sample
				// data for a demo or a test — not one an instance is expected to
				// carry. Measured across OpenRegister's own 14 descriptors, the
				// correlation is exact: all five mocks ship without a Repair step
				// and every non-mock but one has one. Reporting them as ABSENT put
				// five permanent red rows in front of every administrator, and the
				// obvious response — write the missing importers — would have
				// seeded five mock registers onto every instance in the fleet.
				//
				// A panel whose loudest signal is noise is one people stop reading,
				// which is the same silence it was built to break.
				if (($descriptor['data']['x-openregister']['type'] ?? '') === 'mock') {
					continue;
				}

				// ATTRIBUTED TO THE APP THAT DECLARES IT, not to the directory it
				// was found in. A descriptor shipped inside OpenRegister's own
				// lib/Settings may declare a different `app`, and filing it under
				// `openregister` names the wrong owner. An inventory exists to tell
				// somebody whose problem a row is.
				$owner = (string)($descriptor['data']['x-openregister']['app'] ?? $appId);
				if ($owner === '') {
					$owner = $appId;
				}

				foreach ($descriptor['registers'] as $slug => $register) {
					$shipped = (string)($register['version'] ?? '0.0.1');
					$key     = strtolower($slug);

					// 🔴 A MISSING KEY IS THE ROW THAT MATTERS. `absent` is not an
					// error state to be filtered out later — it is the finding this
					// whole service exists to surface, so it is built here as a
					// first-class value rather than inferred from a null further up.
					$state   = self::STATE_ABSENT;
					$current = null;
					if (array_key_exists($key, $installed) === true) {
						$current = $installed[$key];
						$state   = self::STATE_CURRENT;
						if (version_compare($shipped, $current, '>') === true) {
							$state = self::STATE_BEHIND;
						}
					}

					$rows[] = [
						'appId'            => $owner,
						'slug'             => (string)$slug,
						'title'            => (string)($register['title'] ?? $slug),
						'state'            => $state,
						'installedVersion' => $current,
						'shippedVersion'   => $shipped,
						'descriptor'       => $descriptor['file'],
					];
				}
			}
		}

		usort($rows, static fn (array $a, array $b): int => [$a['appId'], $a['slug']] <=> [$b['appId'], $b['slug']]);

		return $rows;
	}//end inventory()

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
	public function reimport(string $appId, string $slug, string $via = self::VIA_OCC): array {
		$document = $this->importRunner->document(appId: $appId);
		$declared = [];
		if ($document['data'] !== null) {
			$declared = $this->declaredSlugs(document: $document['data']);
		}

		if (in_array(strtolower($slug), $declared, true) === true) {
			return $this->counted->run(
				slug: $slug,
				slugs: $declared,
				version: $document['version'],
				fragments: $document['fragments'],
				via: $via,
				import: fn (): array => $this->importRunner->run(appId: $appId)
			);
		}

		// AN APP WITHOUT ONE BASE DOCUMENT. OpenRegister itself ships twenty
		// descriptors and no `openregister_register.json`, and the example-set
		// loader re-imports its registers through this method. For such a slug
		// the real configuration is the one non-mock descriptor declaring it.
		$descriptor = $this->descriptorFor(appId: $appId, slug: $slug, mock: false);
		if ($descriptor === null) {
			return [
				'outcome' => 'failed',
				'reason'  => sprintf(
					'The register configuration of %s declares no register "%s" (it declares: %s). For sample data use --mock.',
					$appId,
					$slug,
					implode(', ', $declared)
				),
			];
		}

		$version = (string)($descriptor['data']['info']['version'] ?? '0.0.1');

		return $this->counted->run(
			slug: $slug,
			slugs: $this->declaredSlugs(document: $descriptor['data']),
			version: $version,
			fragments: 0,
			via: $via,
			import: fn (): array => $this->importDescriptorFile(appId: $appId, descriptor: $descriptor, version: $version)
		);
	}//end reimport()

	/**
	 * Import one non-mock descriptor file, forced, under the configuration id its install-time import uses.
	 *
	 * 🔴 THE CONFIGURATION ID IS THE ONE THE REPAIR STEP USES, not always the
	 * app's. OpenRegister imports eight of its own descriptors under an id of
	 * their own (see {@see DescriptorConfigIds}): importing one under the
	 * shared `openregister` id would set that configuration's version to the
	 * descriptor's, lowering or raising the gate the force:false repair steps
	 * of flows, surveys, credential-broker, flow-timers and intake-sources
	 * compare against, and would append the register to a second configuration
	 * besides its own.
	 *
	 * @param string                                                                          $appId      The app.
	 * @param array{file: string, data: array<string, mixed>, registers: array<string, mixed>} $descriptor The descriptor.
	 * @param string                                                                          $version    Its version.
	 *
	 * @return array{success: bool, path: string, via: string, message: string, importJobId: string|null, importAppId: string}
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) DescriptorConfigIds::forFile() is a stateless lookup table shared with the
	 * repair steps; injecting it would add a dependency without removing any coupling.
	 */
	private function importDescriptorFile(string $appId, array $descriptor, string $version): array {
		$via      = sprintf('OpenRegister (lib/Settings/%s)', $descriptor['file']);
		$configId = DescriptorConfigIds::forFile(appId: $appId, file: $descriptor['file']);
		try {
			$imported = $this->configurationService->importFromApp(
				appId: $configId,
				data: $descriptor['data'],
				version: $version,
				force: true
			);
		} catch (Throwable $e) {
			$this->logger->warning('[RegisterDescriptorService] re-import failed: ' . $e->getMessage());
			return [
				'success'     => false,
				'path'        => self::PATH_DESCRIPTOR,
				'via'         => $via,
				'message'     => $e->getMessage(),
				'importJobId' => null,
				'importAppId' => $configId,
			];
		}

		$jobId = ($imported['importJobId'] ?? null);
		if (is_string($jobId) === false || $jobId === '') {
			$jobId = null;
		}

		return [
			'success'     => true,
			'path'        => self::PATH_DESCRIPTOR,
			'via'         => $via,
			'message'     => '',
			'importJobId' => $jobId,
			'importAppId' => $configId,
		];
	}//end importDescriptorFile()

	/**
	 * The register slugs a configuration document declares, lowercased.
	 *
	 * @param array<string, mixed> $document The register document.
	 *
	 * @return array<int, string> The slugs.
	 */
	private function declaredSlugs(array $document): array {
		$slugs = [];
		foreach ((array)($document['components']['registers'] ?? []) as $key => $register) {
			$slug = (string)$key;
			if (is_array($register) === true && isset($register['slug']) === true) {
				$slug = (string)$register['slug'];
			}

			$slugs[strtolower($slug)] = true;
		}

		return array_keys($slugs);
	}//end declaredSlugs()

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
	 * @spec openspec/changes/register-descriptor-admin/specs/register-descriptor-admin/spec.md
	 */
	public function reimportMock(string $appId, string $slug): array {
		$descriptor = $this->descriptorFor(appId: $appId, slug: $slug, mock: true);
		if ($descriptor === null) {
			return [
				'outcome' => 'failed',
				'reason'  => sprintf('%s ships no mock descriptor declaring register "%s".', $appId, $slug),
			];
		}

		$declaredObjects = count(($descriptor['data']['components']['objects'] ?? []));

		try {
			// 🔴 A MOCK IMPORTS UNDER ITS OWN CONFIGURATION IDENTITY.
			//
			// `importFromApp` stamps `imported_config_<appId>_version` and a
			// content hash. A mock descriptor declares the SAME register slug as
			// the app's real one — larpinq ships `larpinq_register.json` and
			// `larpinq_mock_register.json`, both declaring `larpinq` — so
			// importing the mock under the plain app id writes over the real
			// descriptor's stamp and reads its hash. Measured on a live instance:
			// the command reported `register "larpinq" imported.` and the
			// register ended up with the REAL descriptor's 11 schemas and ZERO of
			// the mock's 30 objects.
			//
			// This is the same rule ImportMergeOperationRegister already applies
			// with its own `openregister.merge-operation` id, and for the same
			// reason: a version gate shared between two descriptors masks one of
			// them.
			$configId = $appId . '.mock';

			$result = $this->configurationService->importFromApp(
				appId: $configId,
				data: $descriptor['data'],
				version: (string)($descriptor['data']['info']['version'] ?? '0.0.1'),
				force: true
			);
		} catch (Throwable $e) {
			// 🔴 REPORTED, NOT SWALLOWED. The Repair steps this complements are
			// documented to never throw — defensible at boot, where the
			// alternative is an app that will not install. It is not defensible
			// for an action somebody just took: an operation that reports nothing
			// is indistinguishable from one that did nothing.
			$this->logger->warning('[RegisterDescriptorService] re-import failed: ' . $e->getMessage());
			return ['outcome' => 'failed', 'reason' => $e->getMessage()];
		}

		// The job the import ran under, so a caller that undoes the import (an example set's remove) claims exactly it.
		$job = ['importJobId' => ($result['importJobId'] ?? null), 'importAppId' => $configId];

		$after = $this->installedRegisterVersions()[strtolower($slug)] ?? null;
		if ($after === null) {
			return [
				'outcome' => 'failed',
				'reason'  => sprintf('The import reported no error but register "%s" still does not exist.', $slug),
			] + $job;
		}

		// 🔴 COUNT WHAT LANDED, and fail when a descriptor that declares objects
		// seeded none of them.
		//
		// The old post-check asked only whether a config version existed for the
		// slug. For a mock that is already true — the app's own descriptor
		// stamped it at install — so `outcome: imported` was returned over an
		// import that created nothing. "Imported" that cannot be told apart from
		// "did nothing" is the failure this whole command exists to surface.
		$imported = count(($result['objects'] ?? []));
		$skipped = (int)(($result['skipped']['objects'] ?? 0) + ($result['skipped']['seedObjects'] ?? 0));

		if ($declaredObjects > 0 && $imported === 0) {
			return [
				'outcome' => 'failed',
				'reason'  => sprintf(
					'%s declares %d demo object(s) but none were imported (%d skipped). The register and its schemas may have landed; the data did not.',
					$descriptor['file'],
					$declaredObjects,
					$skipped
				),
				'counts'  => ['declared' => $declaredObjects, 'imported' => 0, 'skipped' => $skipped],
			] + $job;
		}

		return [
			'outcome' => 'imported',
			'reason'  => null,
			'counts'  => ['declared' => $declaredObjects, 'imported' => $imported, 'skipped' => $skipped],
		] + $job;
	}//end reimportMock()

	/**
	 * Whether the instance holds an older version of a register than the
	 * descriptor of the given kind (mock or real) ships.
	 *
	 * A register that is not on the instance, or that no descriptor of the
	 * kind declares, is not behind: there is nothing to bring up to date.
	 *
	 * @param string $appId The app whose descriptors are read.
	 * @param string $slug  The register slug.
	 * @param bool   $mock  True for the mock descriptor, false for the real one.
	 *
	 * @return bool True when the shipped version is newer than the installed one.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-set-imports-the-openregister-registers-it-requires-req-exs-009
	 */
	public function isBehind(string $appId, string $slug, bool $mock): bool {
		$descriptor = $this->descriptorFor(appId: $appId, slug: $slug, mock: $mock);
		$installed  = ($this->installedRegisterVersions()[strtolower($slug)] ?? null);
		if ($descriptor === null || $installed === null) {
			return false;
		}

		foreach ($descriptor['registers'] as $declared => $register) {
			if (strtolower((string)$declared) === strtolower($slug)) {
				return version_compare((string)($register['version'] ?? '0.0.1'), $installed, '>');
			}
		}

		return false;
	}//end isBehind()

	/**
	 * Slug (lowercased) to version for every register that exists.
	 *
	 * RBAC and multitenancy are off: this is an administrative inventory, and a
	 * register filtered out by scope would be reported as ABSENT — the one
	 * misreading that would send an admin to re-import something already there.
	 *
	 * @return array<string, string> Lowercased slug to version.
	 */
	private function installedRegisterVersions(): array {
		$versions = [];
		foreach ($this->registerMapper->findAll(_rbac: false, _multitenancy: false) as $register) {
			$slug = strtolower((string)$register->getSlug());
			if ($slug === '') {
				continue;
			}

			$versions[$slug] = (string)($register->getVersion() ?? '0.0.0');
		}

		return $versions;
	}//end installedRegisterVersions()

	/**
	 * Every register descriptor one app ships.
	 *
	 * A descriptor is recognised by SHAPE — an OpenAPI document carrying
	 * `components.registers` — not by filename. The filenames vary across the
	 * fleet (`flow_register.json`, `credential-providers.json`,
	 * `credential-providers.json`), and a `*_register.json` glob would
	 * quietly omit the ones that do not match, shrinking the inventory instead of
	 * failing. That is the same invisibility this service exists to fix, one
	 * level up.
	 *
	 * @param string $appId The app to scan.
	 *
	 * @return array<int, array{file: string, data: array<string, mixed>, registers: array<string, mixed>}> Descriptors found.
	 */
	private function descriptorsFor(string $appId): array {
		try {
			$dir = $this->appManager->getAppPath($appId) . self::DESCRIPTOR_DIR;
		} catch (Throwable $e) {
			return [];
		}

		if (is_dir($dir) === false) {
			return [];
		}

		$files = glob($dir . '/*.json');
		if ($files === false) {
			return [];
		}

		$found = [];
		foreach ($files as $file) {
			$data = json_decode((string)file_get_contents($file), true);
			if (is_array($data) === false) {
				$this->logger->debug('[RegisterDescriptorService] not JSON, skipped: ' . $file);
				continue;
			}

			$registers = ($data['components']['registers'] ?? null);
			if (is_array($registers) === false || $registers === []) {
				continue;
			}

			$found[] = ['file' => basename($file), 'data' => $data, 'registers' => $registers];
		}

		return $found;
	}//end descriptorsFor()

	/**
	 * The one descriptor an app ships that declares a given register slug.
	 *
	 * 🔴 THE KIND IS CHOSEN, NOT THE GLOB ORDER. A mock declares the real
	 * register's slug, and `<app>_mock_register.json` sorts before
	 * `<app>_register.json`, so "the first match" was the mock.
	 *
	 * @param string $appId The app that ships it.
	 * @param string $slug  The register slug to find.
	 * @param bool   $mock  True for a `type: mock` descriptor, false for any other.
	 *
	 * @return array{file: string, data: array<string, mixed>, registers: array<string, mixed>}|null The descriptor, or null.
	 */
	private function descriptorFor(string $appId, string $slug, bool $mock): ?array {
		foreach ($this->descriptorsFor(appId: $appId) as $descriptor) {
			$isMock = (($descriptor['data']['x-openregister']['type'] ?? '') === 'mock');
			if ($isMock !== $mock) {
				continue;
			}

			foreach (array_keys($descriptor['registers']) as $declared) {
				if (strtolower((string)$declared) === strtolower($slug)) {
					return $descriptor;
				}
			}
		}

		return null;
	}//end descriptorFor()
}//end class
