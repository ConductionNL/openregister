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
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Service\ConfigurationService;
use OCA\OpenRegister\Service\RegisterDescriptorService;
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

		$this->service = new RegisterDescriptorService(
			$this->appManager,
			$this->registerMapper,
			$this->configurationService,
			$this->createMock(LoggerInterface::class)
		);
	}

	protected function tearDown(): void {
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
		?string $app = null
	): void {
		$descriptor = [
			'openapi'    => '3.0.0',
			'info'       => ['title' => 'Fixture', 'version' => $version],
			'components' => [
				'registers' => [$slug => ['slug' => $slug, 'title' => ucfirst($slug), 'version' => $version]],
			],
		];

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
	 * was found in. `n8n_workflows.openregister.json` ships in OpenRegister's
	 * lib/Settings and declares `app: n8n`; filing it under `openregister` tells
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
	 * (`credential-providers.json`, `n8n_workflows.openregister.json`), and a
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
		$this->writeDescriptor(file: 'a_register.json', slug: 'flows', version: '1.3.0');
		$this->expectApp('fixtureapp');
		$this->registerMapper->method('findAll')->willReturn([$this->register('flows', '1.3.0')]);

		$this->configurationService->expects($this->once())
			->method('importFromApp')
			->with(
				$this->equalTo('fixtureapp'),
				$this->anything(),
				$this->equalTo('1.3.0'),
				$this->isTrue()
			);

		$result = $this->service->reimport(appId: 'fixtureapp', slug: 'flows');

		$this->assertSame('imported', $result['outcome']);
	}

	/**
	 * Reported, not swallowed. The Repair steps this complements never throw —
	 * defensible at boot, not for an action somebody just took.
	 */
	public function testAFailedImportIsReportedWithItsReason(): void {
		$this->writeDescriptor(file: 'a_register.json', slug: 'flows', version: '1.3.0');
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
		$this->writeDescriptor(file: 'a_register.json', slug: 'flows', version: '1.3.0');
		$this->expectApp();
		$this->registerMapper->method('findAll')->willReturn([]);

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
	 * (`flow_register.json`, `credential-providers.json`,
	 * `n8n_workflows.openregister.json`), and asserts every declaring file is
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
		$this->writeDescriptor(file: 'a_register.json', slug: 'flows', version: '1.3.0');
		$this->expectApp();

		$result = $this->service->reimport(appId: 'fixtureapp', slug: 'nothing-here');

		$this->assertSame('failed', $result['outcome']);
		$this->assertStringContainsString('nothing-here', (string)$result['reason']);
	}
}
