<?php

/**
 * Characterisation: how SeedVocabularyRegister seeds its TOOI fixtures — the
 * missing, invalid, failing and imported fixture, the lines it prints and logs,
 * and that every import runs as a system operation and nothing else does.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/skos-concept-registers/specs/skos-concept-registers/spec.md#skos-003
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Repair;

use OCA\OpenRegister\Repair\RegisterDescriptorImport;
use OCA\OpenRegister\Repair\SeedVocabularyRegister;
use OCA\OpenRegister\Service\ConfigurationService;
use OCA\OpenRegister\Service\SystemOperationContext;
use OCA\OpenRegister\Service\VocabularyImportService;
use OCP\App\IAppManager;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Runs the step over a temporary app path and records every line and import.
 */
class SeedVocabularyFixturesGoldenTest extends TestCase {

	/**
	 * The temporary app path.
	 *
	 * @var string
	 */
	private string $root;

	/**
	 * Every printed, logged and imported thing, in order.
	 *
	 * @var array<int, string>
	 */
	private array $calls = [];

	/**
	 * Lay out an empty app path.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->root = sys_get_temp_dir() . '/or-vocab-' . bin2hex(random_bytes(4));
		mkdir($this->root . '/lib/Resources/Vocabulary', 0777, true);
	}//end setUp()

	/**
	 * Remove the app path.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach (glob($this->root . '/lib/Resources/Vocabulary/*') as $file) {
			unlink($file);
		}

		rmdir($this->root . '/lib/Resources/Vocabulary');
		rmdir($this->root . '/lib/Resources');
		rmdir($this->root . '/lib');
		rmdir($this->root);
		parent::tearDown();
	}//end tearDown()

	/**
	 * Run the step; the register descriptor is absent, so only the fixtures do work.
	 *
	 * @param callable $import What importJsonLd() does with a fixture.
	 *
	 * @return array<int, string> The call log, with the app path written as ROOT.
	 */
	private function seed(callable $import): array {
		$this->calls = [];

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppPath')->willReturnCallback(
			function (string $app): string {
				$this->calls[] = 'getAppPath ' . $app;
				return $this->root;
			}
		);

		$importer = $this->createMock(VocabularyImportService::class);
		$importer->method('importJsonLd')->willReturnCallback(
			function (array $jsonLd) use ($import): array {
				$this->calls[] = 'import ' . json_encode($jsonLd) . ' system=' . var_export(SystemOperationContext::isActive(), true);
				return $import($jsonLd);
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message): void {
				$this->calls[] = 'log.warning ' . $message;
			}
		);

		$output = $this->createMock(IOutput::class);
		foreach (['info', 'warning'] as $level) {
			$output->method($level)->willReturnCallback(
				function (string $message) use ($level): void {
					$this->calls[] = 'output.' . $level . ' ' . $message . ' system=' . var_export(SystemOperationContext::isActive(), true);
				}
			);
		}

		$step = new SeedVocabularyRegister(
			descriptors: new RegisterDescriptorImport(
				configurationService: $this->createMock(ConfigurationService::class),
				appManager: $appManager,
				logger: $logger
			),
			importService: $importer
		);
		$step->run($output);

		return array_map(fn (string $call): string => str_replace($this->root, 'ROOT', $call), $this->calls);
	}//end seed()

	/**
	 * Write one fixture.
	 *
	 * @param string $name    The fixture file name.
	 * @param string $content Its content.
	 *
	 * @return void
	 */
	private function fixture(string $name, string $content): void {
		file_put_contents($this->root . '/lib/Resources/Vocabulary/' . $name, $content);
	}//end fixture()

	/**
	 * A missing first fixture and an invalid second one are each warned about and never imported.
	 *
	 * @return void
	 */
	public function testMissingAndInvalidFixturesAreWarnedAbout(): void {
		$this->fixture('tooi-documenthandelingen.jsonld', 'not json');

		$this->assertSame(
			[
				'getAppPath openregister',
				'output.warning Vocabulary register descriptor not found: ROOT/lib/Settings/vocabulary_register.json system=false',
				'getAppPath openregister',
				'output.warning TOOI vocabulary fixture not found: ROOT/lib/Resources/Vocabulary/tooi-informatiecategorieen.jsonld system=false',
				'output.warning TOOI vocabulary fixture is not valid JSON: ROOT/lib/Resources/Vocabulary/tooi-documenthandelingen.jsonld system=false',
			],
			$this->seed(static fn (array $jsonLd): array => [])
		);
	}//end testMissingAndInvalidFixturesAreWarnedAbout()

	/**
	 * A failing import is logged and reported; the next fixture is still imported, as a system operation.
	 *
	 * @return void
	 */
	public function testAFailingImportDoesNotStopTheNextFixture(): void {
		$this->fixture('tooi-informatiecategorieen.jsonld', '{"n":1}');
		$this->fixture('tooi-documenthandelingen.jsonld', '{"n":2}');

		$this->assertSame(
			[
				'getAppPath openregister',
				'output.warning Vocabulary register descriptor not found: ROOT/lib/Settings/vocabulary_register.json system=false',
				'getAppPath openregister',
				'import {"n":1} system=true',
				'log.warning [SeedVocabularyRegister] fixture seed failed for ROOT/lib/Resources/Vocabulary/tooi-informatiecategorieen.jsonld: index offline',
				'output.warning TOOI vocabulary fixture seed skipped (/lib/Resources/Vocabulary/tooi-informatiecategorieen.jsonld): index offline system=false',
				'import {"n":2} system=true',
				'output.info Seeded vocabulary scheme urn:two (created=1, updated=2, unchanged=3, deprecated=4) system=false',
			],
			$this->seed(
				static function (array $jsonLd): array {
					if ($jsonLd['n'] === 1) {
						throw new RuntimeException('index offline');
					}

					return ['scheme' => 'urn:two', 'created' => 1, 'updated' => 2, 'unchanged' => 3, 'deprecated' => 4];
				}
			)
		);
	}//end testAFailingImportDoesNotStopTheNextFixture()
}//end class
