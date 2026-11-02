<?php

/**
 * Tests for a set that requires a register OpenRegister ships only as a mock.
 *
 * `brp` and `kvk` are mock descriptors (`x-openregister.type: mock`), so the
 * real-configuration re-import finds no descriptor for them and a fresh
 * instance refused the zuiddrecht set. These tests read the shipped cast and
 * OpenRegister's own lib/Settings through the real catalogue, registers and
 * descriptor service; only the database and the configuration import are fakes.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ExampleSet
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

use OCA\OpenRegister\AppHost\AppContainerLocator;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\AppRegisterImportRunner;
use OCA\OpenRegister\Service\Configuration\AppImportJobRecorder;
use OCA\OpenRegister\Service\ConfigurationService;
use OCA\OpenRegister\Service\CountedRegisterImport;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetException;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetRegisters;
use OCA\OpenRegister\Service\RegisterDescriptorService;
use OCA\OpenRegister\Service\ShippedBaseline\ShippedAuthorizationDecision;
use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetCatalogue
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetPrerequisites
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetRegisters
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-set-imports-the-openregister-registers-it-requires-req-exs-009
 * @uses \OCA\OpenRegister\AppHost\Service\RegisterDocumentLoader
 * @uses \OCA\OpenRegister\Db\Register
 * @uses \OCA\OpenRegister\Service\AppRegisterImportRunner
 * @uses \OCA\OpenRegister\Service\CountedRegisterImport
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetAppConfig
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetChanges
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetFiles
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetInstaller
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetInstance
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetOrder
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetPaths
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetPeople
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetProof
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetRecord
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetRemover
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetUserPreferences
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetValues
 * @uses \OCA\OpenRegister\Service\RegisterDescriptorService
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetEngineKinds
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetEngineRows
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetTasks
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetViews
 */
class ExampleSetMockRegisterImportTest extends ExampleSetTestCase {
	/** @var array<string, string> Registers on the fake instance, slug to version. */
	private array $instance = [];

	/** @var array<string, array<int, array<string, mixed>>> Recorded import jobs per configuration id. */
	private array $recorded = [];

	/** @var array<int, string> The configuration ids the imports ran under. */
	private array $importedAs = [];

	/**
	 * The real registers service over OpenRegister's own lib/Settings.
	 *
	 * @return ExampleSetRegisters
	 */
	private function realRegisters(): ExampleSetRegisters {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppPath')->willReturn(dirname(__DIR__, 4));

		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('findAll')->willReturnCallback(function (): array {
			$registers = [];
			foreach ($this->instance as $slug => $version) {
				$register = new Register();
				$register->setSlug($slug);
				$register->setVersion($version);
				$registers[] = $register;
			}

			return $registers;
		});

		$configuration = $this->createMock(ConfigurationService::class);
		$configuration->method('importFromApp')->willReturnCallback(
			function (string $appId, array $data, string $version, bool $force = false): array {
				$this->importedAs[] = $appId;
				foreach (array_keys((array) ($data['components']['registers'] ?? [])) as $slug) {
					$this->instance[strtolower((string) $slug)] = $version;
				}

				$jobId = 'job-'.count($this->importedAs);
				$this->recorded[$appId][] = ['jobId' => $jobId, 'version' => $version, 'created' => 1, 'importedAt' => ''];

				// As ImportHandler does: the result names the job the import ran under.
				return ['objects' => array_keys((array) ($data['components']['objects'] ?? [])), 'skipped' => [], 'importJobId' => $jobId];
			}
		);

		$descriptors = new RegisterDescriptorService(
			$appManager,
			$registerMapper,
			$configuration,
			$this->createMock(LoggerInterface::class),
			new CountedRegisterImport(
				registerMapper: $registerMapper,
				schemaMapper: $this->createMock(SchemaMapper::class),
				decision: new ShippedAuthorizationDecision()
			),
			new AppRegisterImportRunner(
				appManager: $appManager,
				containers: $this->createMock(AppContainerLocator::class),
				configurationService: $configuration
			)
		);

		$jobs = $this->createMock(AppImportJobRecorder::class);
		$jobs->method('jobs')->willReturnCallback(fn (string $appId): array => ($this->recorded[$appId] ?? []));

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(fn (string $id): object => match ($id) {
			RegisterDescriptorService::class => $descriptors,
			AppImportJobRecorder::class      => $jobs,
		});

		return new ExampleSetRegisters(container: $container);
	}

	public function testEveryRegisterTheShippedZuiddrechtSetRequiresImportsOnAFreshInstance(): void {
		$this->apps['openregister'] = dirname(__DIR__, 4);
		$plan      = $this->catalogue->gather(set: 'zuiddrecht');
		$registers = $this->realRegisters();

		$this->assertSame(expected: ['brp', 'kvk', 'destruction-lists', 'public-space'], actual: array_keys($plan['requires']));
		$this->assertSame(expected: [true, true, false, true], actual: array_column($plan['requires'], 'mock'));
		foreach ($plan['requires'] as $slug => $required) {
			$import = match ($required['mock']) {
				true  => $registers->importMock(slug: $slug),
				false => $registers->import(slug: $slug),
			};

			$this->assertSame('', $import['error'], $slug.' imports from what the set declares');
			$this->assertArrayHasKey($slug, $this->instance);
		}

		$this->assertSame(
			expected: ['openregister.mock', 'openregister.mock', 'openregister.destruction-lists', 'openregister.mock'],
			actual: $this->importedAs
		);
	}

	public function testARegisterShippedOnlyAsAMockIsNotImportedAsTheRealConfiguration(): void {
		$import = $this->realRegisters()->import(slug: 'brp');

		$this->assertStringContainsString('declares no register "brp"', $import['error']);
		$this->assertSame([], $import['jobs']);
		$this->assertSame([], $this->importedAs);
	}

	public function testARequirementWithoutARegisterSlugRefusesTheSet(): void {
		$cast             = $this->castFile();
		$cast['requires'] = [['mock' => true]];
		$this->app(app: 'openregister', fragment: $cast);

		$this->expectException(ExampleSetException::class);
		$this->expectExceptionMessage('requires 0 needs a register slug, or {"register": "<slug>", "mock": true}.');
		$this->catalogue->gather(set: 'demo');
	}

	public function testAMockRequirementIsImportedFromTheMockAndRecorded(): void {
		$this->store->offer(register: 'brp', schema: 'person');
		$this->registers->mockImportable['brp'] = true;
		$cast             = $this->castFile(objects: [['key' => 'brp-sanne', 'register' => 'brp', 'schema' => 'person', 'data' => ['bsn' => '{{cast:sanne.bsn}}']]]);
		$cast['requires'] = [['register' => 'brp', 'mock' => true]];
		$this->app(app: 'openregister', fragment: $cast);

		$report = $this->installer->install(plan: $this->catalogue->gather(set: 'demo'), today: $this->today());

		$this->assertTrue($report['ok'], (string) json_encode($report));
		$this->assertSame(['brp' => 'imported'], $report['registers']);
		$this->assertSame(1, $this->registers->imports);
		$this->assertSame(1, $this->remover->remove(set: 'demo')['removed']['register imports']);
	}
}
