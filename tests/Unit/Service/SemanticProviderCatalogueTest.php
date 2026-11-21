<?php

/**
 * Unit tests for {@see \OCA\OpenRegister\Service\SemanticProviderCatalogue}.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/cross-app-semantic-references/tasks.md#task-4.1
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaCatalog;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\JsonLd\JsonLdContextService;
use OCA\OpenRegister\Service\SemanticProviderCatalogue;
use OCA\OpenRegister\Service\SemanticTypeResolver;
use OCP\App\IAppManager;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * The provider list: its rows, its availability reasons and its per-user cache.
 */
#[CoversClass(SemanticProviderCatalogue::class)]
#[UsesClass(SemanticTypeResolver::class)]
#[UsesClass(JsonLdContextService::class)]
#[UsesClass(SchemaCatalog::class)]
#[UsesClass(Schema::class)]
#[UsesClass(Register::class)]
class SemanticProviderCatalogueTest extends TestCase {

	private const ORG = 'https://schema.org/Organization';

	private const VENDOR = 'https://openregister.app/ns#Vendor';

	private SchemaMapper&MockObject $schemas;

	private RegisterMapper&MockObject $registers;

	/**
	 * The in-memory cache behind the mocked ICache.
	 *
	 * @var array<string, mixed>
	 */
	private array $store = [];

	/**
	 * Uid the session resolves to, or null for no user.
	 */
	private ?string $uid = 'alice';

	/**
	 * Enabled and installed apps.
	 *
	 * @var array{enabled: list<string>, installed: list<string>}
	 */
	private array $apps = ['enabled' => [], 'installed' => []];

	protected function setUp(): void {
		$this->schemas   = $this->createMock(SchemaMapper::class);
		$this->registers = $this->createMock(RegisterMapper::class);
		$this->registers->method('findAll')->willReturn([]);
	}//end setUp()

	/**
	 * Build the catalogue over the mocked mappers, app manager, session and cache.
	 *
	 * @return SemanticProviderCatalogue
	 */
	private function catalogue(): SemanticProviderCatalogue {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isEnabledForUser')->willReturnCallback(fn (string $app): bool => in_array($app, $this->apps['enabled'], true));
		$appManager->method('isInstalled')->willReturnCallback(fn (string $app): bool => in_array($app, $this->apps['installed'], true));

		$catalog  = new SchemaCatalog(registers: $this->registers, schemas: $this->schemas, tables: $this->createMock(MagicMapperInterface::class));
		$resolver = new SemanticTypeResolver(
			catalog: $catalog,
			jsonLdContextService: new JsonLdContextService($this->createMock(IURLGenerator::class)),
			logger: new NullLogger(),
			appManager: $appManager
		);

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturnCallback(function (): ?IUser {
			if ($this->uid === null) {
				return null;
			}

			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($this->uid);
			return $user;
		});

		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturnCallback(fn (string $key): mixed => $this->store[$key] ?? null);
		$cache->method('set')->willReturnCallback(function (string $key, mixed $value): bool {
			$this->store[$key] = $value;
			return true;
		});
		$cache->method('clear')->willReturnCallback(function (): bool {
			$this->store = [];
			return true;
		});
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createDistributed')->with(SemanticProviderCatalogue::CACHE_PREFIX)->willReturn($cache);

		return new SemanticProviderCatalogue(
			catalog: $catalog,
			resolver: $resolver,
			appManager: $appManager,
			userSession: $session,
			cacheFactory: $factory,
			logger: new NullLogger()
		);
	}//end catalogue()

	/**
	 * A schema entity.
	 *
	 * @param int                  $id            Id.
	 * @param string               $slug          Slug.
	 * @param array<string, mixed> $configuration Configuration.
	 * @param string|null          $application   Owning app.
	 *
	 * @return Schema
	 */
	private function schema(int $id, string $slug, array $configuration, ?string $application = null): Schema {
		$schema = new Schema();
		$schema->setId($id);
		$schema->setSlug($slug);
		$schema->setUuid('uuid-'.$id);
		$schema->setConfiguration($configuration);
		if ($application !== null) {
			$schema->setApplication($application);
		}

		return $schema;
	}//end schema()

	/**
	 * A register entity.
	 *
	 * @param int        $id        Id.
	 * @param string     $slug      Slug.
	 * @param list<int>  $schemaIds Member schema ids.
	 *
	 * @return Register
	 */
	private function register(int $id, string $slug, array $schemaIds): Register {
		$register = new Register();
		$register->setId($id);
		$register->setSlug($slug);
		$register->setSchemas($schemaIds);
		return $register;
	}//end register()

