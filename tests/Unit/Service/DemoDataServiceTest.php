<?php

namespace Unit\Service;

use OCA\OpenRegister\Service\DemoDataService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * ADR-111 rule 1 — the shipped demo dataset.
 *
 * Every failure path here exists because the caller reports the outcome to an
 * operator who just asked for demo data: "nothing happened" must never be
 * presentable as success, so each one must THROW rather than return empty.
 */
class DemoDataServiceTest extends TestCase {
	private string $appPath;
	private IAppManager $appManager;
	private ContainerInterface $container;

	protected function setUp(): void {
		$this->appPath = sys_get_temp_dir() . '/or-demo-' . uniqid();
		mkdir($this->appPath . '/lib/Settings', 0777, true);

		$this->appManager = $this->createMock(IAppManager::class);
		$this->appManager->method('getAppPath')->willReturn($this->appPath);
		$this->appManager->method('getAppVersion')->willReturn('1.2.3');
		$this->appManager->method('getInstalledApps')->willReturn(['openregister']);

		$this->container = $this->createMock(ContainerInterface::class);
	}

	protected function tearDown(): void {
		$file = $this->descriptor();
		if (is_file($file) === true) {
			unlink($file);
		}

		@rmdir($this->appPath . '/lib/Settings');
		@rmdir($this->appPath . '/lib');
		@rmdir($this->appPath);
	}

	private function descriptor(): string {
		return $this->appPath . '/lib/Settings/openregister_mock_register.json';
	}

	private function service(): DemoDataService {
		return new DemoDataService(
			$this->appManager,
			$this->container,
			$this->createMock(LoggerInterface::class)
		);
	}

	public function testIsAvailableIsFalseWithoutADescriptor(): void {
		$this->assertFalse($this->service()->isAvailable());
	}

	public function testIsAvailableIsTrueWithADescriptor(): void {
		file_put_contents($this->descriptor(), '{}');

		$this->assertTrue($this->service()->isAvailable());
	}

	public function testDecliningIsOfferedEvenWhenNoDatasetShips(): void {
		// 🔴 "NO THANKS" HAS TO BE SAYABLE. Every app in this fleet implemented a
		// `skip-demo-data` action that no manifest step could reach, so the step
		// stayed outstanding and CnAppRoot reopened the wizard over every page
		// unless the operator imported data they did not want.
		$choices = $this->service()->listChoices();

		$this->assertSame(['none'], array_column($choices, 'id'));
		$this->assertNotSame('', $choices[0]['description']);
		$this->assertNotSame('', $choices[0]['icon']);
	}

	public function testTheShippedDatasetIsOfferedWithTheCountItActuallyCarries(): void {
		// The card promises a number, so the number has to come from the file
		// that will be imported rather than from a manifest that could disagree
		// with it.
		file_put_contents(
			$this->descriptor(),
			json_encode(['components' => ['objects' => [['a' => 1], ['b' => 2], ['c' => 3]]]])
		);

		$choices = $this->service()->listChoices();

		$this->assertSame(['none', 'demo'], array_column($choices, 'id'));
		$this->assertSame(3, $choices[1]['objectCount']);
		$this->assertNotSame('', $choices[1]['label']);
		$this->assertNotSame('', $choices[1]['description']);
	}

	public function testAMalformedDescriptorOffersNothingRatherThanAnImportThatCannotRun(): void {
		file_put_contents($this->descriptor(), 'not json at all');

		$this->assertSame(['none'], array_column($this->service()->listChoices(), 'id'));
	}

	public function testTheOfferedDescriptionCarriesNoNumber(): void {
		// 🔴 THE WIZARD TRANSLATES A CARD'S DESCRIPTION BY LITERAL LOOKUP. A
		// count interpolated into the sentence would make it untranslatable and
		// leave a Dutch operator reading English. The count travels separately,
		// as `objectCount`.
		file_put_contents(
			$this->descriptor(),
			json_encode(['components' => ['objects' => [['a' => 1]]]])
		);

		$demo = $this->service()->listChoices()[1];

		$this->assertDoesNotMatchRegularExpression('/\d/', $demo['description']);
	}

	public function testADescriptorWithNoObjectsBlockOffersTheSetWithNoCount(): void {
		// A descriptor can ship schemas and no objects. That is a real dataset
		// with nothing to count, not a broken file, so it stays on offer.
		file_put_contents($this->descriptor(), json_encode(['components' => ['schemas' => []]]));

		$choices = $this->service()->listChoices();

		$this->assertSame(['none', 'demo'], array_column($choices, 'id'));
		$this->assertSame(0, $choices[1]['objectCount']);
	}

	public function testInstallThrowsWhenNoDatasetShips(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessageMatches('/No demo dataset/');

		$this->service()->install();
	}

	public function testInstallThrowsOnInvalidJson(): void {
		file_put_contents($this->descriptor(), 'not json at all');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessageMatches('/not valid JSON/');

		$this->service()->install();
	}

	public function testInstallNamesTheMissingAppWhenOpenRegisterIsAbsent(): void {
		file_put_contents($this->descriptor(), '{"components":{"objects":[]}}');
		$this->appManager = $this->createMock(IAppManager::class);
		$this->appManager->method('getAppPath')->willReturn($this->appPath);
		$this->appManager->method('getInstalledApps')->willReturn([]);

		// 🔴 The message must NAME the missing app. Asking the container for a
		// class from an app that is not installed otherwise surfaces as an error
		// about a class the operator never mentioned.
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessageMatches('/OpenRegister/');

		$this->service()->install();
	}

	public function testInstallCountsTheObjectsInTheFileNotTheImportersReply(): void {
		file_put_contents(
			$this->descriptor(),
			json_encode(['components' => ['objects' => [['a' => 1], ['b' => 2], ['c' => 3]]]])
		);

		// 🔴 THE PARAMETER NAMES ARE THE CONTRACT. install() calls this with
		// named arguments, so a fake whose parameters are named differently
		// fails at the call site rather than validating anything.
		$importer = new class {
			public array $seen = [];

			public function importFromApp(string $appId, array $data, string $version, bool $force): array {
				$this->seen = ['appId' => $appId, 'version' => $version, 'force' => $force];

				// Deliberately reports FEWER than the file holds: an object whose
				// schema does not resolve is skipped, and the operator is told
				// what was ASKED FOR so the discrepancy stays visible.
				return ['registers' => [1], 'schemas' => [1, 1]];
			}
		};
		$this->container->method('get')->willReturn($importer);

		$result = $this->service()->install();

		$this->assertSame(3, $result['objects']);
		$this->assertSame(1, $result['registers']);
		$this->assertSame(2, $result['schemas']);

		// Its own configuration namespace, so a demo import cannot mask — or be
		// masked by — a pending real configuration update.
		$this->assertSame('openregister.demo', $importer->seen['appId']);
		$this->assertTrue($importer->seen['force']);
	}
}
