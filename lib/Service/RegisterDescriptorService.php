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
 * @spec openspec/specs/register-descriptor-admin/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Service\Configuration\DescriptorConfigIds;
use OCA\OpenRegister\Service\ShippedBaseline\ShippedAuthorizationDecision;
use OCP\App\IAppManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Inventories app-shipped register descriptors and re-imports them on demand.
 *
 * @spec openspec/specs/register-descriptor-admin/spec.md
 */
class RegisterDescriptorService implements RegisterDescriptorServiceInterface {
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
	 * Performs the mock import.
	 *
	 * @var AppConfigurationImporterInterface
	 */
	private readonly AppConfigurationImporterInterface $configurationService;

	/**
	 * Runs a re-import under the decision and counts what changed.
	 *
	 * @var CountedRegisterImport
	 */
	private readonly CountedRegisterImport $counted;

	/**
	 * Runs the app's real, forced import.
	 *
	 * @var AppRegisterImportRunner
	 */
	private readonly AppRegisterImportRunner $importRunner;

	/**
	 * Constructor.
	 *
	 * @param IAppManager          $appManager           Enumerates installed apps and resolves their paths.
	 * @param RegisterMapperInterface       $registerMapper       Reads the registers that actually exist.
	 * @param RegisterImporters $importers Performs the mock import, runs a counted re-import and the app's real, forced import.
	 * @param LoggerInterface         $logger               Records unreadable descriptors.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly RegisterMapperInterface $registerMapper,
		RegisterImporters $importers,
		private readonly LoggerInterface $logger,
	) {
		$this->configurationService = $importers->configurationService;
		$this->counted = $importers->counted;
		$this->importRunner = $importers->importRunner;
	}//end __construct()

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
	public function inventory(): array {
		$installed = $this->installedRegisterVersions();
		$rows      = [];

		foreach ($this->appManager->getInstalledApps() as $appId) {
			foreach ($this->descriptorsFor(appId: $appId) as $descriptor) {
				$rows = array_merge($rows, $this->descriptorRows(descriptor: $descriptor, installed: $installed));
			}
		}

		usort($rows, static fn (array $a, array $b): int => [$a['appId'], $a['slug']] <=> [$b['appId'], $b['slug']]);

		return $rows;
	}//end inventory()

	/**
	 * The inventory rows of one descriptor: one per register it declares.
	 *
	 * 🔴 A MOCK IS NOT A MISSING REGISTER. `x-openregister.type: mock`
	 * marks a descriptor that exists to be imported ON DEMAND — sample
	 * data for a demo or a test — not one an instance is expected to
	 * carry. Measured across OpenRegister's own 14 descriptors, the
	 * correlation is exact: all five mocks ship without a Repair step
	 * and every non-mock but one has one. Reporting them as ABSENT put
	 * five permanent red rows in front of every administrator, and the
	 * obvious response — write the missing importers — would have
	 * seeded five mock registers onto every instance in the fleet.
	 * A panel whose loudest signal is noise is one people stop reading,
	 * which is the same silence it was built to break.
	 *
	 * @param array<string, mixed>  $descriptor The descriptor (file, data, registers, and owner: the app the row is attributed to).
	 * @param array<string, string> $installed  Lowercased slug to installed version.
	 *
	 * @return array<int, array<string, string|null>> The rows; none for a mock.
	 */
	private function descriptorRows(array $descriptor, array $installed): array {
		if (($descriptor['data']['x-openregister']['type'] ?? '') === 'mock') {
			return [];
		}

		$rows = [];
		foreach ($descriptor['registers'] as $slug => $register) {
			$rows[] = ['appId' => $descriptor['owner']]
				+ $this->registerIdentity(slug: $slug, register: $register)
				+ $this->registerState(register: $register, current: ($installed[strtolower($slug)] ?? null))
				+ ['descriptor' => $descriptor['file']];
		}

		return $rows;
	}//end descriptorRows()

	/**
	 * The app a descriptor's rows are attributed to.
	 *
	 * ATTRIBUTED TO THE APP THAT DECLARES IT, not to the directory it
	 * was found in. A descriptor shipped inside OpenRegister's own
	 * lib/Settings may declare a different `app`, and filing it under
	 * `openregister` names the wrong owner. An inventory exists to tell
	 * somebody whose problem a row is.
	 *
	 * @param string                                                                          $appId      The app the descriptor was found in.
	 * @param array{file: string, data: array<string, mixed>, registers: array<string, mixed>} $descriptor The descriptor.
	 *
	 * @return string The owning app id.
	 */
	private function ownerOf(string $appId, array $descriptor): string {
		$owner = (string)($descriptor['data']['x-openregister']['app'] ?? $appId);
		if ($owner === '') {
			$owner = $appId;
		}

		return $owner;
	}//end ownerOf()

