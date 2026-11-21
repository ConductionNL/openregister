<?php

/**
 * Unit tests for ExternalRegisterLookup — the external-register leaf's read:
 * the caller's right first, then a cached answer, then the source.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Integration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/external-register-view-leaf/specs/object-source-providers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Integration;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Integration\ExternalRegisterDegrade;
use OCA\OpenRegister\Service\Integration\ExternalRegisterLookup;
use OCA\OpenRegister\Service\Object\PermissionCheckInterface;
use OCA\OpenRegister\Service\ObjectSource\OpenConnectorHttpProvider;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Service\Integration\ExternalRegisterLookup
 * @uses \OCA\OpenRegister\Service\Integration\ExternalRegisterDegrade
 * @uses \OCA\OpenRegister\Db\Register
 */
final class ExternalRegisterLookupTest extends TestCase {

	private const CONFIG = ['source' => 'bag', 'find' => '/adressen/{key}'];

	private const LOOKUP_OK = ['key' => 'k', 'appInstalled' => true, 'configured' => true, 'answered' => true, 'refused' => false, 'record' => ['straat' => 'Dam']];

	private RegisterMapperInterface&MockObject $registers;

	private SchemaMapperInterface&MockObject $schemas;

	private PermissionCheckInterface&MockObject $permissions;

	private OpenConnectorHttpProvider&MockObject $http;

	private ICache&MockObject $cache;

	private ICacheFactory&MockObject $caches;

	private ITimeFactory&MockObject $time;

	private Schema&MockObject $schema;

	protected function setUp(): void {
		$register = new Register();
		$register->setId(4);
		$this->registers = $this->createMock(RegisterMapperInterface::class);
		$this->registers->method('find')->willReturn($register);
		$this->schema  = $this->createMock(Schema::class);
		$this->schemas = $this->createMock(SchemaMapperInterface::class);
		$this->schemas->method('find')->willReturn($this->schema);
		$this->permissions = $this->createMock(PermissionCheckInterface::class);
		$this->http        = $this->createMock(OpenConnectorHttpProvider::class);
		$this->cache       = $this->createMock(ICache::class);
		$this->caches      = $this->createMock(ICacheFactory::class);
		$this->caches->method('createDistributed')->willReturn($this->cache);
		$this->time = $this->createMock(ITimeFactory::class);
		$this->time->method('getTime')->willReturn(1_800_000_000);
	}

	private function lookup(): ExternalRegisterLookup {
		return new ExternalRegisterLookup(
			$this->registers,
			$this->schemas,
			$this->permissions,
			$this->http,
			new ExternalRegisterDegrade(),
			$this->caches,
			$this->time,
			$this->createMock(LoggerInterface::class)
		);
	}

	private function sourced(array $declaration=['provider' => 'openconnector-http', 'config' => self::CONFIG]): void {
		$this->schema->method('getObjectSource')->willReturn($declaration);
	}

	public function testARecordIsAnsweredWithItsFetchTimeAndSourceAndCachedForItsState(): void {
		$this->permissions->expects($this->once())->method('hasPermission')
			->with($this->schema, 'read', null, null, true, null, '4')->willReturn(true);
		$this->sourced();
		$this->http->expects($this->once())->method('lookup')->with(self::CONFIG, 'k')->willReturn(self::LOOKUP_OK);
		$this->cache->method('get')->willReturn(null);
		$this->cache->expects($this->once())->method('set')
			->with($this->isType('string'), $this->callback(static fn (array $a): bool => $a['state'] === 'ok'), 900);

		$answer = $this->lookup()->read('4', 'bag-adres', 'k');

		$this->assertSame(
			['state' => 'ok', 'adminActionable' => false, 'record' => ['straat' => 'Dam'], 'fetchedAt' => '2027-01-15T08:00:00Z', 'source' => 'bag', 'cacheSeconds' => 900],
			$answer
		);
	}

	public function testARefusedReadRightIsNullAndAsksNoSource(): void {
		$this->permissions->method('hasPermission')->willReturn(false);
		$this->http->expects($this->never())->method('lookup');
		$this->cache->expects($this->never())->method('get');

		$this->assertNull($this->lookup()->read('4', 'bag-adres', 'k'));
	}

	public function testAnUnknownSchemaIsTheSameNull(): void {
		$this->schemas = $this->createMock(SchemaMapperInterface::class);
		$this->schemas->method('find')->willThrowException(new DoesNotExistException('no'));
		$this->permissions->expects($this->never())->method('hasPermission');

		$this->assertNull($this->lookup()->read('4', 'nope', 'k'));
	}

	public function testACachedAnswerIsReusedAfterTheRightIsAsked(): void {
		$this->permissions->expects($this->once())->method('hasPermission')->willReturn(true);
		$cached = ['state' => 'ok', 'record' => ['straat' => 'Dam']];
		$this->cache->method('get')->willReturn($cached);
		$this->http->expects($this->never())->method('lookup');

		$this->assertSame($cached, $this->lookup()->read('4', 'bag-adres', 'k'));
	}

	public function testRefreshIgnoresTheCache(): void {
		$this->permissions->method('hasPermission')->willReturn(true);
		$this->sourced();
		$this->cache->expects($this->never())->method('get');
		$this->http->expects($this->once())->method('lookup')->willReturn(self::LOOKUP_OK);

		$this->assertSame('ok', $this->lookup()->read('4', 'bag-adres', 'k', true)['state']);
	}

	public function testAnOutageIsCachedBriefly(): void {
		$this->permissions->method('hasPermission')->willReturn(true);
		$this->sourced();
		$this->http->method('lookup')->willReturn(['answered' => false] + self::LOOKUP_OK);
		$this->cache->expects($this->once())->method('set')->with($this->anything(), $this->anything(), 30);

		$answer = $this->lookup()->read('4', 'bag-adres', 'k', true);

		$this->assertSame('unreachable', $answer['state']);
		$this->assertTrue($answer['adminActionable']);
		$this->assertNull($answer['record']);
	}

	public function testAStateThatChangesWhenSomebodyActsIsNotCached(): void {
		$this->permissions->method('hasPermission')->willReturn(true);
		$this->sourced();
		$this->http->method('lookup')->willReturn(['configured' => false] + self::LOOKUP_OK);
		$this->cache->expects($this->never())->method('set');

		$this->assertSame('not-configured', $this->lookup()->read('4', 'bag-adres', 'k', true)['state']);
	}

	public function testASchemaWithoutAnHttpSourceIsSourceAbsent(): void {
		$this->permissions->method('hasPermission')->willReturn(true);
		$this->sourced(['provider' => 'caldav-vtodo', 'config' => ['source' => 'x']]);
		$this->http->expects($this->never())->method('lookup');

		$answer = $this->lookup()->read('4', 'tasks', 'k', true);

		$this->assertSame('source-absent', $answer['state']);
		$this->assertNull($answer['source']);
	}

	public function testAnEmptyKeyIsNoKeyBeforeAnythingElse(): void {
		$this->permissions->method('hasPermission')->willReturn(true);
		$this->schema->method('getObjectSource')->willReturn(null);

		$this->assertSame('no-key', $this->lookup()->read('4', 'bag-adres', ' ', true)['state']);
	}
}
