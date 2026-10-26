<?php

/**
 * Resolving a selectielijst URL (as a result type stores it) to the code and
 * name a reader understands ("6.1, Ingewilligd").
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Archival
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/selectielijst-reference-resolve/specs/retention-management/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Archival;

use OCA\OpenRegister\Service\Archival\SelectielijstReferenceResolver;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * SelectielijstReferenceResolver.
 */
class SelectielijstReferenceResolverTest extends TestCase {

	private const URL = 'https://selectielijst.openzaak.nl/api/v1/resultaten/3fc7002b-31b6-4abe-82af-b6d46a9a1736';

	/**
	 * A resolver over a client that answers with the given body, and an array cache.
	 *
	 * @param string|null $body   The JSON body, null for a failing request.
	 * @param int         $called Receives how often the client was asked.
	 *
	 * @return SelectielijstReferenceResolver
	 */
	private function resolver(?string $body, int &$called=0): SelectielijstReferenceResolver {
		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturnCallback(
			function () use ($body, &$called) {
				$called++;
				if ($body === null) {
					throw new RuntimeException('unreachable');
				}

				$response = $this->createMock(IResponse::class);
				$response->method('getBody')->willReturn($body);
				return $response;
			}
		);
		$clients = $this->createMock(IClientService::class);
		$clients->method('newClient')->willReturn($client);

		$store = [];
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturnCallback(static function (string $key) use (&$store) {
			return ($store[$key] ?? null);
		});
		$cache->method('set')->willReturnCallback(static function (string $key, $value) use (&$store) {
			$store[$key] = $value;
			return true;
		});
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createDistributed')->willReturn($cache);

		return new SelectielijstReferenceResolver(clients: $clients, cacheFactory: $factory, logger: new NullLogger());
	}//end resolver()

	/**
	 * A VNG result URL reads as its full number and name, and is fetched once.
	 *
	 * @return void
	 */
	public function testResolvesTheCodeAndNameOnceAndCachesThem(): void {
		$called   = 0;
		$resolver = $this->resolver(body: '{"url":"'.self::URL.'","volledigNummer":"6.1","naam":"Ingewilligd"}', called: $called);

		$expected = ['url' => self::URL, 'code' => '6.1', 'name' => 'Ingewilligd'];
		$this->assertSame($expected, $resolver->resolve(url: self::URL));
		$this->assertSame($expected, $resolver->resolve(url: self::URL));
		$this->assertSame(1, $called);
	}//end testResolvesTheCodeAndNameOnceAndCachesThem()

	/**
	 * Only https URLs on an allowed selectielijst host are fetched.
	 *
	 * @return void
	 */
	public function testRefusesAnyOtherHostOrScheme(): void {
		$called   = 0;
		$resolver = $this->resolver(body: '{}', called: $called);

		$this->assertFalse($resolver->isResolvable(url: 'https://example.org/api/v1/resultaten/x'));
		$this->assertFalse($resolver->isResolvable(url: 'http://selectielijst.openzaak.nl/api/v1/resultaten/x'));
		$this->assertFalse($resolver->isResolvable(url: 'https://selectielijst.openzaak.nl.evil.org/api/v1/resultaten/x'));
		$this->assertNull($resolver->resolve(url: 'https://example.org/api/v1/resultaten/x'));
		$this->assertSame(0, $called);
	}//end testRefusesAnyOtherHostOrScheme()

	/**
	 * An unreachable list answers null, so the caller falls back.
	 *
	 * @return void
	 */
	public function testAnUnreachableListAnswersNull(): void {
		$this->assertNull($this->resolver(body: null)->resolve(url: self::URL));
	}//end testAnUnreachableListAnswersNull()
}//end class
