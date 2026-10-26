<?php

/**
 * RegisterDescriptorServiceTest.
 *
 * The assertions that matter here are the ones about what the inventory says
 * when something is WRONG. A test suite that only exercises the healthy row
 * reproduces the silence this service was written to break.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/register-descriptor-admin/specs/register-descriptor-admin/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\AppHost\AppContainerLocator;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\AppRegisterImportRunner;
use OCA\OpenRegister\Service\ConfigurationService;
use OCA\OpenRegister\Service\RegisterDescriptorService;
use OCA\OpenRegister\Service\Configuration\DescriptorConfigIds;
use OCA\OpenRegister\Service\CountedRegisterImport;
use OCA\OpenRegister\Service\ShippedBaseline\ShippedAuthorizationDecision;
use Psr\Container\ContainerInterface;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Covers the inventory's three states and the forced re-import.
 */
class RegisterDescriptorServiceTest extends TestCase {
	private RegisterDescriptorService $service;

	private $appManager;

	private $registerMapper;

	private $configurationService;

	private $schemaMapper;

	private $containers;

	/**
	 * The decision the re-import opens, shared with the guard.
	 *
	 * @var ShippedAuthorizationDecision
	 */
	private ShippedAuthorizationDecision $decision;

	/**
	 * Temporary app root holding the descriptor fixtures.
	 *
	 * @var string
	 */
	private string $appRoot = '';

	protected function setUp(): void {
		$this->appRoot = sys_get_temp_dir() . '/or-descriptor-' . bin2hex(random_bytes(6));
		mkdir($this->appRoot . '/lib/Settings', 0o777, true);

		$this->appManager           = $this->createMock(IAppManager::class);
		$this->registerMapper       = $this->createMock(RegisterMapper::class);
		$this->configurationService = $this->createMock(ConfigurationService::class);
		$this->schemaMapper         = $this->createMock(SchemaMapper::class);
		$this->containers           = $this->createMock(AppContainerLocator::class);
		$this->decision             = new ShippedAuthorizationDecision();

		$this->service = new RegisterDescriptorService(
			$this->appManager,
			$this->registerMapper,
			$this->configurationService,
			$this->createMock(LoggerInterface::class),
			new CountedRegisterImport(
				registerMapper: $this->registerMapper,
				schemaMapper: $this->schemaMapper,
				decision: $this->decision
			),
			new AppRegisterImportRunner(
				appManager: $this->appManager,
				containers: $this->containers,
				configurationService: $this->configurationService
			)
		);
	}

	protected function tearDown(): void {
		foreach ((glob($this->appRoot . '/lib/Settings/register.d/*') ?: []) as $file) {
			unlink($file);
		}

		if (is_dir($this->appRoot . '/lib/Settings/register.d') === true) {
			rmdir($this->appRoot . '/lib/Settings/register.d');
		}

		foreach ((glob($this->appRoot . '/lib/Settings/*') ?: []) as $file) {
			unlink($file);
		}

		if (is_dir($this->appRoot . '/lib/Settings') === true) {
			rmdir($this->appRoot . '/lib/Settings');
			rmdir($this->appRoot . '/lib');
			rmdir($this->appRoot);
		}
	}

	/**
	 * Write a descriptor fixture into the fake app root.
	 *
	 * @param string $file    File name.
	 * @param string $slug    Register slug it declares.
	 * @param string $version Version the descriptor ships.
	 *
	 * @return void
	 */
	private function writeDescriptor(
		string $file,
		string $slug,
		string $version,
		?string $type = null,
		?string $app = null,
		int $objects = 0
	): void {
		$descriptor = [
			'openapi'    => '3.0.0',
			'info'       => ['title' => 'Fixture', 'version' => $version],
			'components' => [
				'registers' => [$slug => ['slug' => $slug, 'title' => ucfirst($slug), 'version' => $version]],
			],
		];

		if ($objects > 0) {
			$descriptor['components']['objects'] = array_map(
				static fn (int $i): array => [
					'@self' => ['register' => $slug, 'schema' => 'thing', 'slug' => 'thing-' . $i],
					'name'  => 'Voorbeeld ' . $i,
				],
				range(1, $objects)
			);
		}

		$marker = [];
		if ($type !== null) {
			$marker['type'] = $type;
		}

		if ($app !== null) {
			$marker['app'] = $app;
		}

		if ($marker !== []) {
			$descriptor['x-openregister'] = $marker;
		}

		file_put_contents($this->appRoot . '/lib/Settings/' . $file, json_encode($descriptor));
	}