	/**
	 * The slug and title of one declared register.
	 *
	 * @param int|string $slug     The register slug (array key).
	 * @param mixed      $register The register declaration.
	 *
	 * @return array{slug: string, title: string}
	 */
	private function registerIdentity(int|string $slug, mixed $register): array {
		return [
			'slug'  => (string)$slug,
			'title' => (string)($register['title'] ?? $slug),
		];
	}//end registerIdentity()

	/**
	 * The rest of the middle of an inventory row: whether, and how current, a declared register landed.
	 * The caller puts the owning app and the register identity before it and the descriptor file after it.
	 *
	 * @param mixed       $register The register declaration.
	 * @param string|null $current  The installed version, or null when absent.
	 *
	 * @return array{state: string, installedVersion: string|null, shippedVersion: string}
	 */
	private function registerState(mixed $register, ?string $current): array {
		$shipped = (string)($register['version'] ?? '0.0.1');

		return [
			'state'            => $this->stateOf(shipped: $shipped, current: $current),
			'installedVersion' => $current,
			'shippedVersion'   => $shipped,
		];
	}//end registerState()

	/**
	 * The state of a declared register against what the instance holds.
	 *
	 * 🔴 A MISSING KEY IS THE ROW THAT MATTERS. `absent` is not an
	 * error state to be filtered out later — it is the finding this
	 * whole service exists to surface, so it is built here as a
	 * first-class value rather than inferred from a null further up.
	 *
	 * @param string      $shipped The version the descriptor ships.
	 * @param string|null $current The installed version, or null when the register is not on the instance.
	 *
	 * @return string One of the STATE_* constants.
	 */
	private function stateOf(string $shipped, ?string $current): string {
		if ($current === null) {
			return self::STATE_ABSENT;
		}

		if (version_compare($shipped, $current, '>') === true) {
			return self::STATE_BEHIND;
		}

		return self::STATE_CURRENT;
	}//end stateOf()

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
	 * @param ShippedRegister $register The app that ships the configuration and a register slug it declares.
	 * @param string          $via      Who asked, recorded with every authorization block the decision replaces.
	 *
	 * @return array<string, mixed> outcome, reason, path, via, version, counts{registers, schemas, authorizationReplaced}, replaced,
	 *                              and, when the import recorded a job, importJobId and importAppId (the configuration id it ran under).
	 *
	 * @spec openspec/changes/reimport-real-register-configuration/specs/register-descriptor-admin/spec.md
	 */
	public function reimportShipped(ShippedRegister $register, string $via = self::VIA_OCC): array {
		$document = $this->importRunner->document(appId: $register->appId);
		$declared = [];
		if ($document['data'] !== null) {
			$declared = $this->declaredSlugs(document: $document['data']);
		}

		if (in_array(strtolower($register->slug), $declared, true) === true) {
			// The app's base document with its fragments, through the app's own forced import.
			return $this->counted->run(
				slug: $register->slug,
				slugs: $declared,
				version: $document['version'],
				fragments: $document['fragments'],
				via: $via,
				import: fn (): array => $this->importRunner->run(appId: $register->appId)
			);
		}

		return $this->reimportDescriptor(run: new DescriptorReimportRun(register: $register, declared: $declared, via: $via));
	}//end reimportShipped()

	/**
	 * Re-import the one non-mock descriptor declaring a slug.
	 *
	 * AN APP WITHOUT ONE BASE DOCUMENT. OpenRegister itself ships twenty
	 * descriptors and no `openregister_register.json`, and the example-set
	 * loader re-imports its registers through this method. For such a slug
	 * the real configuration is the one non-mock descriptor declaring it.
	 *
	 * @param DescriptorReimportRun $run The app, slug, declared slugs and who asked.
	 *
	 * @return array<string, mixed> The counted outcome, or a failure naming what is declared.
	 */
	private function reimportDescriptor(DescriptorReimportRun $run): array {
		$descriptor = $this->descriptorFor(register: $run->register, mock: false);
		if ($descriptor === null) {
			return $this->undeclaredFailure(run: $run);
		}

		return $this->counted->run(
			slug: $run->register->slug,
			slugs: $this->declaredSlugs(document: $descriptor['data']),
			version: $this->versionOf(descriptor: $descriptor),
			fragments: 0,
			via: $run->via,
			import: fn (): array => $this->importDescriptorFile(appId: $run->register->appId, descriptor: $descriptor)
		);
	}//end reimportDescriptor()

