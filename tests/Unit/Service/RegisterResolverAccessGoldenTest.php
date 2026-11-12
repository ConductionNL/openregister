<?php

/**
 * Golden test pinning the tenant-access outcomes of {@see \OCA\OpenRegister\Service\RegisterResolverService}.
 *
 * Pins, for registers AND schemas, what the explicit-organisation override
 * allows and refuses, which multitenancy flag reaches the mapper, how mapper
 * misses are wrapped, how the request cache is keyed per organisation, and
 * that a failing active-organisation lookup never flushes the cache. Written
 * against the code before it was split into small units, so the split is
 * held to the exact same allowed/refused set.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/register-resolver-service/spec.md
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ConfigLookup;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\RegisterResolverService;
use OCA\OpenRegister\Service\RegisterSchemaMappers;
use OCA\OpenRegister\Service\Resolver\Exception\RegisterNotFoundException;
use OCA\OpenRegister\Service\Resolver\Exception\SchemaNotFoundException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * RegisterResolverAccessGoldenTest.
 */
class RegisterResolverAccessGoldenTest extends TestCase {

	private IAppConfig&MockObject $appConfig;

	private RegisterMapper&MockObject $registerMapper;

	private SchemaMapper&MockObject $schemaMapper;

	private OrganisationService&MockObject $organisationService;

	private LoggerInterface&MockObject $logger;

	private RegisterResolverService $resolver;

	protected function setUp(): void {
		$this->appConfig           = $this->createMock(IAppConfig::class);
		$this->registerMapper      = $this->createMock(RegisterMapper::class);
		$this->schemaMapper        = $this->createMock(SchemaMapper::class);
		$this->organisationService = $this->createMock(OrganisationService::class);
		$this->logger              = $this->createMock(LoggerInterface::class);

		$this->appConfig->method('getValueString')->willReturn('the-slug');

		$this->resolver = new RegisterResolverService(
			appConfig: $this->appConfig,
			mappers: new RegisterSchemaMappers($this->registerMapper, $this->schemaMapper),
			organisationService: $this->organisationService,
			logger: $this->logger
		);
	}//end setUp()

	/**
	 * Override: the entity's own organisation, or none, is allowed; the mapper is asked without multitenancy.
	 *
	 * @return array<string, array{0: string|null}>
	 */
	public static function allowedOwners(): array {
		return [
			'same organisation' => ['org-caller'],
			'no organisation'   => [null],
		];
	}//end allowedOwners()

	/**
	 * @dataProvider allowedOwners
	 */
	public function testOverrideAllowsOwnOrUnownedRegister(?string $owner): void {
		$register = $this->register(owner: $owner);
		$this->registerMapper->expects($this->once())->method('find')
			->with('the-slug', true, false)
			->willReturn($register);

		$this->assertSame($register, $this->resolver->lookupRegister(new ConfigLookup('app', 'x_register', null, 'org-caller')));
	}//end testOverrideAllowsOwnOrUnownedRegister()

	/**
	 * @dataProvider allowedOwners
	 */
	public function testOverrideAllowsOwnOrUnownedSchema(?string $owner): void {
		$schema = $this->schema(owner: $owner);
		$this->schemaMapper->expects($this->once())->method('find')
			->with('the-slug', [], true, false)
			->willReturn($schema);

		$this->assertSame($schema, $this->resolver->lookupSchema(new ConfigLookup('app', 'x_schema', null, 'org-caller')));
	}//end testOverrideAllowsOwnOrUnownedSchema()

	public function testOverrideRefusesForeignRegisterAndDoesNotCacheIt(): void {
		$this->registerMapper->expects($this->exactly(2))->method('find')
			->with('the-slug', true, false)
			->willReturn($this->register(owner: 'org-other'));

		for ($attempt = 0; $attempt < 2; $attempt++) {
			try {
				$this->resolver->lookupRegister(new ConfigLookup('app', 'x_register', null, 'org-caller'));
				$this->fail('A register of another organisation must be refused.');
			} catch (RegisterNotFoundException $e) {
				$this->assertSame('the-slug', $e->getResolvedValue());
				$this->assertSame('app', $e->getAppId());
				$this->assertSame('x_register', $e->getConfigKey());
				$this->assertNull($e->getPrevious());
			}
		}
	}//end testOverrideRefusesForeignRegisterAndDoesNotCacheIt()

	public function testOverrideRefusesForeignSchemaAndDoesNotCacheIt(): void {
		$this->schemaMapper->expects($this->exactly(2))->method('find')
			->with('the-slug', [], true, false)
			->willReturn($this->schema(owner: 'org-other'));

		for ($attempt = 0; $attempt < 2; $attempt++) {
			try {
				$this->resolver->lookupSchema(new ConfigLookup('app', 'x_schema', null, 'org-caller'));
				$this->fail('A schema of another organisation must be refused.');
			} catch (SchemaNotFoundException $e) {
				$this->assertSame('the-slug', $e->getResolvedValue());
				$this->assertSame('x_schema', $e->getConfigKey());
				$this->assertNull($e->getPrevious());
			}
		}
	}//end testOverrideRefusesForeignSchemaAndDoesNotCacheIt()