	/**
	 * 🔴 A MOCK IS NOT A MISSING REGISTER.
	 *
	 * `type: mock` marks sample data imported on demand, not something an
	 * instance is expected to carry. Across OpenRegister's own descriptors the
	 * correlation is exact — all five mocks ship without a Repair step, and every
	 * non-mock but one has one. Listing them as ABSENT put five permanent red
	 * rows in front of every administrator, and acting on those rows would have
	 * seeded five mock registers onto every instance in the fleet.
	 */
	public function testAMockDescriptorIsNotReportedAsAbsent(): void {
		$this->writeDescriptor(file: 'mock_register.json', slug: 'bag', version: '1.0.0', type: 'mock');
		$this->expectApp();
		$this->registerMapper->method('findAll')->willReturn([]);

		$this->assertSame([], $this->service->inventory());
	}

	/**
	 * Control for the test above: the SAME descriptor without the mock marker is
	 * still reported. Without this, a bug that dropped every row would pass.
	 */
	public function testANonMockDescriptorIsStillReported(): void {
		$this->writeDescriptor(file: 'real_register.json', slug: 'bag', version: '1.0.0', type: 'core');
		$this->expectApp();
		$this->registerMapper->method('findAll')->willReturn([]);

		$rows = $this->service->inventory();

		$this->assertCount(1, $rows);
		$this->assertSame(RegisterDescriptorService::STATE_ABSENT, $rows[0]['state']);
	}

	/**
	 * A row names the app that DECLARES the register, not the directory the file
	 * was found in. A descriptor shipped in OpenRegister's own lib/Settings may
	 * declare a different `app`; filing it under `openregister` tells
	 * the reader the wrong owner, and an inventory exists to say whose problem a
	 * row is.
	 */
	public function testARowIsAttributedToTheAppThatDeclaresIt(): void {
		$this->writeDescriptor(
			file: 'foreign_register.json',
			slug: 'workflows',
			version: '1.0.0',
			type: 'integration',
			app: 'n8n'
		);
		$this->expectApp('openregister');
		$this->registerMapper->method('findAll')->willReturn([]);

		$rows = $this->service->inventory();

		$this->assertCount(1, $rows);
		$this->assertSame('n8n', $rows[0]['appId'], 'the declaring app owns the row');
	}

	/**
	 * Point the app manager at the fixture root for one app.
	 *
	 * @return void
	 */
	private function expectApp(string $appId = 'fixtureapp'): void {
		$this->appManager->method('getInstalledApps')->willReturn([$appId]);
		$this->appManager->method('getAppPath')->willReturn($this->appRoot);
	}

	/**
	 * Build a register the mapper will return.
	 *
	 * @return Register The register.
	 */
	private function register(string $slug, string $version): Register {
		$register = new Register();
		$register->setSlug($slug);
		$register->setVersion($version);

		return $register;
	}

	/**
	 * 🔴 The row this whole service exists for.
	 */
	public function testAnAppWhoseRegisterNeverLandedIsReportedAbsent(): void {
		$this->writeDescriptor(file: 'a_register.json', slug: 'flows', version: '1.3.0');
		$this->expectApp();
		$this->registerMapper->method('findAll')->willReturn([]);

		$rows = $this->service->inventory();

		$this->assertCount(1, $rows);
		$this->assertSame(RegisterDescriptorService::STATE_ABSENT, $rows[0]['state']);
		$this->assertNull($rows[0]['installedVersion']);
		$this->assertSame('1.3.0', $rows[0]['shippedVersion']);
	}

	public function testAMatchingVersionIsReportedCurrent(): void {
		$this->writeDescriptor(file: 'a_register.json', slug: 'flows', version: '1.3.0');
		$this->expectApp();
		$this->registerMapper->method('findAll')->willReturn([$this->register('flows', '1.3.0')]);

		$rows = $this->service->inventory();

		$this->assertSame(RegisterDescriptorService::STATE_CURRENT, $rows[0]['state']);
		$this->assertSame('1.3.0', $rows[0]['installedVersion']);
	}

