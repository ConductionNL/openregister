<?php

/**
 * Regression test: a slug two registers share resolves to the named register's schema.
 *
 * Slugs are unique within a register, never across the instance. Measured on
 * the dev instance 2026-08-13: hermiq and openbuild both carry a schema with
 * the slug `agent`, and a global lookup returned openbuild's. The scoped lookup
 * must return the register's own schema, and must keep doing so whichever of
 * the two lookups runs first in a request: a request cache keyed on the bare
 * slug would hand the first answer to the second lookup.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Db
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\SchemaMapper\SchemaMapperServices;
use OCA\OpenRegister\Db\Tenancy\TenancyServices;
use OCA\OpenRegister\Service\Schemas\PropertyValidatorHandler;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Db\SchemaMapper
 * @uses   \OCA\OpenRegister\Db\SchemaMapper\SchemaLookup
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Service\WritePhaseProbe
 * @uses   \OCA\OpenRegister\Db\MultiTenancyTrait
 * @uses   \OCA\OpenRegister\Service\OrganisationContext
 * @uses \OCA\OpenRegister\Db\SchemaMapper\SchemaComposition
 * @uses \OCA\OpenRegister\Db\SchemaMapper\ParentReference
 * @uses \OCA\OpenRegister\Db\SchemaMapper\PropertyMerge
 * @uses \OCA\OpenRegister\Db\SchemaMapper\PropertyDelta
 * @uses \OCA\OpenRegister\Db\SchemaMapper\ExtendedByReads
 * @uses \OCA\OpenRegister\Db\MappingMapper
 * @uses \OCA\OpenRegister\Db\SchemaMapper\SchemaMapperServices
 * @uses \OCA\OpenRegister\Db\SchemaMapper\SchemaReadTrace
 * @uses \OCA\OpenRegister\Db\Tenancy\OrganisationFilterOptions
 * @uses \OCA\OpenRegister\Db\Tenancy\TenancyServices
 * @uses \OCA\OpenRegister\Service\WritePhase
 * @uses \OCA\OpenRegister\Db\Tenancy\OrganisationFilterTrait
 * @uses \OCA\OpenRegister\Db\Tenancy\TenancyIdentityTrait
 *
 * @spec openspec/changes/register-scoped-schema-slug-resolution/specs/schema-slug-resolution/spec.md
 */
class SchemaMapperScopedSlugTest extends TestCase {
	use RecordingQueryBuilderTrait;

	/**
	 * Hermiq's `agent`, the schema the hermiq register lists.
	 *
	 * @var array<string, mixed>
	 */
	private const HERMIQ = ['id' => 11, 'uuid' => 'uuid-hermiq-agent', 'slug' => 'agent', 'title' => 'Agent', 'application' => 'hermiq'];

	/**
	 * Openbuild's `agent`, the row a global lookup happens to fetch first.
	 *
	 * @var array<string, mixed>
	 */
	private const OPENBUILD = ['id' => 22, 'uuid' => 'uuid-openbuild-agent', 'slug' => 'agent', 'title' => 'Agent', 'application' => 'openbuild'];

	/**
	 * The hermiq register's schema ids.
	 *
	 * @var list<int>
	 */
	private const HERMIQ_SCHEMAS = [11, 12, 13];

	public function testAScopedSlugResolvesToTheRegistersOwnSchema(): void {
		$this->queuedRows = [[self::HERMIQ]];

		$schema = $this->mapper()->findBySlugInIds('agent', self::HERMIQ_SCHEMAS);

		$this->assertSame(11, $schema?->getId(), 'expected hermiq\'s agent (11), got ' . var_export($schema?->getId(), true));
		$this->assertCount(1, $this->executedQueries());
	}//end testAScopedSlugResolvesToTheRegistersOwnSchema()

	public function testGlobalThenScopedInOneRequestStillReturnsTheRegistersSchema(): void {
		$this->queuedRows = [[self::OPENBUILD, self::HERMIQ], [self::HERMIQ]];
		$mapper = $this->mapper();

		$global = $mapper->find('agent', [], false, false);
		$scoped = $mapper->findBySlugInIds('agent', self::HERMIQ_SCHEMAS);

		$this->assertSame(22, $global->getId());
		$this->assertSame(11, $scoped?->getId(), 'the global answer leaked into the scoped lookup: got ' . var_export($scoped?->getId(), true));
	}//end testGlobalThenScopedInOneRequestStillReturnsTheRegistersSchema()

	public function testScopedThenGlobalInOneRequestDoesNotSeedTheGlobalCache(): void {
		$this->queuedRows = [[self::HERMIQ], [self::OPENBUILD, self::HERMIQ]];
		$mapper = $this->mapper();

		$scoped = $mapper->findBySlugInIds('agent', self::HERMIQ_SCHEMAS);
		$global = $mapper->find('agent', [], false, false);

		$this->assertSame(11, $scoped?->getId());
		$this->assertSame(22, $global->getId(), 'the scoped answer was served from the cache to a global lookup');
		$this->assertCount(2, $this->executedQueries(), 'the global lookup must query, not read the scoped answer back');
	}//end testScopedThenGlobalInOneRequestDoesNotSeedTheGlobalCache()

	public function testASlugTheRegisterDoesNotCarryIsNullSoTheCallerFallsThrough(): void {
		$this->queuedRows = [[]];

		$this->assertNull($this->mapper()->findBySlugInIds('agent', self::HERMIQ_SCHEMAS));
	}//end testASlugTheRegisterDoesNotCarryIsNullSoTheCallerFallsThrough()

	public function testARegisterWithoutSchemasNeverQueries(): void {
		$this->assertNull($this->mapper()->findBySlugInIds('agent', []));
		$this->assertNull($this->mapper()->findBySlugInIds('agent', ['x', 0, -3]));
		$this->assertSame([], $this->executedQueries());
	}//end testARegisterWithoutSchemasNeverQueries()

	/**
	 * The executeQuery() calls recorded so far.
	 *
	 * @return list<mixed>
	 */
	private function executedQueries(): array {
		return array_values(
			array_filter(
				$this->queryLog,
				static fn ($entry): bool => str_contains(json_encode($entry), 'executeQuery') === true
			)
		);
	}//end executedQueries()

	private function mapper(): SchemaMapper {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn () => $this->recordingBuilder());

		return new SchemaMapper(
			db: $db,
			tenancy: new TenancyServices(
				organisationMapper: $this->createMock(OrganisationMapper::class),
				userSession: $this->createMock(IUserSession::class),
				groupManager: $this->createMock(IGroupManager::class)
			),
			appConfig: $this->createMock(IAppConfig::class),
			services: new SchemaMapperServices(
				eventDispatcher: $this->createMock(IEventDispatcher::class),
				validator: $this->createMock(PropertyValidatorHandler::class),
				logger: $this->createMock(LoggerInterface::class)
			)
		);
	}//end mapper()
}//end class
