<?php

/**
 * Golden test for four import steps of ImportHandler.
 *
 * Records the outcome and every mapper, magic-mapper and log call of
 * importRegisterComponents() (schema linking, inherited register type, failed
 * registers), resolveImportedObjectConfigurations() (object and array forms,
 * items), listedSeedObjects() (both locations, de-duplication) and
 * ensureMagicTablesForImportedSchemas() (every owning register, failures).
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

use OCA\OpenRegister\Db\Configuration;
use OCA\OpenRegister\Db\ConfigurationMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MappingMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Configuration\ImportHandler;
use OCA\OpenRegister\Service\Configuration\ImportRun;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use ReflectionProperty;

class ImportHandlerComponentStepsGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/import-handler-component-steps-golden.json';

	/** @var array<int, string> Every recorded call, in order. */
	private array $calls = [];

	/** @var ImportHandler The handler under test. */
	private ImportHandler $handler;

	private function handler(): ImportHandler {
		$this->calls = [];
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$registerMapper = $this->createMock(RegisterMapper::class);
		$magicMapper = $this->createMock(MagicMapper::class);
		$logger = $this->createMock(LoggerInterface::class);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('');

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

		$registerMapper->method('find')->willReturnCallback(
			function ($id, ...$rest) {
				$this->calls[] = 'register.find ' . json_encode([$id, $rest]);
				if ((int)$id === 66) {
					throw new \RuntimeException('register 66 broken');
				}

				if (is_int($id) === true) {
					return $this->register((int)$id, 'r' . $id);
				}

				throw new DoesNotExistException('not found');
			}
		);
		$registerMapper->method('createFromArray')->willReturnCallback(
			function (array $data) {
				$this->calls[] = 'register.create ' . json_encode($data);
				if (($data['slug'] ?? '') === 'boom') {
					throw new \RuntimeException('create refused');
				}

				return $this->register(50, (string)$data['slug']);
			}
		);
		$registerMapper->method('update')->willReturnCallback(
			function (Register $register) {
				$this->calls[] = 'register.update ' . $register->getSlug();
				return $register;
			}
		);
		$registerMapper->method('getSlugToIdMap')->willReturn([]);
		$registerMapper->method('getAllRegisterIdsWithSchema')->willReturnCallback(
			function (int $schemaId) {
				$this->calls[] = 'register.idsWithSchema ' . $schemaId;
				if ($schemaId === 99) {
					throw new \Exception('ids lookup failed');
				}

				return ($schemaId === 41 ? [7, '66', 8] : []);
			}
		);
		$schemaMapper->method('getSlugToIdMap')->willReturn([]);
		$schemaMapper->method('find')->willThrowException(new DoesNotExistException('not found'));
		$mappingMapper = $this->createMock(MappingMapper::class);
		$mappingMapper->method('getSlugToIdMap')->willReturn([]);

		$magicMapper->method('ensureTableForRegisterSchema')->willReturnCallback(
			function (Register $register, Schema $schema) {
				$this->calls[] = 'magic.ensure ' . $register->getId() . '/' . $schema->getId();
				if ($register->getId() === 8) {
					throw new \Exception('ddl refused');
				}

				return true;
			}
		);

		$handler = new ImportHandler(
			schemaMapper:        $schemaMapper,
			registerMapper:      $registerMapper,
			objectEntityMapper:  $this->createMock(MagicMapper::class),
			configurationMapper: $this->createMock(ConfigurationMapper::class),
			mappingMapper:       $mappingMapper,
			appConfig:           $appConfig,
			logger:              $logger,
			appDataPath:         '/tmp',
			objectService:       $this->createMock(ObjectService::class)
		);
		$handler->setMagicMapper($magicMapper);
		return $handler;
	}//end handler()

	private function register(int $id, string $slug): Register {
		$register = new Register();
		$register->hydrate(['slug' => $slug, 'version' => '1.0.0']);
		$register->setId($id);
		return $register;
	}//end register()

	private function schema(?int $id, string $slug): Schema {
		$schema = new Schema();
		$schema->hydrate(['slug' => $slug, 'title' => $slug, 'version' => '1.0.0', 'properties' => []]);
		if ($id !== null) {
			$schema->setId($id);
		}

		return $schema;
	}//end schema()

	private function call(string $method, array $args): mixed {
		return (new ReflectionMethod(ImportHandler::class, $method))->invokeArgs($this->handler, $args);
	}//end call()

	private function maps(): array {
		$read = fn (string $name): array => array_map(
			fn ($entity) => ($entity instanceof Register || $entity instanceof Schema) ? get_class($entity) . '#' . $entity->getId() : $entity,
			(new ReflectionProperty(ImportHandler::class, $name))->getValue($this->handler)
		);
		return ['registersMap' => $read('registersMap'), 'schemasMap' => $read('schemasMap')];
	}//end maps()

	private function registerCase(array $data): array {
		$this->handler = $this->handler();
		(new ReflectionProperty(ImportHandler::class, 'schemasMap'))->setValue(
			$this->handler,
			['page' => $this->schema(3, 'page'), 'menu' => $this->schema(4, 'menu')]
		);
		$run = new ImportRun(data: $data, configuration: new Configuration());
		$run->appId = 'app';
		$run->result = ['registers' => [], 'skipped' => ['registers' => 0], 'failed' => ['schemas' => [['slug' => 'gone', 'key' => 'gone', 'error' => 'bad shape']]]];
		$this->call('importRegisterComponents', [$run]);
		return [
			'registers' => array_map(fn (Register $r) => $r->getSlug(), $run->result['registers']),
			'skipped' => $run->result['skipped'],
			'maps' => $this->maps(),
			'calls' => $this->calls,
		];
	}//end registerCase()

	private function registerCases(): array {
		return [
			'no registers' => $this->registerCase(['components' => []]),
			'registers not a list' => $this->registerCase(['components' => ['registers' => 'x']]),
			'linked, typed and failing registers' => $this->registerCase([
				'x-openregister' => ['type' => 'mock'],
				'components' => ['registers' => [
					'Pub' => ['slug' => 'pub', 'schemas' => ['page', 'gone', 'menu']],
					'own' => ['slug' => 'own', 'type' => 'real', 'schemas' => 'not-a-list'],
					'boom' => ['slug' => 'boom'],
				]],
			]),
			'empty parent type' => $this->registerCase([
				'x-openregister' => ['type' => ''],
				'components' => ['registers' => ['pub' => ['slug' => 'pub', 'schemas' => null]]],
			]),
		];
	}//end registerCases()

	private function configurationCases(): array {
		$this->handler = $this->handler();
		(new ReflectionProperty(ImportHandler::class, 'schemasMap'))->setValue($this->handler, ['page' => $this->schema(3, 'page')]);
		(new ReflectionProperty(ImportHandler::class, 'registersMap'))->setValue($this->handler, ['pub' => $this->register(9, 'pub')]);
		$config = ['register' => 'pub', 'schema' => 'page'];
		$cases = [
			'plain property' => ['type' => 'string'],
			'array configuration' => ['type' => 'object', 'objectConfiguration' => $config],
			'object configuration' => ['type' => 'object', 'objectConfiguration' => (object)$config],
			'scalar configuration' => ['type' => 'object', 'objectConfiguration' => 'pub'],
			'items null' => ['type' => 'array', 'items' => null],
			'items scalar' => ['type' => 'array', 'items' => 'string'],
			'items array configuration' => ['type' => 'array', 'items' => ['objectConfiguration' => $config]],
			'items object with object configuration' => ['type' => 'array', 'items' => (object)['objectConfiguration' => (object)$config]],
			'items without configuration' => ['type' => 'array', 'objectConfiguration' => $config, 'items' => ['type' => 'string']],
		];
		$out = [];
		foreach ($cases as $name => $property) {
			$this->calls = [];
			$out[$name] = ['result' => json_decode(json_encode($this->call('resolveImportedObjectConfigurations', [$property])), true), 'calls' => $this->calls];
		}

		return $out;
	}//end configurationCases()

	private function seedCases(): array {
		$this->handler = $this->handler();
		$a = ['@self' => ['uuid' => 'u-1'], 'n' => 1];
		$b = ['@self' => ['register' => 'pub', 'schema' => 'page', 'slug' => 'home'], 'n' => 2];
		$c = ['title' => 'no self', 'n' => 3];
		$cases = [
			'nothing listed' => [],
			'components only' => ['components' => ['objects' => [$a, $b, 'skip', $c, $c]]],
			'both, deduplicated' => [
				'components' => ['objects' => [$a, $b]],
				'objects' => [$b, ['@self' => ['uuid' => 'u-1'], 'n' => 4], $c, ['@self' => ['uuid' => 'u-2']]],
			],
			'scalar buckets' => ['components' => ['objects' => 'x'], 'objects' => 5],
		];
		$out = [];
		foreach ($cases as $name => $data) {
			$out[$name] = $this->call('listedSeedObjects', [$data]);
		}

		return $out;
	}//end seedCases()

	private function magicCases(): array {
		$out = [];
		$cases = [
			'no schemas' => [],
			'unusable entries' => ['x', $this->schema(null, 'noid')],
			'owning registers, failures and lookup failure' => [$this->schema(41, 'page'), $this->schema(42, 'lonely'), $this->schema(99, 'broken')],
		];
		foreach ($cases as $name => $schemas) {
			$this->handler = $this->handler();
			$this->call('ensureMagicTablesForImportedSchemas', [$schemas]);
			$out[$name] = $this->calls;
		}

		$this->handler = $this->handler();
		(new ReflectionProperty(ImportHandler::class, 'magicMapper'))->setValue($this->handler, null);
		$this->call('ensureMagicTablesForImportedSchemas', [[$this->schema(41, 'page')]]);
		$out['no magic mapper'] = $this->calls;
		return $out;
	}//end magicCases()

	public function testComponentStepsMatchGolden(): void {
		$actual = [
			'importRegisterComponents' => $this->registerCases(),
			'resolveImportedObjectConfigurations' => $this->configurationCases(),
			'listedSeedObjects' => $this->seedCases(),
			'ensureMagicTablesForImportedSchemas' => $this->magicCases(),
		];

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$expected = json_decode((string)file_get_contents(self::FIXTURE), true);
		$actual = json_decode(json_encode($actual), true);
		foreach ($expected as $step => $cases) {
			foreach ($cases as $name => $outcome) {
				$this->assertSame($outcome, $actual[$step][$name] ?? null, $step . ': ' . $name);
			}
		}

		$this->assertSame($expected, $actual);
	}//end testComponentStepsMatchGolden()
}//end class