	/**
	 * `behind` must stay distinct from `absent`: they need different actions and
	 * carry different risk — absent means a code path is dead, behind means it
	 * runs against an older contract.
	 */
	public function testAnOlderInstalledVersionIsReportedBehindNotAbsent(): void {
		$this->writeDescriptor(file: 'a_register.json', slug: 'flows', version: '1.4.0');
		$this->expectApp();
		$this->registerMapper->method('findAll')->willReturn([$this->register('flows', '1.3.0')]);

		$rows = $this->service->inventory();

		$this->assertSame(RegisterDescriptorService::STATE_BEHIND, $rows[0]['state']);
		$this->assertSame('1.3.0', $rows[0]['installedVersion']);
		$this->assertSame('1.4.0', $rows[0]['shippedVersion']);
	}

	public function testAnAppShippingNoDescriptorIsOmittedNotListedAbsent(): void {
		$this->expectApp();
		$this->registerMapper->method('findAll')->willReturn([]);

		$this->assertSame([], $this->service->inventory());
	}

	/**
	 * A descriptor is recognised by SHAPE, not filename — the fleet's names vary
	 * (`credential-providers.json`, `flow_register.json`), and a
	 * `*_register.json` glob would silently shrink the inventory rather than
	 * fail, which is this service's own failure mode one level up.
	 */
	public function testADescriptorIsFoundRegardlessOfItsFilename(): void {
		$this->writeDescriptor(file: 'credential-providers.json', slug: 'broker', version: '1.0.0');
		$this->expectApp();
		$this->registerMapper->method('findAll')->willReturn([]);

		$rows = $this->service->inventory();

		$this->assertCount(1, $rows);
		$this->assertSame('broker', $rows[0]['slug']);
	}

	public function testANonDescriptorJsonFileIsIgnored(): void {
		file_put_contents($this->appRoot . '/lib/Settings/notes.json', json_encode(['hello' => 'world']));
		$this->expectApp();
		$this->registerMapper->method('findAll')->willReturn([]);

		$this->assertSame([], $this->service->inventory());
	}

	/**
	 * 🔴 The force is the whole point. `ImportHandler` short-circuits on
	 * `version_compare($shipped, $installed, '<=')` when force is false — which
	 * is exactly the state an administrator presses this in.
	 */
	public function testReimportForcesPastTheVersionGate(): void {
		$this->writeDescriptor(file: 'fixtureapp_register.json', slug: 'flows', version: '1.3.0');
		$this->expectApp('fixtureapp');
		$this->registerMapper->method('findAll')->willReturn([$this->register('flows', '1.3.0')]);

		$this->configurationService->expects($this->once())
			->method('importFromApp')
			->with(
				$this->equalTo('fixtureapp'),
				$this->anything(),
				$this->equalTo('1.3.0'),
				$this->isTrue()
			)
			->willReturn(['registers' => ['flows']]);

		$result = $this->service->reimport(appId: 'fixtureapp', slug: 'flows');

		$this->assertSame('imported', $result['outcome']);
	}

	/**
	 * A register is behind when the descriptor of the asked kind ships a newer
	 * version than the instance holds; same version, the other kind or a
	 * register that is not there is not behind.
	 *
	 * @return void
	 */
	public function testARegisterIsBehindOnlyWhenItsDescriptorIsNewer(): void {
		$this->writeDescriptor(file: 'a_mock_register.json', slug: 'flows', version: '1.0.1', type: 'mock');
		$this->writeDescriptor(file: 'flows_register.json', slug: 'flows', version: '1.0.0');
		$this->expectApp(appId: 'fixtureapp');
		$this->registerMapper->method('findAll')->willReturn([$this->register(slug: 'flows', version: '1.0.0')]);

		$this->assertTrue(condition: $this->service->isBehind(appId: 'fixtureapp', slug: 'flows', mock: true));
		$this->assertFalse(condition: $this->service->isBehind(appId: 'fixtureapp', slug: 'flows', mock: false));
		$this->assertFalse(condition: $this->service->isBehind(appId: 'fixtureapp', slug: 'other', mock: true));
	}//end testARegisterIsBehindOnlyWhenItsDescriptorIsNewer()

