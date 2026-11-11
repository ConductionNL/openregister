<?php

/**
 * Golden test for MagicFacetHandler::batchResolveUuidLabels().
 *
 * Runs sequences of label lookups against one handler, so the in-memory field
 * cache, the distributed cache and the object name cache are exercised in
 * every order, and records the labels, the cache traffic, the statistics and
 * the log lines. Re-record with UPDATE_GOLDEN=1.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use OCA\OpenRegister\Db\MagicMapper\FacetLabelLookup;
use OCA\OpenRegister\Db\RegisterSchema;
use OCA\OpenRegister\Db\MagicMapper\MagicFacetHandler;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Kernel\Contracts\ObjectNameCacheInterface;
use OCP\ICache;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;

/**
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicFacetHandler
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicFacetFieldLabel
 * @uses   \OCA\OpenRegister\Db\MagicMapper\FacetLabelLookup
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Db\Register
 */
class MagicFacetHandlerUuidLabelsGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/facet-uuid-labels-golden.json';

	private const NAMES = ['u1' => 'One', 'u2' => 'Two', 'u3' => 'Three', 'u4' => 'Four'];

	private array $events = [];

	private static function scenarios(): array {
		$calls = [
			[['u1', 'u2'], 'f'],
			[['u1', 'u2'], 'f'],
			[['u1', 'u3'], 'f'],
			[['u4', 'u9'], 'g'],
			[['u4'], 'g'],
			[[], 'f'],
		];
		return [
			'object names only' => ['none', true, $calls],
			'distributed empty' => ['empty', true, $calls],
			'distributed full' => ['full', true, $calls],
			'distributed partial' => ['partial', true, $calls],
			'distributed not an array' => ['scalar', true, $calls],
			'distributed get throws' => ['get-throws', true, $calls],
			'distributed set throws' => ['set-throws', true, $calls],
			'no object name cache' => ['empty', false, $calls],
			'nothing at all' => ['none', false, $calls],
		];
	}//end scenarios()

	public function testUuidLabelsMatchGolden(): void {
		$actual = [];
		foreach (self::scenarios() as $name => [$dist, $names, $calls]) {
			$handler = $this->makeHandler(dist: $dist, names: $names);
			$method = new ReflectionMethod(MagicFacetHandler::class, 'batchResolveUuidLabels');
			$method->setAccessible(true);
			$schema = new Schema();
			$schema->setId(2);
			$register = new Register();
			$register->setId(1);
			$steps = [];
			foreach ($calls as [$uuids, $field]) {
				$this->events = [];
				$labels = $method->invoke($handler, new FacetLabelLookup($uuids, $field), new RegisterSchema($register, $schema));
				$steps[] = [
					'labels' => $labels,
					'events' => $this->events,
					'stats' => $this->property(handler: $handler, name: 'cacheStats'),
				];
			}

			$steps[] = [
				'fieldLabelCache' => $this->property(handler: $handler, name: 'fieldLabelCache'),
				'warmedFields' => $this->property(handler: $handler, name: 'warmedFields'),
				'uuidLabelCache' => $this->property(handler: $handler, name: 'uuidLabelCache'),
			];
			$actual[$name] = $steps;
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$expected = json_decode((string) file_get_contents(self::FIXTURE), true);
		foreach ($expected as $name => $outcome) {
			$this->assertSame($outcome, $actual[$name] ?? null, $name);
		}

		$this->assertSame(array_keys($expected), array_keys($actual));
	}//end testUuidLabelsMatchGolden()

	private function property(MagicFacetHandler $handler, string $name): mixed {
		$property = new \ReflectionProperty(MagicFacetHandler::class, $name);
		$property->setAccessible(true);
		return $property->getValue($handler);
	}//end property()

	private function makeHandler(string $dist, bool $names): MagicFacetHandler {
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'debug', 'info', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					unset($context['file'], $context['line'], $context['time_ms']);
					$this->events[] = ['log' => $level, 'message' => $message, 'context' => $context];
				}
			);
		}

		$cache = null;
		if ($dist !== 'none') {
			$cache = $this->createMock(ICache::class);
			$cache->method('get')->willReturnCallback(
				function (string $key) use ($dist): mixed {
					$this->events[] = ['distGet' => $key];
					return match ($dist) {
						'full' => self::NAMES,
						'partial' => ['u1' => 'Cached one'],
						'scalar' => 'labels',
						'get-throws' => throw new \Exception('cache down'),
						default => null,
					};
				}
			);
			$cache->method('set')->willReturnCallback(
				function (string $key, mixed $value, int $ttl) use ($dist): bool {
					$this->events[] = ['distSet' => [$key, $value, $ttl]];
					if ($dist === 'set-throws') {
						throw new \Exception('cache full');
					}

					return true;
				}
			);
		}

		$objectNames = null;
		if ($names === true) {
			$objectNames = $this->createMock(ObjectNameCacheInterface::class);
			$objectNames->method('getMultipleObjectNames')->willReturnCallback(
				function (array $uuids): array {
					$this->events[] = ['names' => $uuids];
					return array_intersect_key(self::NAMES, array_flip($uuids));
				}
			);
		}

		$reflection = new ReflectionClass(MagicFacetHandler::class);
		$handler = $reflection->newInstanceWithoutConstructor();
		$values = [
			'logger' => $logger,
			'distLabelCache' => $cache,
			'cacheHandler' => $objectNames,
			'cacheFactory' => null,
			'container' => null,
			'lazyResolved' => true,
		];
		foreach ($values as $prop => $value) {
			$property = $reflection->getProperty($prop);
			$property->setAccessible(true);
			$property->setValue($handler, $value);
		}

		return $handler;
	}//end makeHandler()
}//end class
