<?php

/**
 * Unit tests for FederatedObjectSourceProvider — reading objects from a peer's share.
 *
 * The provider builds the share URL from the source config, asks the peer over
 * HTTP and turns the JSON back into ObjectEntity rows. These tests stand in for
 * the peer with an IClient mock and assert the URL and query it was asked, and
 * what a missing config, a failing call, an error body or a malformed row
 * decides.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ObjectSource
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ObjectSource;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\ObjectSource\FederatedObjectSourceProvider;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class FederatedObjectSourceProviderTest extends TestCase {

	private const CONFIG = ['remoteUrl' => 'https://peer.example/', 'shareToken' => 'tok/en'];

	private const BASE = 'https://peer.example/apps/openregister/api/federation/tok%2Fen';

	private IClient&MockObject $client;

	private IClientService&MockObject $clients;

	private LoggerInterface&MockObject $logger;

	private Register $register;

	private Schema $schema;

	protected function setUp(): void {
		$this->client  = $this->createMock(IClient::class);
		$this->clients = $this->createMock(IClientService::class);
		$this->clients->method('newClient')->willReturn($this->client);
		$this->logger   = $this->createMock(LoggerInterface::class);
		$this->register = new Register();
		$this->register->setId(4);
		$this->schema = new Schema();
		$this->schema->setId(9);
	}

	private function provider(): FederatedObjectSourceProvider {
		return new FederatedObjectSourceProvider($this->clients, $this->logger);
	}

	private function respond(string $body): IResponse {
		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn($body);
		return $response;
	}

	public function testItIsAlwaysEnabledUnderTheFederatedId(): void {
		$provider = $this->provider();
		$this->assertSame('federated', $provider->getId());
		$this->assertTrue($provider->isEnabled());
	}

	public static function incompleteConfigs(): array {
		return [
			'nothing' => [[]],
			'no token' => [['remoteUrl' => 'https://peer.example']],
			'blank token' => [['remoteUrl' => 'https://peer.example', 'shareToken' => '  ']],
			'no url' => [['shareToken' => 'tok']],
		];
	}

	#[DataProvider('incompleteConfigs')]
	public function testAnIncompleteConfigAsksNobodyAndFindsNothing(array $config): void {
		$this->client->expects($this->never())->method('get');
		$this->logger->expects($this->exactly(2))->method('warning')
			->with('[ObjectSource:federated] missing remoteUrl/shareToken in source config');
		$provider = $this->provider();

		$this->assertNull($provider->find($this->register, $this->schema, 'x', $config));
		$this->assertSame([], $provider->findAll($this->register, $this->schema, [], $config));
	}

	public function testFindAsksThePeerForTheEncodedIdAndMapsTheRecord(): void {
		$this->client->expects($this->once())->method('get')
			->with(self::BASE . '/objects/a%20b', ['timeout' => 15])
			->willReturn($this->respond(json_encode(['@self' => ['id' => 'uuid-1', 'uri' => 'https://peer.example/o/1'], 'name' => 'Eén'])));

		$entity = $this->provider()->find($this->register, $this->schema, 'a b', self::CONFIG);

		$this->assertSame('uuid-1', $entity->getUuid());
		$this->assertSame('https://peer.example/o/1', $entity->getUri());
		$this->assertSame('4', $entity->getRegister());
		$this->assertSame('9', $entity->getSchema());
		$this->assertSame('Eén', $entity->getObject()['name']);
		$this->assertArrayNotHasKey('@self', $entity->getObject(), 'The peer\'s @self block is not stored as data.');
	}

	public function testARecordWithoutSelfFallsBackToItsIdThenUuidAndHasNoUri(): void {
		$this->client->method('get')->willReturnOnConsecutiveCalls(
			$this->respond(json_encode(['id' => 'by-id', 'uuid' => 'by-uuid'])),
			$this->respond(json_encode(['uuid' => 'by-uuid'])),
			$this->respond(json_encode(['name' => 'anonymous']))
		);
		$provider = $this->provider();

		$byId = $provider->find($this->register, $this->schema, '1', self::CONFIG);
		$this->assertSame('by-id', $byId->getUuid());
		$this->assertNull($byId->getUri());
		$this->assertSame('by-uuid', $provider->find($this->register, $this->schema, '2', self::CONFIG)->getUuid());
		$this->assertSame('', $provider->find($this->register, $this->schema, '3', self::CONFIG)->getUuid());
	}

	public static function unusableBodies(): array {
		return [
			'error body' => ['{"error":"not shared"}'],
			'not json' => ['<html>'],
			'a scalar' => ['42'],
		];
	}

	#[DataProvider('unusableBodies')]
	public function testAnUnusableFindBodyFindsNothing(string $body): void {
		$this->client->method('get')->willReturn($this->respond($body));

		$this->assertNull($this->provider()->find($this->register, $this->schema, '1', self::CONFIG));
	}

	public function testAFailingFindIsLoggedAndFindsNothing(): void {
		$this->client->method('get')->willThrowException(new RuntimeException('peer down'));
		$this->logger->expects($this->once())->method('warning')->with('[ObjectSource:federated] find failed: peer down');

		$this->assertNull($this->provider()->find($this->register, $this->schema, '1', self::CONFIG));
	}

	public function testFindAllSendsPagingAndTheSearchTermAndKeepsOnlyArrayRows(): void {
		$this->client->expects($this->once())->method('get')
			->with(self::BASE . '/objects', ['timeout' => 15, 'query' => ['_limit' => '10', '_offset' => '20', '_search' => 'water']])
			->willReturn($this->respond(json_encode(['results' => [['id' => 'a', 'n' => 1], 'junk', ['@self' => ['id' => 'b']]]])));

		$rows = $this->provider()->findAll($this->register, $this->schema, ['limit' => 10, 'offset' => 20, 'filters' => ['search' => 'water']], self::CONFIG);

		$this->assertSame(['a', 'b'], array_map(static fn ($e) => $e->getUuid(), $rows));
		$this->assertSame(1, $rows[0]->getObject()['n']);
		$this->assertArrayNotHasKey('@self', $rows[1]->getObject());
	}

	public static function searchSpellings(): array {
		return [
			'_search' => [['_search' => 'kelder'], 'kelder'],
			'search' => [['search' => 'zolder'], 'zolder'],
			'filters wins' => [['filters' => ['search' => 'a'], '_search' => 'b', 'search' => 'c'], 'a'],
		];
	}

	#[DataProvider('searchSpellings')]
	public function testTheSearchTermIsReadFromEachSpelling(array $query, string $expected): void {
		$this->client->expects($this->once())->method('get')
			->with(self::BASE . '/objects', ['timeout' => 15, 'query' => ['_limit' => '50', '_offset' => '0', '_search' => $expected]])
			->willReturn($this->respond('{"results":[]}'));

		$this->assertSame([], $this->provider()->findAll($this->register, $this->schema, $query, self::CONFIG));
	}

	public function testAnEmptySearchTermIsNotSent(): void {
		$this->client->expects($this->once())->method('get')
			->with(self::BASE . '/objects', ['timeout' => 15, 'query' => ['_limit' => '50', '_offset' => '0']])
			->willReturn($this->respond('{}'));

		$this->assertSame([], $this->provider()->findAll($this->register, $this->schema, ['_search' => ''], self::CONFIG));
	}

	public function testAFailingFindAllIsLoggedAndListsNothing(): void {
		$this->client->method('get')->willThrowException(new RuntimeException('timeout'));
		$this->logger->expects($this->once())->method('warning')->with('[ObjectSource:federated] findAll failed: timeout');

		$this->assertSame([], $this->provider()->findAll($this->register, $this->schema, [], self::CONFIG));
	}

	public function testCountIsTheNumberOfRowsTheListReturns(): void {
		$this->client->method('get')->willReturn($this->respond(json_encode(['results' => [['id' => 'a'], ['id' => 'b'], 'x']])));

		$this->assertSame(2, $this->provider()->count($this->register, $this->schema, [], self::CONFIG));
	}
}
