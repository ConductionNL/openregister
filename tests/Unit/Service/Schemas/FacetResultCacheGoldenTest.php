<?php

/**
 * Golden record of how a schema change and the admin facet clear empty the live facet caches.
 *
 * Every scenario records each cache-factory call (which namespace, distributed
 * or local, and whether it was cleared) and, for the admin action, the response
 * of CacheSettingsHandler::clearCache('facet') with its timing fields masked.
 * The recording lives in fixtures/facet-result-cache-golden.json; set
 * FACET_GOLDEN_RECORD=1 to write it.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Schemas
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec exclude Pins the facet cache clearing the schema and settings paths rely on.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Schemas;

use OCA\OpenRegister\Tests\Unit\Service\Schemas\Support\FacetCacheSubject;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The live facet caches are emptied on a schema change and by the admin clear.
 */
class FacetResultCacheGoldenTest extends TestCase {

	private const FIXTURE = __DIR__.'/fixtures/facet-result-cache-golden.json';

	/**
	 * What the factory saw.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $events = [];

	/**
	 * A cache factory that records every call; `$failing` lists kinds that throw.
	 *
	 * @param list<string> $failing `distributed` and/or `local`.
	 *
	 * @return ICacheFactory The factory.
	 */
	private function factory(array $failing): ICacheFactory {
		$factory = $this->createMock(ICacheFactory::class);
		foreach (['distributed' => 'createDistributed', 'local' => 'createLocal'] as $kind => $method) {
			$factory->method($method)->willReturnCallback(
				function (string $name='') use ($kind, $failing): ICache {
					$this->events[] = ['create' => $kind, 'name' => $name];
					if (in_array($kind, $failing, true) === true) {
						throw new RuntimeException($kind.' cache unavailable');
					}

					$cache = $this->createMock(ICache::class);
					$cache->method('clear')->willReturnCallback(
						function (string $prefix='') use ($kind, $name): bool {
							$this->events[] = ['clear' => $kind, 'name' => $name, 'prefix' => $prefix];
							return true;
						}
					);
					return $cache;
				}
			);
		}

		return $factory;
	}//end factory()

	/**
	 * Mask the fields that carry the moment of the run.
	 *
	 * @param mixed $value A response.
	 *
	 * @return mixed The response with query_time and timestamp masked.
	 */
	private function masked(mixed $value): mixed {
		if (is_array($value) === false) {
			return $value;
		}

		foreach ($value as $key => $inner) {
			$value[$key] = in_array($key, ['query_time', 'timestamp'], true) ? '<masked>' : $this->masked($inner);
		}

		return $value;
	}//end masked()

	/**
	 * Each scenario empties the same caches the same way.
	 *
	 * @return void
	 */
	public function testFacetCachesAreClearedAsRecorded(): void {
		$seen = [];
		$runs = ['schema update' => 'schemaChange', 'admin clear' => 'adminClear'];
		$fails = ['caches up' => [], 'distributed down' => ['distributed'], 'all down' => ['distributed', 'local']];
		foreach ($runs as $run => $method) {
			foreach ($fails as $label => $failing) {
				$this->events = [];
				$subject = new FacetCacheSubject($this, $this->factory($failing));
				$result = $subject->$method();
				$seen[$run.', '.$label] = ['events' => $this->events, 'response' => $this->masked($result)];
			}
		}

		if (getenv('FACET_GOLDEN_RECORD') === '1') {
			@mkdir(dirname(self::FIXTURE));
			file_put_contents(self::FIXTURE, json_encode($seen, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
			$this->markTestIncomplete('recorded');
		}

		$this->assertSame(json_decode((string)file_get_contents(self::FIXTURE), true), json_decode((string)json_encode($seen), true));
	}//end testFacetCachesAreClearedAsRecorded()
}//end class
