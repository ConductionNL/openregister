<?php

/**
 * Golden test for CacheSettingsHandler::getCacheStats.
 *
 * Records the statistics payload for each way the object cache handler and the
 * distributed cache can answer, in fixtures/cache-settings-handler-stats-golden.json.
 * Set UPDATE_GOLDEN=1 to rewrite it.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Settings;

use Exception;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\Object\CacheHandlerInterface;
use OCA\OpenRegister\Service\Schemas\FacetResultCacheClearer;
use OCA\OpenRegister\Service\Schemas\SchemaCacheHandler;
use OCA\OpenRegister\Service\Settings\CacheSettingsHandler;
use OCA\OpenRegister\Service\Settings\SchemaCacheClearers;
use OCP\AppFramework\IAppContainer;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Settings\CacheSettingsHandler
 */
class CacheSettingsHandlerStatsGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/cache-settings-handler-stats-golden.json';

	/**
	 * Scenarios: [object cache source (none|direct|container|container-fails|throws), stats, distributed available].
	 *
	 * @return array<string, array>
	 */
	public static function scenarios(): array {
		$full = [
			'entries' => 3, 'hits' => 2, 'requests' => 8, 'memoryUsage' => 120,
			'name_cache_size' => 4, 'name_hit_rate' => 50.5, 'name_hits' => 6, 'name_misses' => 7, 'name_warmups' => 1,
		];
		return [
			'no handler' => ['none', [], true],
			'no handler, no distributed' => ['none', [], false],
			'direct full stats' => ['direct', $full, true],
			'direct partial stats' => ['direct', ['hits' => 5, 'name_hits' => 2], false],
			'direct zero requests' => ['direct', ['hits' => 5, 'requests' => 0], true],
			'from container' => ['container', $full, true],
			'container fails' => ['container-fails', $full, true],
			'handler throws' => ['throws', $full, true],
		];
	}

	/**
	 * @dataProvider scenarios
	 */
	public function testCacheStatsGolden(string $source, array $stats, bool $distributed): void {
		$handler = $this->createMock(CacheHandlerInterface::class);
		if ($source === 'throws') {
			$handler->method('getStats')->willThrowException(new Exception('down'));
		} else {
			$handler->method('getStats')->willReturn($stats);
		}

		$container = null;
		if ($source === 'container' || $source === 'container-fails') {
			$container = $this->createMock(IAppContainer::class);
			if ($source === 'container') {
				$container->method('get')->with(CacheHandler::class)->willReturn($handler);
			} else {
				$container->method('get')->willThrowException(new Exception('not registered'));
			}
		}

		$factory = $this->createMock(ICacheFactory::class);
		if ($distributed === true) {
			$factory->method('createDistributed')->willReturn($this->createMock(ICache::class));
		} else {
			$factory->method('createDistributed')->willThrowException(new Exception('no backend'));
		}

		$direct = null;
		if ($source === 'direct' || $source === 'throws') {
			$direct = $handler;
		}

		$settings = new CacheSettingsHandler(
			$factory,
			new SchemaCacheClearers($this->createMock(SchemaCacheHandler::class), $this->createMock(FacetResultCacheClearer::class)),
			$direct,
			$container
		);

		$result = $settings->getCacheStats();
		$this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT/', $result['lastUpdated']);
		$result['lastUpdated'] = 'normalised';
		if ($result['distributed']['available'] === true) {
			$this->assertStringStartsWith('MockObject_ICache', $result['distributed']['backend']);
			$result['distributed']['backend'] = 'normalised';
		}

		$name = $this->dataName();
		$golden = [];
		if (file_exists(self::FIXTURE) === true) {
			$golden = json_decode((string) file_get_contents(self::FIXTURE), true);
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			$golden[$name] = ['result' => $result, 'types' => self::types($result)];
			ksort($golden);
			file_put_contents(self::FIXTURE, json_encode($golden, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION) . "\n");
		}

		$this->assertArrayHasKey($name, $golden, 'No golden recorded for ' . $name);
		$this->assertSame($golden[$name]['types'], self::types($result));
		$this->assertSame($golden[$name]['result'], json_decode(json_encode($result, JSON_PRESERVE_ZERO_FRACTION), true));
	}

	/**
	 * The PHP type of every leaf, so an int turning into a float shows.
	 *
	 * @param array<string, mixed> $data The payload.
	 *
	 * @return array<string, mixed>
	 */
	private static function types(array $data): array {
		return array_map(fn ($v) => is_array($v) === true ? self::types($v) : get_debug_type($v), $data);
	}
}
