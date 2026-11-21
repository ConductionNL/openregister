<?php

/**
 * Golden test for three app-configuration steps of ImportHandler.
 *
 * Records the outcome and every mapper, app-config and log call of
 * findAppConfigurationByApp() (lookup failure, none, found, up to date or
 * forced), isUnchangedConfiguration() (no app, no version, forced, no or a
 * different stored hash, unchanged) and importMappingComponents() (no or a
 * non-list mappings block, names taken from keys, failing mappings).
 * Re-record with UPDATE_GOLDEN=1.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Configuration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Configuration;

use Exception;
use OCA\OpenRegister\Db\Configuration;
use OCA\OpenRegister\Db\ConfigurationMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Mapping;
use OCA\OpenRegister\Db\MappingMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Configuration\AppImport;
use OCA\OpenRegister\Service\Configuration\ImportHandler;
use OCA\OpenRegister\Service\Configuration\ImportRun;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

class ImportHandlerAppConfigurationStepsGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/import-handler-app-configuration-steps-golden.json';

	/** @var array<int, string> Every recorded call, in order. */
	private array $calls = [];

	/** @var mixed What ConfigurationMapper::findByApp answers; a Throwable is thrown. */
	private mixed $configurations = [];

	/** @var array<string, string> The stored app config values. */
	private array $stored = [];

	private function handler(): ImportHandler {
		$this->calls = [];
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'info', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					unset($context['line']);
					if (isset($context['file']) === true) {
						$context['file'] = basename((string)$context['file']);
					}

					$this->calls[] = $level . ' ' . $message . ' ' . json_encode($context);
				}
			);
		}

		$configurationMapper = $this->createMock(ConfigurationMapper::class);
		$configurationMapper->method('findByApp')->willReturnCallback(
			function (...$args) {
				$this->calls[] = 'configuration.findByApp ' . json_encode($args);
				if ($this->configurations instanceof \Throwable) {
					throw $this->configurations;
				}

				return $this->configurations;
			}
		);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = '') {
				$this->calls[] = 'appConfig.get ' . $key;
				return ($this->stored[$key] ?? $default);
			}
		);
		$mappingMapper = $this->createMock(MappingMapper::class);
		$mappingMapper->method('getSlugToIdMap')->willReturnCallback(
			function (...$args): array {
				$this->calls[] = 'mapping.slugToIdMap ' . json_encode($args);
				return [];
			}
		);
		$mappingMapper->method('createFromArray')->willReturnCallback(
			function (array $data): Mapping {
				$this->calls[] = 'mapping.create ' . json_encode($data);
				if (($data['name'] ?? '') === 'broken') {
					throw new Exception('mapping refused');
				}

				$mapping = new Mapping();
				$mapping->setId(count($this->calls));
				return $mapping;
			}
		);

		return new ImportHandler(
			catalog: new \OCA\OpenRegister\Db\SchemaCatalog(
				registers: $this->createMock(RegisterMapper::class),
				schemas: $this->createMock(SchemaMapper::class),
				tables: $this->createMock(MagicMapper::class)
			),
			records: new \OCA\OpenRegister\Service\Configuration\ConfigurationImportRecords(
				configurations: $configurationMapper,
				mappings: $mappingMapper,
				appConfig: $appConfig
			),
			schemaSteps: new \OCA\OpenRegister\Service\Configuration\SchemaImportSteps(),
			logger:              $logger,
			appDataPath:         '/tmp',
			objectService:       $this->createMock(ObjectService::class)
		);
	}//end handler()

	private function call(ImportHandler $handler, string $method, array $args): mixed {
		return (new ReflectionMethod(ImportHandler::class, $method))->invokeArgs($handler, $args);
	}//end call()

	private static function configuration(int $id, ?string $version): Configuration {
		$configuration = new Configuration();
		$configuration->setId($id);
		$configuration->setVersion($version);
		return $configuration;
	}//end configuration()

	private function configurationCases(): array {
		$cases = [
			'lookup fails' => [new Exception('no table'), '1.0.0', false],
			'none stored' => [[], '1.0.0', false],
			'found, newer import' => [[self::configuration(5, '1.0.0'), self::configuration(6, '9.0.0')], '2.0.0', false],
			'found, same version' => [[self::configuration(5, '2.0.0')], '2.0.0', false],
			'found, older import' => [[self::configuration(5, '2.0.0')], '1.0.0', false],
			'found, forced' => [[self::configuration(5, '2.0.0')], '1.0.0', true],
			'found, no stored version' => [[self::configuration(5, null)], '0.0.0', false],
		];
		$out = [];
		foreach ($cases as $name => [$configurations, $version, $force]) {
			$handler = $this->handler();
			$this->configurations = $configurations;
			$found = $this->call($handler, 'findAppConfigurationByApp', [new AppImport(appId: 'zaakapp', data: [], version: $version, force: $force)]);
			$out[$name] = ['found' => $found?->getId(), 'calls' => $this->calls];
		}

		return $out;
	}//end configurationCases()

	private function unchangedCases(): array {
		$run = function (?string $appId, ?string $version, bool $force, string $hash): ImportRun {
			$run = new ImportRun(data: [], configuration: new Configuration());
			$run->appId = $appId;
			$run->version = $version;
			$run->force = $force;
			$run->definitionHash = $hash;
			return $run;
		};
		$cases = [
			'no app' => [$run(null, '1.0.0', false, 'h1'), ['imported_config__hash' => 'h1']],
			'no version' => [$run('zaakapp', null, false, 'h1'), []],
			'forced' => [$run('zaakapp', '1.0.0', true, 'h1'), []],
			'nothing stored' => [$run('zaakapp', '1.0.0', false, 'h1'), []],
			'empty stored hash, empty definition hash' => [$run('zaakapp', '1.0.0', false, ''), ['imported_config_zaakapp_hash' => '']],
			'different hash' => [
				$run('zaakapp', '1.0.0', false, 'h2'),
				['imported_config_zaakapp_hash' => 'h1', 'imported_config_zaakapp_version' => '0.9.0'],
			],
			'unchanged' => [$run('zaakapp', '1.0.0', false, 'h1'), ['imported_config_zaakapp_hash' => 'h1', 'imported_config_zaakapp_version' => '0.9.0']],
		];
		$out = [];
		foreach ($cases as $name => [$importRun, $stored]) {
			$handler = $this->handler();
			$this->stored = $stored;
			$out[$name] = ['unchanged' => $this->call($handler, 'isUnchangedConfiguration', [$importRun]), 'calls' => $this->calls];
		}

		return $out;
	}//end unchangedCases()

	private function mappingCases(): array {
		$cases = [
			'no mappings' => ['components' => []],
			'mappings not a list' => ['components' => ['mappings' => 'x']],
			'named by key, own name kept, failure' => ['components' => ['mappings' => [
				'adres' => ['slug' => 'adres'],
				'own' => ['name' => 'Eigen', 'slug' => 'own'],
				'broken' => ['slug' => 'broken'],
				3 => ['slug' => 'numbered'],
			]]],
		];
		$out = [];
		foreach ($cases as $name => $data) {
			$handler = $this->handler();
			$importRun = new ImportRun(data: $data, configuration: new Configuration());
			$importRun->result = ['mappings' => []];
			$this->call($handler, 'importMappingComponents', [$importRun]);
			$out[$name] = ['mappings' => count($importRun->result['mappings']), 'slugsAndIdsMap' => $importRun->slugsAndIdsMap, 'calls' => $this->calls];
		}

		return $out;
	}//end mappingCases()

	public function testAppConfigurationStepsMatchGolden(): void {
		$actual = json_decode(
			json_encode(
				[
					'findAppConfigurationByApp' => $this->configurationCases(),
					'isUnchangedConfiguration' => $this->unchangedCases(),
					'importMappingComponents' => $this->mappingCases(),
				]
			),
			true
		);

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$expected = json_decode((string)file_get_contents(self::FIXTURE), true);
		foreach ($expected as $step => $cases) {
			foreach ($cases as $name => $outcome) {
				$this->assertSame($outcome, $actual[$step][$name] ?? null, $step . ': ' . $name);
			}
		}

		$this->assertSame($expected, $actual);
	}//end testAppConfigurationStepsMatchGolden()
}//end class