	/**
	 * 🔴 A MOCK IMPORTS UNDER ITS OWN CONFIGURATION IDENTITY.
	 *
	 * `importFromApp` stamps `imported_config_<appId>_version` plus a content
	 * hash. A mock declares the SAME register slug as the app's real descriptor —
	 * larpinq ships `larpinq_register.json` and `larpinq_mock_register.json`,
	 * both declaring `larpinq` — so importing the mock under the plain app id
	 * writes over the real descriptor's stamp and reads its hash.
	 *
	 * Measured on a live instance before this fix: the command printed
	 * `register "larpinq" imported.` while the register ended up with the REAL
	 * descriptor's 11 schemas and ZERO of the mock's 30 objects.
	 */
	public function testAMockImportsUnderItsOwnConfigurationIdentity(): void {
		$this->writeDescriptor(
			file: 'a_mock_register.json',
			slug: 'flows',
			version: '1.0.0',
			type: 'mock',
			objects: 3
		);
		$this->expectApp('fixtureapp');
		$this->registerMapper->method('findAll')->willReturn([$this->register('flows', '1.0.0')]);

		$this->configurationService->expects($this->once())
			->method('importFromApp')
			->with(
				// NOT 'fixtureapp' — that identity belongs to the real descriptor.
				$this->equalTo('fixtureapp.mock'),
				$this->anything(),
				$this->equalTo('1.0.0'),
				$this->isTrue()
			)
			->willReturn(['objects' => [1, 2, 3], 'skipped' => []]);

		$result = $this->service->reimportMock(appId: 'fixtureapp', slug: 'flows');

		$this->assertSame('imported', $result['outcome']);
		$this->assertSame(3, $result['counts']['imported']);
	}

	/**
	 * 🔴 "IMPORTED" MUST NOT BE SAYABLE OVER A NO-OP.
	 *
	 * The old post-check asked only whether a config version existed for the
	 * slug. For a mock that is already true — the app's own descriptor stamped it
	 * at install — so the service returned `imported` for an import that created
	 * nothing at all. A success message that cannot be told apart from silence is
	 * the exact failure this command was built to surface.
	 */
	public function testADescriptorThatSeedsNoneOfItsObjectsIsAFailure(): void {
		$this->writeDescriptor(
			file: 'a_mock_register.json',
			slug: 'flows',
			version: '1.0.0',
			type: 'mock',
			objects: 30
		);
		$this->expectApp('fixtureapp');
		$this->registerMapper->method('findAll')->willReturn([$this->register('flows', '1.0.0')]);

		// The register lands, the data does not — exactly what was measured live.
		$this->configurationService->method('importFromApp')
			->willReturn(['objects' => [], 'skipped' => ['objects' => 30, 'seedObjects' => 0]]);

		$result = $this->service->reimportMock(appId: 'fixtureapp', slug: 'flows');

		$this->assertSame('failed', $result['outcome']);
		$this->assertStringContainsString('30', (string)$result['reason']);
		$this->assertStringContainsString('none were imported', (string)$result['reason']);
		$this->assertSame(0, $result['counts']['imported']);
	}

	/**
	 * A real register re-import is not judged by demo objects: it declares
	 * none, and it reports registers and schemas instead.
	 */
	public function testARegisterReimportReportsRegistersAndSchemasNotObjects(): void {
		$this->writeDescriptor(file: 'fixtureapp_register.json', slug: 'flows', version: '1.3.0');
		$this->expectApp('fixtureapp');
		$this->registerMapper->method('findAll')->willReturn([$this->register('flows', '1.3.0')]);
		$this->configurationService->method('importFromApp')->willReturn(['registers' => ['flows']]);

		$result = $this->service->reimport(appId: 'fixtureapp', slug: 'flows');

		$this->assertSame('imported', $result['outcome']);
		$this->assertSame(['created' => 0, 'updated' => 0, 'unchanged' => 1], $result['counts']['registers']);
		$this->assertArrayNotHasKey('declared', $result['counts']);
	}

