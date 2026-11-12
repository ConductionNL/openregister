<?php

/**
 * Characterisation of SelectielijstReferenceResolver: which URLs it reads, what it caches, what it refuses.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Archival
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Archival;

use OCA\OpenRegister\Service\Archival\SelectielijstReferenceResolver;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins the resolver's outcomes before its constructor changes.
 */
class SelectielijstReferenceResolverTest extends TestCase {

	private const URL = 'https://selectielijst.openzaak.nl/api/v1/resultaten/0e4d4fe1-1a1b-4c37-b1c1-2f6a3f3a9a10';

	/** @var IClient&MockObject */
	private IClient $client;

	/** @var ICache&MockObject */
	private ICache $cache;

	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;

	/**
	 * The resolver over a mocked client, cache and logger.
	 *
	 * @return SelectielijstReferenceResolver The resolver.
	 */
	private function resolver(): SelectielijstReferenceResolver {
		$this->client = $this->createMock(IClient::class);
		$clients = $this->createMock(IClientService::class);
		$clients->method('newClient')->willReturn($this->client);

		$this->cache = $this->createMock(ICache::class);
		$factory = $this->createMock(ICacheFactory::class);
		$factory->expects($this->atMost(1))->method('createDistributed')->with('openregister-selectielijst-reference')->willReturn($this->cache);

		$this->logger = $this->createMock(LoggerInterface::class);

		return new SelectielijstReferenceResolver($clients, $factory, $this->logger);
	}//end resolver()

	/**
	 * A response with this body.
	 *
	 * @param string $body The body.
	 *
	 * @return IResponse The response.
	 */
	private function response(string $body): IResponse {
		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn($body);
		return $response;
	}//end response()

	/**
	 * Only an https URL on the allowed host, without user or port, naming a result or process type, is resolvable.
	 *
	 * @return void
	 */
	public function testOnlyThePublishedListIsResolvable(): void {
		$resolver = $this->resolver();
		$uuid = '0e4d4fe1-1a1b-4c37-b1c1-2f6a3f3a9a10';

		$this->assertTrue($resolver->isResolvable(url: self::URL));
		$this->assertTrue($resolver->isResolvable(url: 'https://selectielijst.openzaak.nl/api/v1/procestypen/' . $uuid));
		$this->assertFalse($resolver->isResolvable(url: 'http://selectielijst.openzaak.nl/api/v1/resultaten/' . $uuid));
		$this->assertFalse($resolver->isResolvable(url: 'https://selectielijst.openzaak.nl:8443/api/v1/resultaten/' . $uuid));
		$this->assertFalse($resolver->isResolvable(url: 'https://u@selectielijst.openzaak.nl/api/v1/resultaten/' . $uuid));
		$this->assertFalse($resolver->isResolvable(url: 'https://example.org/api/v1/resultaten/' . $uuid));
		$this->assertFalse($resolver->isResolvable(url: 'https://selectielijst.openzaak.nl/api/v1/other/' . $uuid));
		$this->assertFalse($resolver->isResolvable(url: 'not a url'));
	}//end testOnlyThePublishedListIsResolvable()

	/**
	 * A URL that is not resolvable is never fetched.
	 *
	 * @return void
	 */
	public function testAnUnresolvableUrlIsNotFetched(): void {
		$resolver = $this->resolver();
		$this->client->expects($this->never())->method('get');

		$this->assertNull($resolver->resolve(url: 'https://example.org/api/v1/resultaten/x'));
	}//end testAnUnresolvableUrlIsNotFetched()

	/**
	 * A cached row is returned without a fetch.
	 *
	 * @return void
	 */
	public function testACachedRowIsReturnedWithoutAFetch(): void {
		$resolver = $this->resolver();
		$cached = ['url' => self::URL, 'code' => '1.1', 'name' => 'Ingericht'];
		$this->cache->method('get')->with(hash('sha256', self::URL))->willReturn($cached);
		$this->client->expects($this->never())->method('get');

		$this->assertSame($cached, $resolver->resolve(url: self::URL));
	}//end testACachedRowIsReturnedWithoutAFetch()