	public function testAnEnabledProviderIsListedWithItsRegister(): void {
		$this->apps['enabled'] = ['shillinq'];
		$this->schemas->method('findAll')->willReturn([
			$this->schema(id: 7, slug: 'payee', configuration: ['jsonld' => ['type' => self::ORG]], application: 'shillinq'),
			$this->schema(id: 8, slug: 'invoice', configuration: []),
		]);
		$registers = $this->createMock(RegisterMapper::class);
		$registers->method('findAll')->willReturn([$this->register(id: 3, slug: 'finance', schemaIds: [8, 7])]);
		$this->registers = $registers;

		$this->assertSame(
			[
				[
					'uri'          => self::ORG,
					'register'     => 3,
					'registerSlug' => 'finance',
					'schema'       => 7,
					'schemaSlug'   => 'payee',
					'appId'        => 'shillinq',
					'available'    => true,
					'reason'       => null,
				],
			],
			$this->catalogue()->list()
		);
	}//end testAnEnabledProviderIsListedWithItsRegister()

	public function testADisabledAppGivesAppDisabled(): void {
		$this->apps['installed'] = ['shillinq'];
		$this->schemas->method('findAll')->willReturn([
			$this->schema(id: 7, slug: 'payee', configuration: ['jsonld' => ['type' => self::ORG]], application: 'shillinq'),
		]);

		$row = $this->catalogue()->list()[0];

		$this->assertFalse($row['available']);
		$this->assertSame(SemanticProviderCatalogue::REASON_APP_DISABLED, $row['reason']);
		$this->assertNull($row['register']);
	}//end testADisabledAppGivesAppDisabled()

	public function testAnAppThatIsNotInstalledGivesAppNotInstalled(): void {
		$this->schemas->method('findAll')->willReturn([
			$this->schema(id: 7, slug: 'payee', configuration: ['jsonld' => ['type' => self::ORG]], application: 'shillinq'),
		]);

		$row = $this->catalogue()->list()[0];

		$this->assertFalse($row['available']);
		$this->assertSame(SemanticProviderCatalogue::REASON_APP_NOT_INSTALLED, $row['reason']);
	}//end testAnAppThatIsNotInstalledGivesAppNotInstalled()

	public function testASchemaWithoutAnOwningAppIsAlwaysAvailable(): void {
		$this->schemas->method('findAll')->willReturn([
			$this->schema(id: 2, slug: 'organisation', configuration: ['jsonld' => ['type' => self::ORG]]),
		]);

		$row = $this->catalogue()->list()[0];

		$this->assertNull($row['appId']);
		$this->assertTrue($row['available']);
		$this->assertNull($row['reason']);
	}//end testASchemaWithoutAnOwningAppIsAlwaysAvailable()

	public function testEveryImplementedTypeGetsARowOrderedByUriThenSlug(): void {
		$this->schemas->method('findAll')->willReturn([
			$this->schema(id: 9, slug: 'vendor', configuration: ['implements' => [self::VENDOR, self::ORG]]),
			$this->schema(id: 2, slug: 'agency', configuration: ['jsonld' => ['type' => self::ORG]]),
		]);

		$rows = $this->catalogue()->list();

		$this->assertSame(
			[[self::VENDOR, 'vendor'], [self::ORG, 'agency'], [self::ORG, 'vendor']],
			array_map(static fn (array $row): array => [$row['uri'], $row['schemaSlug']], $rows)
		);
	}//end testEveryImplementedTypeGetsARowOrderedByUriThenSlug()

	public function testTheListIsCachedPerUserUntilCleared(): void {
		$this->schemas->expects($this->exactly(3))->method('findAll')->willReturn([
			$this->schema(id: 2, slug: 'agency', configuration: ['jsonld' => ['type' => self::ORG]]),
		]);
		$catalogue = $this->catalogue();

		$first = $catalogue->list();
		$this->assertSame($first, $catalogue->list());
		$this->assertArrayHasKey('providers:alice', $this->store);

		$this->uid = 'bob';
		$catalogue->list();
		$this->assertArrayHasKey('providers:bob', $this->store);

		$catalogue->clear();
		$this->assertSame([], $this->store);
		$this->uid = 'alice';
		$this->assertSame($first, $catalogue->list());
	}//end testTheListIsCachedPerUserUntilCleared()

	public function testAFailingEnumerationAdvertisesNoProviders(): void {
		$this->schemas->method('findAll')->willThrowException(new RuntimeException('database gone'));

		$this->assertSame([], $this->catalogue()->list());
	}//end testAFailingEnumerationAdvertisesNoProviders()

	public function testWithoutAUserTheAnonymousKeyIsUsed(): void {
		$this->uid = null;
		$this->schemas->method('findAll')->willReturn([]);

		$this->assertSame([], $this->catalogue()->list());
		$this->assertSame(['providers:' => []], $this->store);
	}//end testWithoutAUserTheAnonymousKeyIsUsed()
}//end class