	/**
	 * Reported, not swallowed. The Repair steps this complements never throw —
	 * defensible at boot, not for an action somebody just took.
	 */
	public function testAFailedImportIsReportedWithItsReason(): void {
		$this->writeDescriptor(file: 'fixtureapp_register.json', slug: 'flows', version: '1.3.0');
		$this->expectApp();
		$this->registerMapper->method('findAll')->willReturn([]);
		$this->configurationService->method('importFromApp')
			->willThrowException(new RuntimeException('the table is missing'));

		$result = $this->service->reimport(appId: 'fixtureapp', slug: 'flows');

		$this->assertSame('failed', $result['outcome']);
		$this->assertStringContainsString('the table is missing', (string)$result['reason']);
	}

	/**
	 * An import that throws nothing but writes nothing is still a failure. This
	 * is the shape that would otherwise report success over an absent register —
	 * the exact defect the capability exists to end.
	 */
	public function testAnImportThatWritesNothingIsReportedFailedNotImported(): void {
		$this->writeDescriptor(file: 'fixtureapp_register.json', slug: 'flows', version: '1.3.0');
		$this->expectApp();
		$this->registerMapper->method('findAll')->willReturn([]);
		$this->configurationService->method('importFromApp')->willReturn(['registers' => ['flows']]);

		$result = $this->service->reimport(appId: 'fixtureapp', slug: 'flows');

		$this->assertSame('failed', $result['outcome']);
		$this->assertStringContainsString('still does not exist', (string)$result['reason']);
	}

	/**
	 * 🔴 THE DISCOVERY RULE, PINNED AGAINST THE REAL SHIPPED DESCRIPTORS.
	 *
	 * Every other test here feeds the service a fixture it wrote itself, so all
	 * of them would still pass if the rule stopped matching the descriptors the
	 * fleet actually ships — the failure would be a SHORTER inventory, which is
	 * the one failure mode a fixture-only suite cannot see. This reads
	 * OpenRegister's own `lib/Settings`, where the filenames deliberately vary
	 * (`flow_register.json`, `credential-providers.json`), and asserts every declaring file is
	 * found.
	 */
	public function testEveryDescriptorOpenRegisterShipsIsDiscovered(): void {
		$settings = __DIR__ . '/../../../lib/Settings';
		$this->assertDirectoryExists($settings);

		// Independently count the files that DECLARE a register, by reading them
		// here rather than by asking the service — a count derived from the code
		// under test could only ever agree with itself.
		$declaring = [];
		foreach ((array)glob($settings . '/*.json') as $file) {
			$data = json_decode((string)file_get_contents((string)$file), true);
			if (is_array($data) === false) {
				continue;
			}

			// Mocks are excluded HERE TOO, by reading the marker directly rather
			// than by asking the service — a count derived from the code under
			// test could only ever agree with itself. This test's job is to catch
			// a discovery rule that silently SHRINKS, so its own count has to be
			// computed independently and by the same stated rule.
			if (($data['x-openregister']['type'] ?? '') === 'mock') {
				continue;
			}

			$registers = ($data['components']['registers'] ?? null);
			if (is_array($registers) === true && $registers !== []) {
				$declaring[basename((string)$file)] = count($registers);
			}
		}

		$this->assertNotEmpty($declaring, 'OpenRegister ships register descriptors');

		$this->appManager->method('getInstalledApps')->willReturn(['openregister']);
		$this->appManager->method('getAppPath')->willReturn(__DIR__ . '/../../..');
		$this->registerMapper->method('findAll')->willReturn([]);

		$rows = $this->service->inventory();

		$this->assertSame(
			array_sum($declaring),
			count($rows),
			'the inventory must report every register every shipped NON-MOCK descriptor declares — '
			. 'a narrower discovery rule shrinks the list instead of failing'
		);

		$byFile = array_count_values(array_column($rows, 'descriptor'));
		foreach ($declaring as $file => $expected) {
			$this->assertArrayHasKey($file, $byFile, $file . ' was not discovered');
			$this->assertSame($expected, $byFile[$file], $file . ' declared a different number of registers');
		}
	}