	/**
	 * A fetched row is reduced to its code and name and cached for a day.
	 *
	 * @return void
	 */
	public function testAFetchedRowIsReducedAndCached(): void {
		$resolver = $this->resolver();
		$this->cache->method('get')->willReturn(null);
		$this->client->method('get')->with(self::URL, ['timeout' => 5, 'headers' => ['Accept' => 'application/json']])
			->willReturn($this->response(json_encode(['volledigNummer' => '1.1.2', 'nummer' => 2, 'naam' => 'Ingericht'])));
		$expected = ['url' => self::URL, 'code' => '1.1.2', 'name' => 'Ingericht'];
		$this->cache->expects($this->once())->method('set')->with(hash('sha256', self::URL), $expected, 86400);

		$this->assertSame($expected, $resolver->resolve(url: self::URL));
	}//end testAFetchedRowIsReducedAndCached()

	/**
	 * A failed fetch, an unreadable body and a row without code or name give null.
	 *
	 * @return void
	 */
	public function testAnUnusableRowGivesNull(): void {
		$resolver = $this->resolver();
		$this->cache->method('get')->willReturn(null);
		$this->cache->expects($this->never())->method('set');
		$this->client->method('get')->willReturnOnConsecutiveCalls(
			$this->throwException(new RuntimeException('down')),
			$this->response('not json'),
			$this->response(json_encode(['omschrijving' => 'x']))
		);
		$this->logger->expects($this->once())->method('warning');

		$this->assertNull($resolver->resolve(url: self::URL));
		$this->assertNull($resolver->resolve(url: self::URL));
		$this->assertNull($resolver->resolve(url: self::URL));
	}//end testAnUnusableRowGivesNull()

	/**
	 * A row without a full number falls back to its number, a row with only a name resolves with an empty
	 * code, and a failed fetch logs the URL and the reason; refused URLs, failed fetches and rows without
	 * code or name are never cached.
	 *
	 * @return void
	 */
	public function testTheRowFallbacksAndTheFailureLogArePinned(): void {
		$resolver = $this->resolver();
		$this->cache->method('get')->willReturn(null);
		$set = [];
		$this->cache->method('set')->willReturnCallback(
			static function (string $key, mixed $value, int $ttl) use (&$set): bool {
				$set[] = [$key, $value, $ttl];
				return true;
			}
		);
		$this->client->method('get')->willReturnOnConsecutiveCalls(
			$this->response(json_encode(['nummer' => 7, 'naam' => 'Vernietigen'])),
			$this->response(json_encode(['naam' => 'Alleen een naam'])),
			$this->response(json_encode(['volledigNummer' => '', 'nummer' => '3'])), // An empty full number does not fall back.
			$this->response('null'),
			$this->throwException(new RuntimeException('connection refused'))
		);
		$this->logger->expects($this->once())->method('warning')->with(
			'[SelectielijstReferenceResolver] The selectielijst row could not be read',
			['url' => self::URL, 'exception' => 'connection refused']
		);

		$this->assertSame(['url' => self::URL, 'code' => '7', 'name' => 'Vernietigen'], $resolver->resolve(url: self::URL));
		$this->assertSame(['url' => self::URL, 'code' => '', 'name' => 'Alleen een naam'], $resolver->resolve(url: self::URL));
		$this->assertNull($resolver->resolve(url: self::URL));
		$this->assertNull($resolver->resolve(url: self::URL));
		$this->assertNull($resolver->resolve(url: self::URL));
		$this->assertNull($resolver->resolve(url: 'http://selectielijst.openzaak.nl/api/v1/resultaten/0e4d4fe1-1a1b-4c37-b1c1-2f6a3f3a9a10'));

		$this->assertSame(
			[
				[hash('sha256', self::URL), ['url' => self::URL, 'code' => '7', 'name' => 'Vernietigen'], 86400],
				[hash('sha256', self::URL), ['url' => self::URL, 'code' => '', 'name' => 'Alleen een naam'], 86400],
			],
			$set
		);
	}//end testTheRowFallbacksAndTheFailureLogArePinned()
}//end class
