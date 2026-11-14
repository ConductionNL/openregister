<?php
/**
 * Unit tests for ProbeResultCache — the probe results kept per backend for the configured TTL.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Anonymisation
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Anonymisation;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Service\Anonymisation\ProbeResult;
use OCA\OpenRegister\Service\Anonymisation\ProbeResultCache;
use OCP\IAppConfig;
use OCP\ICache;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProbeResultCache::class)]
#[UsesClass(ProbeResult::class)]
final class ProbeResultCacheTest extends TestCase {

	/**
	 * The cache entries, by key.
	 *
	 * @var array<string, mixed>
	 */
	private array $entries = [];

	/**
	 * The TTL of each write, by key.
	 *
	 * @var array<string, int>
	 */
	private array $ttls = [];

	private function cache(int $storedTtl = 60, bool $withBackend = true): ProbeResultCache {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')
			->with('openregister', 'anonymisation.probe_cache_ttl', 60)
			->willReturn($storedTtl);

		$backend = null;
		if ($withBackend === true) {
			$backend = $this->createMock(ICache::class);
			$backend->method('get')->willReturnCallback(fn (string $key): mixed => ($this->entries[$key] ?? null));
			$backend->method('set')->willReturnCallback(
				function (string $key, mixed $value, int $ttl): bool {
					$this->entries[$key] = $value;
					$this->ttls[$key] = $ttl;
					return true;
				}
			);
		}

		return new ProbeResultCache($backend, $appConfig);
	}

	public function testAWrittenResultIsReadBackForItsMethodWithTheTtl(): void {
		$cache = $this->cache(storedTtl: 120);
		$cache->write('presidio', new ProbeResult(true, 42, null, '2026-11-12T10:00:00Z'));

		$read = $cache->read('presidio');

		$this->assertSame(
			['reachable' => true, 'latencyMs' => 42, 'error' => null, 'probedAt' => '2026-11-12T10:00:00Z'],
			$read?->jsonSerialize()
		);
		$this->assertSame(120, $this->ttls['presidio']);
		$this->assertNull($cache->read('llm'));
	}

	public function testAnUnreachableResultKeepsItsError(): void {
		$cache = $this->cache();
		$cache->write('openanonymiser', new ProbeResult(false, null, ProbeResult::ERROR_EXAPP_DISABLED, 't'));

		$this->assertSame(
			['reachable' => false, 'latencyMs' => null, 'error' => 'exapp_disabled', 'probedAt' => 't'],
			$cache->read('openanonymiser')?->jsonSerialize()
		);
	}

	public function testTheTtlIsClampedToTenThroughSixHundredSeconds(): void {
		$this->assertSame(10, $this->cache(storedTtl: 1)->ttl());
		$this->assertSame(600, $this->cache(storedTtl: 9999)->ttl());
		$this->assertSame(60, $this->cache()->ttl());
	}

	public function testAnUnreadableEntryIsAMiss(): void {
		$this->entries = ['regex' => 'not json', 'presidio' => ['already' => 'decoded'], 'llm' => '"a string"'];
		$cache = $this->cache();

		$this->assertNull($cache->read('regex'));
		$this->assertNull($cache->read('presidio'));
		$this->assertNull($cache->read('llm'));
	}

	public function testAPartialEntryReadsAsUnreachableWithTheGivenFields(): void {
		$this->entries = ['presidio' => '{"latencyMs":"17","probedAt":"t"}'];

		$this->assertSame(
			['reachable' => false, 'latencyMs' => 17, 'error' => null, 'probedAt' => 't'],
			$this->cache()->read('presidio')?->jsonSerialize()
		);
	}

	public function testWithoutACacheBackendEveryReadMissesAndWritesAreDropped(): void {
		$cache = $this->cache(withBackend: false);
		$cache->write('presidio', new ProbeResult(true, 1, null, 't'));

		$this->assertNull($cache->read('presidio'));
		$this->assertSame([], $this->entries);
	}

	public function testAnHttpFailureMessageMapsToItsProbeError(): void {
		$this->assertSame(ProbeResult::ERROR_TIMEOUT, ProbeResult::errorFromHttpMessage('cURL error 28: Operation timed out'));
		$this->assertSame(ProbeResult::ERROR_TIMEOUT, ProbeResult::errorFromHttpMessage('Request TIMEOUT'));
		$this->assertSame(ProbeResult::ERROR_DNS_ERROR, ProbeResult::errorFromHttpMessage('Could not resolve host: presidio'));
		$this->assertSame(ProbeResult::ERROR_DNS_ERROR, ProbeResult::errorFromHttpMessage('Name or service not known'));
		$this->assertSame(ProbeResult::ERROR_CONNECT_REFUSED, ProbeResult::errorFromHttpMessage('Connection refused'));
	}
}