	public function testReimportingASlugTheAppDoesNotDeclareFails(): void {
		$this->writeDescriptor(file: 'fixtureapp_register.json', slug: 'flows', version: '1.3.0');
		$this->expectApp();

		$result = $this->service->reimport(appId: 'fixtureapp', slug: 'nothing-here');

		$this->assertSame('failed', $result['outcome']);
		$this->assertStringContainsString('nothing-here', (string)$result['reason']);
	}

	/**
	 * A schema the mapper will return.
	 *
	 * @param int                  $id            The schema id.
	 * @param array<string, mixed> $authorization Its authorization.
	 *
	 * @return Schema The schema.
	 */
	private function schema(int $id, array $authorization): Schema {
		$schema = new Schema();
		$schema->setId($id);
		$schema->setSlug('schema-' . $id);
		$schema->setAuthorization($authorization);

		return $schema;
	}

	/**
	 * 🔴 FINDING 1: `--import` IMPORTED THE MOCK.
	 *
	 * portaliq ships `portaliq_mock_register.json` (0.27.0, demo objects) and
	 * `portaliq_register.json` (0.97.0), both declaring `portaliq`. Glob order
	 * put the mock first, so the "re-import" seeded 164 demo objects and set
	 * the register to 0.27.0. The real configuration is the one imported now.
	 *
	 * @spec openspec/changes/reimport-real-register-configuration/specs/register-descriptor-admin/spec.md
	 */
	public function testTheRealConfigurationIsImportedNotTheMock(): void {
		$this->writeDescriptor(file: 'fixtureapp_mock_register.json', slug: 'flows', version: '0.27.0', type: 'mock', objects: 3);
		$this->writeDescriptor(file: 'fixtureapp_register.json', slug: 'flows', version: '0.97.0', type: 'application');
		$this->expectApp('fixtureapp');
		$this->registerMapper->method('findAll')->willReturn([$this->register('flows', '0.97.0')]);

		$this->configurationService->expects($this->once())
			->method('importFromApp')
			->with(
				$this->equalTo('fixtureapp'),
				$this->callback(static fn (array $data): bool => ($data['info']['version'] ?? null) === '0.97.0'),
				$this->equalTo('0.97.0'),
				$this->isTrue()
			)
			->willReturn(['registers' => ['flows']]);

		$result = $this->service->reimport(appId: 'fixtureapp', slug: 'flows');

		$this->assertSame('imported', $result['outcome']);
		$this->assertSame(AppRegisterImportRunner::PATH_OPENREGISTER, $result['path']);
	}

	/**
	 * The register.d fragments are part of the real configuration, as on upgrade.
	 *
	 * @spec openspec/changes/reimport-real-register-configuration/specs/register-descriptor-admin/spec.md
	 */
	public function testRegisterDFragmentsAreImportedWithTheBase(): void {
		$this->writeDescriptor(file: 'fixtureapp_register.json', slug: 'flows', version: '1.3.0');
		mkdir($this->appRoot . '/lib/Settings/register.d');
		file_put_contents(
			$this->appRoot . '/lib/Settings/register.d/10-extra.json',
			json_encode(['components' => ['schemas' => ['extra' => ['slug' => 'extra', 'authorization' => ['read' => ['staff']]]]]])
		);
		$this->expectApp('fixtureapp');
		$this->registerMapper->method('findAll')->willReturn([$this->register('flows', '1.3.0')]);

		$this->configurationService->expects($this->once())
			->method('importFromApp')
			->with(
				$this->equalTo('fixtureapp'),
				$this->callback(static fn (array $data): bool => isset($data['components']['schemas']['extra']) === true),
				$this->stringStartsWith('1.3.0+frag.'),
				$this->isTrue()
			)
			->willReturn(['registers' => ['flows']]);

		$result = $this->service->reimport(appId: 'fixtureapp', slug: 'flows');

		$this->assertSame('imported', $result['outcome']);
		$this->assertSame(1, $result['fragments']);
	}