	public function testWithoutOverrideTheMapperScopesTheRegister(): void {
		$register = $this->register(owner: 'org-other');
		$this->registerMapper->expects($this->once())->method('find')
			->with('the-slug', true, true)
			->willReturn($register);

		$this->assertSame($register, $this->resolver->lookupRegister(new ConfigLookup('app', 'x_register')));
	}//end testWithoutOverrideTheMapperScopesTheRegister()

	public function testWithoutOverrideTheMapperScopesTheSchema(): void {
		$schema = $this->schema(owner: 'org-other');
		$this->schemaMapper->expects($this->once())->method('find')
			->with('the-slug', [], true, true)
			->willReturn($schema);

		$this->assertSame($schema, $this->resolver->lookupSchema(new ConfigLookup('app', 'x_schema')));
	}//end testWithoutOverrideTheMapperScopesTheSchema()

	/**
	 * Mapper misses, with and without the override.
	 *
	 * @return array<string, array{0: \Exception, 1: string|null}>
	 */
	public static function misses(): array {
		return [
			'not found, override'      => [new DoesNotExistException('none'), 'org-caller'],
			'not found, scoped'        => [new DoesNotExistException('none'), null],
			'ambiguous, override'      => [new MultipleObjectsReturnedException('two'), 'org-caller'],
			'ambiguous, scoped'        => [new MultipleObjectsReturnedException('two'), null],
		];
	}//end misses()

	/**
	 * @dataProvider misses
	 */
	public function testARegisterMissIsWrapped(\Exception $miss, ?string $organisation): void {
		$this->registerMapper->method('find')->willThrowException($miss);

		try {
			$this->resolver->lookupRegister(new ConfigLookup('app', 'x_register', null, $organisation));
			$this->fail('A mapper miss must surface as RegisterNotFoundException.');
		} catch (RegisterNotFoundException $e) {
			$this->assertSame($miss, $e->getPrevious());
			$this->assertSame('the-slug', $e->getResolvedValue());
		}
	}//end testARegisterMissIsWrapped()

	/**
	 * @dataProvider misses
	 */
	public function testASchemaMissIsWrapped(\Exception $miss, ?string $organisation): void {
		$this->schemaMapper->method('find')->willThrowException($miss);

		try {
			$this->resolver->lookupSchema(new ConfigLookup('app', 'x_schema', null, $organisation));
			$this->fail('A mapper miss must surface as SchemaNotFoundException.');
		} catch (SchemaNotFoundException $e) {
			$this->assertSame($miss, $e->getPrevious());
			$this->assertSame('the-slug', $e->getResolvedValue());
		}
	}//end testASchemaMissIsWrapped()

	public function testTheCacheIsKeyedPerOrganisation(): void {
		$this->registerMapper->expects($this->exactly(3))->method('find')
			->willReturnCallback(fn (): Register => $this->register(owner: null));

		$a = $this->resolver->lookupRegister(new ConfigLookup('app', 'x_register', null, 'org-a'));
		$b = $this->resolver->lookupRegister(new ConfigLookup('app', 'x_register', null, 'org-b'));
		$scoped = $this->resolver->lookupRegister(new ConfigLookup('app', 'x_register'));

		$this->assertNotSame($a, $b);
		$this->assertSame($a, $this->resolver->lookupRegister(new ConfigLookup('app', 'x_register', null, 'org-a')));
		$this->assertSame($b, $this->resolver->lookupRegister(new ConfigLookup('app', 'x_register', null, 'org-b')));
		$this->assertSame($scoped, $this->resolver->lookupRegister(new ConfigLookup('app', 'x_register')));
	}//end testTheCacheIsKeyedPerOrganisation()

	public function testAFailingTenantLookupNeverFlushesTheCache(): void {
		$this->organisationService->method('getActiveOrganisation')
			->willThrowException(new RuntimeException('session gone'));
		$this->logger->expects($this->exactly(2))->method('debug');
		$this->schemaMapper->expects($this->once())->method('find')->willReturn($this->schema(owner: null));

		$first = $this->resolver->lookupSchema(new ConfigLookup('app', 'x_schema'));

		$this->assertSame($first, $this->resolver->lookupSchema(new ConfigLookup('app', 'x_schema')));
	}//end testAFailingTenantLookupNeverFlushesTheCache()

	/**
	 * A register owned by the given organisation.
	 *
	 * @param string|null $owner The owning organisation uuid.
	 *
	 * @return Register
	 */
	private function register(?string $owner): Register {
		$register = new Register();
		$register->setSlug('the-slug');
		if ($owner !== null) {
			$register->setOrganisation($owner);
		}

		return $register;
	}//end register()

	/**
	 * A schema owned by the given organisation.
	 *
	 * @param string|null $owner The owning organisation uuid.
	 *
	 * @return Schema
	 */
	private function schema(?string $owner): Schema {
		$schema = new Schema();
		$schema->setSlug('the-slug');
		if ($owner !== null) {
			$schema->setOrganisation($owner);
		}

		return $schema;
	}//end schema()
}//end class