	/**
	 * The version a descriptor ships, `0.0.1` when it names none.
	 *
	 * @param array<string, mixed> $descriptor The descriptor (file, data, registers).
	 *
	 * @return string
	 */
	private function versionOf(array $descriptor): string {
		return (string)($descriptor['data']['info']['version'] ?? '0.0.1');
	}//end versionOf()

	/**
	 * The failure for a slug that neither the base document nor a real descriptor declares.
	 *
	 * @param DescriptorReimportRun $run The app, slug and the slugs that are declared.
	 *
	 * @return array{outcome: string, reason: string}
	 */
	private function undeclaredFailure(DescriptorReimportRun $run): array {
		return [
			'outcome' => 'failed',
			'reason'  => sprintf(
				'The register configuration of %s declares no register "%s" (it declares: %s). For sample data use --mock.',
				$run->register->appId,
				$run->register->slug,
				implode(', ', $run->declared)
			),
		];
	}//end undeclaredFailure()

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
	 *
	 * @return array{success: bool, path: string, via: string, message: string, importJobId: string|null, importAppId: string}
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) DescriptorConfigIds::forFile() is a stateless lookup table shared with the
	 * repair steps; injecting it would add a dependency without removing any coupling.
	 */
	private function importDescriptorFile(string $appId, array $descriptor): array {
		$target = new DescriptorImportTarget(
			via: sprintf('OpenRegister (lib/Settings/%s)', $descriptor['file']),
			configId: DescriptorConfigIds::forFile(appId: $appId, file: $descriptor['file'])
		);
		try {
			$imported = $this->forcedImport(configId: $target->configId, descriptor: $descriptor);
		} catch (Throwable $e) {
			$this->logger->warning('[RegisterDescriptorService] re-import failed: ' . $e->getMessage());
			return $this->fileImportFailed(target: $target, failure: $e->getMessage());
		}

		return $this->fileImportSucceeded(target: $target, jobId: $this->jobIdOf(imported: $imported));
	}//end importDescriptorFile()

	/**
	 * Run the configuration import, forced past the version gate, at the version the descriptor ships.
	 *
	 * @param string               $configId   The configuration id to import under.
	 * @param array<string, mixed> $descriptor The descriptor (file, data, registers).
	 *
	 * @return array<string, mixed> The import's own result.
	 */
	private function forcedImport(string $configId, array $descriptor): array {
		return $this->configurationService->importFromApp(
			appId: $configId,
			data: $descriptor['data'],
			version: $this->versionOf(descriptor: $descriptor),
			force: true
		);
	}//end forcedImport()

	/**
	 * The import job id an import recorded, or null when it recorded none.
	 *
	 * @param array<string, mixed> $imported The import's result.
	 *
	 * @return string|null The job id.
	 */
	private function jobIdOf(array $imported): ?string {
		$jobId = ($imported['importJobId'] ?? null);
		if (is_string($jobId) === false || $jobId === '') {
			return null;
		}

		return $jobId;
	}//end jobIdOf()

	/**
	 * The result of a descriptor file import that completed.
	 *
	 * @param DescriptorImportTarget $target The label and configuration id the file imported under.
	 * @param string|null            $jobId  The import job id, when the import recorded one.
	 *
	 * @return array<string, mixed> success, path, via, message, importJobId, importAppId.
	 */
	private function fileImportSucceeded(DescriptorImportTarget $target, ?string $jobId): array {
		return [
			'success'     => true,
			'path'        => self::PATH_DESCRIPTOR,
			'via'         => $target->via,
			'message'     => '',
			'importJobId' => $jobId,
			'importAppId' => $target->configId,
		];
	}//end fileImportSucceeded()

	/**
	 * The result of a descriptor file import that threw.
	 *
	 * @param DescriptorImportTarget $target  The label and configuration id the file imported under.
	 * @param string                 $failure The failure message.
	 *
	 * @return array<string, mixed> success, path, via, message, importJobId, importAppId.
	 */
	private function fileImportFailed(DescriptorImportTarget $target, string $failure): array {
		return [
			'success'     => false,
			'path'        => self::PATH_DESCRIPTOR,
			'via'         => $target->via,
			'message'     => $failure,
			'importJobId' => null,
			'importAppId' => $target->configId,
		];
	}//end fileImportFailed()

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
	 * @spec openspec/specs/register-descriptor-admin/spec.md
	 */
	public function reimportMock(string $appId, string $slug): array {
		$register   = new ShippedRegister(appId: $appId, slug: $slug);
		$descriptor = $this->descriptorFor(register: $register, mock: true);
		if ($descriptor === null) {
			return $this->noMockFailure(register: $register);
		}

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
		$run = new MockImportRun(register: $register, descriptor: $descriptor, configId: $appId . '.mock');
		try {
			$result = $this->forcedImport(configId: $run->configId, descriptor: $descriptor);
		} catch (Throwable $e) {
			// 🔴 REPORTED, NOT SWALLOWED. The Repair steps this complements are
			// documented to never throw — defensible at boot, where the
			// alternative is an app that will not install. It is not defensible
			// for an action somebody just took: an operation that reports nothing
			// is indistinguishable from one that did nothing.
			$this->logger->warning('[RegisterDescriptorService] re-import failed: ' . $e->getMessage());
			return ['outcome' => 'failed', 'reason' => $e->getMessage()];
		}

		return $this->mockOutcome(run: $run, result: $result);
	}//end reimportMock()

	/**
	 * The failure for an app that ships no mock declaring the slug.
	 *
	 * @param ShippedRegister $register The app and slug asked for.
	 *
	 * @return array{outcome: string, reason: string}
	 */
	private function noMockFailure(ShippedRegister $register): array {
		return [
			'outcome' => 'failed',
			'reason'  => sprintf('%s ships no mock descriptor declaring register "%s".', $register->appId, $register->slug),
		];
	}//end noMockFailure()

	/**
	 * The outcome of a mock import that did not throw.
	 *
	 * @param MockImportRun        $run    The mock, its slug and the configuration id it imported under.
	 * @param array<string, mixed> $result What the import returned.
	 *
	 * @return array<string, mixed> outcome, reason, counts, importJobId, importAppId.
	 */
	private function mockOutcome(MockImportRun $run, array $result): array {
		// The job the import ran under, so a caller that undoes the import (an example set's remove) claims exactly it.
		$job = ['importJobId' => ($result['importJobId'] ?? null), 'importAppId' => $run->configId];

		$after = $this->installedRegisterVersions()[strtolower($run->register->slug)] ?? null;
		if ($after === null) {
			return [
				'outcome' => 'failed',
				'reason'  => sprintf('The import reported no error but register "%s" still does not exist.', $run->register->slug),
			] + $job;
		}

		return $this->mockCounts(descriptor: $run->descriptor, result: $result) + $job;
	}//end mockOutcome()

	/**
	 * Count what a mock import seeded, failing when it declares objects and seeded none.
	 *
	 * 🔴 COUNT WHAT LANDED, and fail when a descriptor that declares objects
	 * seeded none of them.
	 *
	 * The old post-check asked only whether a config version existed for the
	 * slug. For a mock that is already true — the app's own descriptor
	 * stamped it at install — so `outcome: imported` was returned over an
	 * import that created nothing. "Imported" that cannot be told apart from
	 * "did nothing" is the failure this whole command exists to surface.
	 *
	 * @param array{file: string, data: array<string, mixed>, registers: array<string, mixed>} $descriptor The mock descriptor.
	 * @param array<string, mixed>                                                            $result     The import's result.
	 *
	 * @return array{outcome: string, reason: string|null, counts: array<string, int>}
	 */
	private function mockCounts(array $descriptor, array $result): array {
		$declared = count(($descriptor['data']['components']['objects'] ?? []));
		$imported = count(($result['objects'] ?? []));
		$skipped  = (int)(($result['skipped']['objects'] ?? 0) + ($result['skipped']['seedObjects'] ?? 0));
		$counts   = ['declared' => $declared, 'imported' => $imported, 'skipped' => $skipped];

		$outcome = 'imported';
		$reason  = null;
		if ($declared > 0 && $imported === 0) {
			$outcome = 'failed';
			$reason  = $this->unseededReason(file: $descriptor['file'], counts: $counts);
		}

		return ['outcome' => $outcome, 'reason' => $reason, 'counts' => $counts];
	}//end mockCounts()

	/**
	 * Why a mock that declares objects but seeded none failed.
	 *
	 * @param string             $file   The descriptor file name.
	 * @param array<string, int> $counts declared, imported and skipped object counts.
	 *
	 * @return string
	 */
	private function unseededReason(string $file, array $counts): string {
		return sprintf(
			'%s declares %d demo object(s) but none were imported (%d skipped). The register and its schemas may have landed; the data did not.',
			$file,
			$counts['declared'],
			$counts['skipped']
		);
	}//end unseededReason()

	/**
	 * Whether the instance holds an older version of a register than the
	 * descriptor of the given kind (mock or real) ships.
	 *
	 * A register that is not on the instance, or that no descriptor of the
	 * kind declares, is not behind: there is nothing to bring up to date.
	 *
	 * @param ShippedRegister $register The app whose descriptors are read and the register slug.
	 * @param bool            $mock     True for the mock descriptor, false for the real one.
	 *
	 * @return bool True when the shipped version is newer than the installed one.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-set-imports-the-openregister-registers-it-requires-req-exs-009
	 */
	public function isShippedBehind(ShippedRegister $register, bool $mock): bool {
		$descriptor = $this->descriptorFor(register: $register, mock: $mock);
		$installed  = ($this->installedRegisterVersions()[strtolower($register->slug)] ?? null);
		if ($descriptor === null || $installed === null) {
			return false;
		}

		foreach ($descriptor['registers'] as $declared => $declaration) {
			if (strtolower((string)$declared) === strtolower($register->slug)) {
				return version_compare((string)($declaration['version'] ?? '0.0.1'), $installed, '>');
			}
		}

		return false;
	}//end isShippedBehind()

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
		$found = [];
		foreach ($this->descriptorFiles(appId: $appId) as $file) {
			$descriptor = $this->readDescriptor(file: $file);
			if ($descriptor !== null) {
				$found[] = $descriptor + ['owner' => $this->ownerOf(appId: $appId, descriptor: $descriptor)];
			}
		}

		return $found;
	}//end descriptorsFor()

	/**
	 * The JSON files in an app's descriptor directory; none when it has no such directory.
	 *
	 * @param string $appId The app to scan.
	 *
	 * @return array<int, string> Absolute file paths.
	 */
	private function descriptorFiles(string $appId): array {
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

		return $files;
	}//end descriptorFiles()

	/**
	 * Read one file as a register descriptor, or null when it is not one.
	 *
	 * @param string $file The file path.
	 *
	 * @return array{file: string, data: array<string, mixed>, registers: array<string, mixed>}|null The descriptor.
	 */
	private function readDescriptor(string $file): ?array {
		$data = json_decode((string)file_get_contents($file), true);
		if (is_array($data) === false) {
			$this->logger->debug('[RegisterDescriptorService] not JSON, skipped: ' . $file);
			return null;
		}

		$registers = ($data['components']['registers'] ?? null);
		if (is_array($registers) === false || $registers === []) {
			return null;
		}

		return ['file' => basename($file), 'data' => $data, 'registers' => $registers];
	}//end readDescriptor()

	/**
	 * The descriptor of the given kind (mock or real) that declares a slug.
	 *
	 * @param ShippedRegister $register The app whose descriptors are read and the register slug.
	 * @param bool            $mock     True for the mock descriptor, false for the real one.
	 *
	 * @return array<string, mixed>|null The descriptor, or null when none declares the slug.
	 */
	private function descriptorFor(ShippedRegister $register, bool $mock): ?array {
		foreach ($this->descriptorsFor(appId: $register->appId) as $descriptor) {
			$isMock = (($descriptor['data']['x-openregister']['type'] ?? '') === 'mock');
			if ($isMock === $mock && $this->declaresSlug(descriptor: $descriptor, slug: $register->slug) === true) {
				return $descriptor;
			}
		}

		return null;
	}//end descriptorFor()

	/**
	 * Whether a descriptor declares a register slug, case-insensitively.
	 *
	 * @param array{file: string, data: array<string, mixed>, registers: array<string, mixed>} $descriptor The descriptor.
	 * @param string                                                                          $slug       The register slug.
	 *
	 * @return bool True when one of its register keys matches.
	 */
	private function declaresSlug(array $descriptor, string $slug): bool {
		foreach (array_keys($descriptor['registers']) as $declared) {
			if (strtolower((string)$declared) === strtolower($slug)) {
				return true;
			}
		}

		return false;
	}//end declaresSlug()
}//end class