	/**
	 * 🔴 THE APP'S OWN FORCED ENTRY POINT RUNS, UNDER THE ADMINISTRATOR'S DECISION.
	 *
	 * pipelinq re-grants its CRM groups after its import and portaliq its
	 * page-editor groups, so the app's SettingsService is the real path. The
	 * decision to take the file's authorization must be in effect while it runs.
	 *
	 * @spec openspec/changes/reimport-real-register-configuration/specs/register-descriptor-admin/spec.md
	 */
	public function testTheAppsOwnForcedEntryPointRunsUnderTheDecision(): void {
		$this->writeDescriptor(file: 'fixtureapp_register.json', slug: 'flows', version: '1.3.0');
		$this->expectApp('fixtureapp');
		$this->appManager->method('getAppInfo')->willReturn(['namespace' => 'FixtureApp']);
		$this->registerMapper->method('findAll')->willReturn([$this->register('flows', '1.3.0')]);

		$decision = $this->decision;
		$settings = new class($decision) {
			public array $calls = [];

			public function __construct(private ShippedAuthorizationDecision $decision) {
			}

			public function loadConfiguration(): array {
				$this->calls[] = 'unforced';
				return ['success' => true];
			}

			public function loadSettings(bool $force = false): array {
				$this->calls[] = ['force' => $force, 'decision' => $this->decision->via()];
				$this->decision->noteReplaced(slug: 'portalAccount', roots: ['authorization']);
				return ['success' => true];
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('has')->with('OCA\\FixtureApp\\Service\\SettingsService')->willReturn(true);
		$container->method('get')->willReturn($settings);
		$this->containers->method('find')->willReturn($container);

		$this->configurationService->expects($this->never())->method('importFromApp');

		$result = $this->service->reimport(appId: 'fixtureapp', slug: 'flows');

		$this->assertSame('imported', $result['outcome']);
		$this->assertSame(AppRegisterImportRunner::PATH_APP, $result['path']);
		$this->assertStringContainsString('loadSettings(force: true)', $result['via']);
		$this->assertSame(
			[['force' => true, 'decision' => 'occ openregister:descriptors:list --import']],
			$settings->calls,
			'the forced method ran once, under the decision; the unforced one never ran'
		);
		$this->assertSame(1, $result['counts']['authorizationReplaced']);
		$this->assertNull($this->decision->via());
	}

	/**
	 * The counts are read from the database before and after, not from the import's own report.
	 *
	 * @spec openspec/changes/reimport-real-register-configuration/specs/register-descriptor-admin/spec.md
	 */
	public function testTheCountsComeFromTheDatabase(): void {
		$this->writeDescriptor(file: 'fixtureapp_register.json', slug: 'flows', version: '0.97.0');
		$this->expectApp('fixtureapp');

		$before = $this->register('flows', '0.96.0');
		$before->setSchemas([1, 2]);
		$after = $this->register('flows', '0.97.0');
		$after->setSchemas([1, 2, 3]);
		$this->registerMapper->method('findAll')->willReturnOnConsecutiveCalls([$before], [$after]);

		$this->schemaMapper->method('findMultiple')->willReturnOnConsecutiveCalls(
			[$this->schema(1, ['read' => ['authenticated']]), $this->schema(2, ['read' => ['admin']])],
			[
				$this->schema(1, ['read' => ['portaliq-medewerkers']]),
				$this->schema(2, ['read' => ['admin']]),
				$this->schema(3, ['read' => ['admin']]),
			]
		);
		$this->configurationService->method('importFromApp')->willReturn(['registers' => ['flows']]);

		$result = $this->service->reimport(appId: 'fixtureapp', slug: 'flows');

		$this->assertSame(['created' => 0, 'updated' => 1, 'unchanged' => 0], $result['counts']['registers']);
		$this->assertSame(['created' => 1, 'updated' => 1, 'unchanged' => 1], $result['counts']['schemas']);
	}

	/**
	 * `--mock` imports only a descriptor marked as a mock, even where the real one sorts first.
	 *
	 * @spec openspec/changes/reimport-real-register-configuration/specs/register-descriptor-admin/spec.md
	 */
	public function testTheMockImportNeverPicksTheRealDescriptor(): void {
		$this->writeDescriptor(file: 'a_register.json', slug: 'flows', version: '0.97.0');
		$this->writeDescriptor(file: 'z_mock_register.json', slug: 'flows', version: '0.27.0', type: 'mock', objects: 2);
		$this->expectApp('fixtureapp');
		$this->registerMapper->method('findAll')->willReturn([$this->register('flows', '0.27.0')]);

		$this->configurationService->expects($this->once())
			->method('importFromApp')
			->with($this->equalTo('fixtureapp.mock'), $this->anything(), $this->equalTo('0.27.0'), $this->isTrue())
			->willReturn(['objects' => [1, 2], 'skipped' => []]);

		$result = $this->service->reimportMock(appId: 'fixtureapp', slug: 'flows');

		$this->assertSame('imported', $result['outcome']);
		$this->assertSame(2, $result['counts']['imported']);
	}

	/**
	 * An app with no base document, as OpenRegister itself, re-imports the
	 * non-mock descriptor that declares the slug, never its mock.
	 *
	 * @spec openspec/changes/reimport-real-register-configuration/specs/register-descriptor-admin/spec.md
	 */
	public function testAnAppWithoutABaseDocumentImportsTheDescriptorDeclaringTheSlug(): void {
		$this->writeDescriptor(file: 'a_mock_register.json', slug: 'flows', version: '0.1.0', type: 'mock', objects: 2);
		$this->writeDescriptor(file: 'flow_register.json', slug: 'flows', version: '1.3.0');
		$this->expectApp('fixtureapp');
		$this->registerMapper->method('findAll')->willReturn([$this->register('flows', '1.3.0')]);

		$this->configurationService->expects($this->once())
			->method('importFromApp')
			->with($this->equalTo('fixtureapp'), $this->anything(), $this->equalTo('1.3.0'), $this->isTrue())
			->willReturn(['registers' => ['flows']]);

		$result = $this->service->reimport(appId: 'fixtureapp', slug: 'flows');

		$this->assertSame('imported', $result['outcome']);
		$this->assertSame(RegisterDescriptorService::PATH_DESCRIPTOR, $result['path']);
		$this->assertStringContainsString('flow_register.json', $result['via']);
	}

	/**
	 * A core descriptor is re-imported under the configuration id its repair
	 * step imports it under, never the shared `openregister` id: that would set
	 * the version the force:false repair steps of other descriptors compare
	 * against.
	 *
	 * @spec openspec/changes/reimport-real-register-configuration/specs/register-descriptor-admin/spec.md
	 */
	public function testACoreDescriptorIsReimportedUnderItsInstallTimeConfigurationId(): void {
		$this->writeDescriptor(file: 'merge_operation_register.json', slug: 'merge-operation', version: '1.4.0');
		$this->expectApp('openregister');
		$this->registerMapper->method('findAll')->willReturn([$this->register('merge-operation', '1.4.0')]);

		$this->configurationService->expects($this->once())
			->method('importFromApp')
			->with($this->equalTo('openregister.merge-operation'), $this->anything(), $this->equalTo('1.4.0'), $this->isTrue())
			->willReturn(['registers' => ['merge-operation'], 'importJobId' => 'job-7']);

		$result = $this->service->reimport(appId: 'openregister', slug: 'merge-operation');

		$this->assertSame('imported', $result['outcome']);
		$this->assertSame('job-7', $result['importJobId'], 'the job the import ran under is reported');
		$this->assertSame('openregister.merge-operation', $result['importAppId']);
	}

	/**
	 * A descriptor whose repair step imports under the shared id keeps it, and
	 * another app's file never takes a core descriptor's id.
	 *
	 * @spec openspec/changes/reimport-real-register-configuration/specs/register-descriptor-admin/spec.md
	 */
	public function testADescriptorWithoutADedicatedIdAndAnotherAppsFileKeepTheirOwnId(): void {
		$this->assertSame('openregister', DescriptorConfigIds::forFile(appId: 'openregister', file: 'flow_register.json'));
		$this->assertSame('openregister', DescriptorConfigIds::forFile(appId: 'openregister', file: 'brp_register.json'));
		$this->assertSame('fixtureapp', DescriptorConfigIds::forFile(appId: 'fixtureapp', file: 'merge_operation_register.json'));
		$this->assertSame('openregister.dsar-policy-packs', DescriptorConfigIds::forFile(appId: 'openregister', file: 'dsar_policy_pack_register.json'));
	}
}
