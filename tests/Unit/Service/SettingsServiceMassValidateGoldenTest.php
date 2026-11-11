<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\OpenRegister\Tests\Unit\Service;

use Exception;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\MassValidationRequest;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\SettingsService;
use OCP\AppFramework\IAppContainer;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use Throwable;
use OCA\OpenRegister\Service\Object\ObjectSaveOptions;

/**
 * Records the mapper calls, log lines and result of massValidate(), per scenario,
 * against fixtures/settings-mass-validate-golden.json. Set UPDATE_GOLDEN=1 to rewrite the fixture.
 * Clock and memory readings are replaced by a fixed marker before comparing.
 *
 * @covers \OCA\OpenRegister\Service\SettingsService
 * @uses   \OCA\OpenRegister\Service\MassValidationRun
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 */
class SettingsServiceMassValidateGoldenTest extends TestCase {

	/**
	 * The request fields a scenario does not set.
	 *
	 * @var array<string, mixed>
	 */
	private const DEFAULTS = ['maxObjects' => 0, 'batchSize' => 1000, 'mode' => 'serial', 'collectErrors' => false];

	private const CLOCK_KEYS = [
		'timestamp', 'duration_seconds', 'objects_per_second', 'start_memory', 'end_memory', 'peak_memory', 'memory_used',
		'peak_percentage', 'actual_used', 'peak_usage', 'batchDuration', 'objectsPerSecond',
		'chunkTime', 'memoryUsage', 'peakMemory', 'duration',
	];

	/**
	 * @var list<array>
	 */
	private array $calls = [];

	/**
	 * Builds the service over a scripted mapper for one scenario.
	 *
	 * @param array $s Scenario settings.
	 *
	 * @return SettingsService
	 */
	private function service(array $s): SettingsService {
		$mapper = $this->createMock(MagicMapper::class);
		$mapper->method('countSearchObjects')->willReturnCallback(function (...$args) use ($s) {
			$this->calls[] = ['count', $args];
			return $s['total'];
		});
		$mapper->method('getAllRegisterSchemaPairs')->willReturn([['registerId' => 1, 'schemaId' => 2]]);
		$mapper->method('searchObjects')->willReturnCallback(function (...$args) use ($s) {
			$this->calls[] = ['searchObjects', $args];
			$objects = [];
			for ($i = 0; $i < $s['perBatch']; $i++) {
				$object = new ObjectEntity();
				$object->setUuid('uuid-' . $i);
				$object->setObject(['n' => $i]);
				$objects[] = $object;
			}
			return $objects;
		});

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('saveObject')->willReturnCallback(function (array|ObjectEntity $object, ObjectSaveOptions $options = new ObjectSaveOptions(), bool $_rbac = true, bool $_multitenancy = true) {
			$args = [$object, $options->extend, $options->target->register, $options->target->schema, $options->uuid, $_rbac, $_multitenancy, $options->policy->silent, $options->policy->validation, $options->uploadedFiles, $options->actor->currentUser, $options->policy->failIfExists, $options->actor->unowned, $options->policy->dedupOverride, $options->actor->owner];
			$this->calls[] = ['save', $args[4], $args[0]];
			if ($args[4] === 'uuid-1') {
				throw new Exception('rejected');
			}
			return new ObjectEntity();
		});

		$container = $this->createMock(IAppContainer::class);
		$container->method('get')->willReturnCallback(function (string $id) use ($mapper, $objectService) {
			$this->calls[] = ['container.get', $id];
			if ($id === ObjectService::class) {
				return $objectService;
			}
			return $mapper;
		});

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'info', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(function ($message, array $context = []) use ($level) {
				unset($context['file'], $context['line']);
				$this->calls[] = ['log.' . $level, $message, $this->fixClock($context)];
			});
		}

		$args = [];
		$given = ['logger' => $logger, 'container' => $container];
		foreach ((new ReflectionMethod(SettingsService::class, '__construct'))->getParameters() as $param) {
			$name = $param->getName();
			if (isset($given[$name]) === true) {
				$args[$name] = $given[$name];
			} elseif ($param->getType() !== null && $param->getType()->isBuiltin() === false) {
				$args[$name] = $this->createMock($param->getType()->getName());
			}
		}
		return new SettingsService(...$args);
	}

	/**
	 * Replace clock and memory readings with a marker.
	 *
	 * @param array $data Log context or result.
	 *
	 * @return array The data with fixed readings.
	 */
	private function fixClock(array $data): array {
		foreach ($data as $key => $value) {
			if (in_array($key, self::CLOCK_KEYS, true) === true) {
				$data[$key] = 'CLOCK';
			} elseif (is_array($value) === true) {
				$data[$key] = $this->fixClock($value);
			}
		}
		return $data;
	}

	/**
	 * Scenario table.
	 *
	 * @return array<string, array>
	 */
	public static function scenarios(): array {
		$base = ['total' => 3, 'perBatch' => 0, 'args' => []];
		return [
			'defaults, empty batches' => [$base],
			'bad mode' => [['args' => ['mode' => 'turbo']] + $base],
			'batch size zero' => [['args' => ['batchSize' => 0]] + $base],
			'batch size too large' => [['args' => ['batchSize' => 5001]] + $base],
			'batch size at limits' => [['args' => ['batchSize' => 5000, 'maxObjects' => 2]] + $base],
			'max objects caps' => [['args' => ['maxObjects' => 2, 'batchSize' => 1]] + $base],
			'max objects one above total' => [['args' => ['maxObjects' => 4, 'batchSize' => 2]] + $base],
			'max objects above total' => [['args' => ['maxObjects' => 9, 'batchSize' => 2]] + $base],
			'nothing to do' => [['total' => 0] + $base],
			'twelve batches' => [['total' => 23, 'args' => ['batchSize' => 2, 'collectErrors' => true]] + $base],
			'serial with objects' => [['perBatch' => 2] + $base],
			'serial with objects, collect errors' => [['perBatch' => 3, 'args' => ['collectErrors' => true]] + $base],
			'parallel with objects' => [['perBatch' => 2, 'args' => ['mode' => 'parallel', 'batchSize' => 2]] + $base],
			'parallel with objects, collect errors' => [['perBatch' => 3, 'args' => ['mode' => 'parallel', 'collectErrors' => true]] + $base],
			'parallel nothing' => [['total' => 0, 'args' => ['mode' => 'parallel']] + $base],
			'parallel with five jobs' => [['total' => 5, 'args' => ['mode' => 'parallel', 'batchSize' => 1]] + $base],
			'parallel with jobs' => [['args' => ['mode' => 'parallel', 'batchSize' => 1]] + $base],
		];
	}

	/**
	 * @dataProvider scenarios
	 */
	public function testMassValidateGolden(array $s): void {
		$service = $this->service($s);
		try {
			$this->calls[] = ['result', $this->fixClock($service->massValidate(request: new MassValidationRequest(...($s['args'] + self::DEFAULTS))))];
		} catch (Throwable $e) {
			$message = $e->getMessage();
			if ($e instanceof \TypeError) {
				$message = 'TypeError';
			}
			$this->calls[] = ['thrown', get_class($e), $message];
		}

		$file = __DIR__ . '/fixtures/settings-mass-validate-golden.json';
		$name = $this->dataName();
		$all = is_file($file) === true ? json_decode(file_get_contents($file), true) : [];
		$actual = json_decode(json_encode($this->calls, JSON_UNESCAPED_UNICODE), true);
		if (getenv('UPDATE_GOLDEN') === '1') {
			$all[$name] = $actual;
			file_put_contents($file, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
		}
		$this->assertSame($all[$name], $actual);
	}
}
