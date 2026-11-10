<?php

/**
 * Characterisation tests for AggregationRunner's filter, join, bucket and source helpers.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Aggregation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Aggregation;

use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MagicMapper\MagicOrganizationHandler;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaCatalog;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Search\Service\Aggregation\AggregationCache;
use OCA\OpenRegister\Search\Service\Aggregation\AggregationQuery;
use OCA\OpenRegister\Search\Service\Search\PlaceholderResolver;
use OCA\OpenRegister\Service\Aggregation\AdhocAggregationRun;
use OCA\OpenRegister\Service\Aggregation\AggregationRunner;
use OCA\OpenRegister\Service\Aggregation\JoinedValueLookup;
use OCA\OpenRegister\Service\Aggregation\RequestedNarrowing;
use OCA\OpenRegister\Service\LanguageService;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Object\TranslationHandler;
use OCA\OpenRegister\Service\ObjectSource\DbalObjectSourceProvider;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\IDBConnection;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Pins the branches of mergeNarrowingFilter(), mergeJoinedValues(),
 * resolveAtSelfReferences(), coerceBucketKey(), findRegisterForSchema(),
 * tryDbalAggregation() and dbalSourceConfig() that the existing suites leave
 * open. The one timezone-sensitive case sets the zone itself and restores it.
 */
class AggregationRunnerHelperCharacterisationTest extends TestCase {

	private RegisterMapper&MockObject $registerMapper;

	private LoggerInterface&MockObject $logger;

	private string $timezone;

	protected function setUp(): void {
		parent::setUp();
		$this->timezone = date_default_timezone_get();
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	protected function tearDown(): void {
		date_default_timezone_set($this->timezone);
		parent::tearDown();
	}

	private function runner(?DbalObjectSourceProvider $dbal = null): AggregationRunner {
		$session = $this->createMock(IUserSession::class);
		return new AggregationRunner(
			catalog: new SchemaCatalog(registers: $this->registerMapper, schemas: $this->createMock(SchemaMapper::class), tables: $this->createMock(MagicMapper::class)),
			placeholders: new PlaceholderResolver($session),
			db: $this->createMock(IDBConnection::class),
			cache: $this->createMock(AggregationCache::class),
			permissionHandler: $this->createMock(PermissionHandler::class),
			userSession: $session,
			organisationService: $this->createMock(OrganisationService::class),
			organizationHandler: $this->createMock(MagicOrganizationHandler::class),
			translationHandler: $this->createMock(TranslationHandler::class),
			languageService: $this->createMock(LanguageService::class),
			logger: $this->logger,
			dbalSourceProvider: $dbal
		);
	}

	private function call(AggregationRunner $runner, string $method, mixed ...$args): mixed {
		return (new ReflectionMethod(AggregationRunner::class, $method))->invoke($runner, ...$args);
	}

	// ── mergeNarrowingFilter ──

	public function testNoConstraintsReturnTheDeclaredFilter(): void {
		$this->logger->expects($this->never())->method('debug');

		$this->assertSame(['a' => 1], $this->call($this->runner(), 'mergeNarrowingFilter', ['a' => 1], new RequestedNarrowing(constraints: [], aggregation: 'x')));
	}

	public function testOnlyNewNamedScalarConstraintsAreMergedInOrder(): void {
		$this->logger->expects($this->once())->method('debug')->with(
			'Aggregation: refusing a request filter that would relax a declared one.',
			['aggregation' => 'agg', 'key' => 'status']
		);
		$narrowing = new RequestedNarrowing(
			constraints: ['' => 'x', 0 => 'y', 'status' => 'closed', 'kind' => ['ne' => 'a'], 'empty' => '', 'year' => 2026, 'flag' => false, 'code' => 'c'],
			aggregation: 'agg'
		);

		$merged = $this->call($this->runner(), 'mergeNarrowingFilter', ['status' => 'open'], $narrowing);

		$this->assertSame(['status' => 'open', 'year' => 2026, 'flag' => false, 'code' => 'c'], $merged);
	}

	// ── mergeJoinedValues ──

	public function testJoinedValuesAreLookedUpByKeysOrKeyAndAMissIsNull(): void {
		$lookup = new JoinedValueLookup(
			onMap: ['code' => 'id'],
			aliases: ['label', 'total'],
			values: ['A' => ['label' => 'Alpha', 'total' => 3], 'B' => ['label' => 'Beta']]
		);
		$groups = [
			'scalar',
			['keys' => ['code' => 'A'], 'key' => 'B'],
			['keys' => 'not-a-map', 'key' => 'B'],
			['key' => 'Z'],
			[],
		];

		$merged = $this->call($this->runner(), 'mergeJoinedValues', $groups, $lookup);

		$this->assertSame('scalar', $merged[0]);
		$this->assertSame(['label' => 'Alpha', 'total' => 3], $merged[1]['joined']);
		$this->assertSame(['label' => 'Beta', 'total' => null], $merged[2]['joined']);
		$this->assertSame(['label' => null, 'total' => null], $merged[3]['joined']);
		$this->assertSame(['joined' => ['label' => null, 'total' => null]], $merged[4]);
	}

	// ── resolveAtSelfReferences ──

	public function testSelfReferencesResolveInNestedMapsAndOtherValuesStay(): void {
		$where = ['code' => '@self.code', 'range' => ['ne' => '@self.code', 'deep' => ['gte' => '@self.from']], 'n' => 7, 'nil' => '@self.none', 'plain' => 'self.code', 'flag' => true];

		$resolved = $this->call($this->runner(), 'resolveAtSelfReferences', $where, ['code' => 'K1', 'from' => 3, 'none' => null]);

		$this->assertSame(['code' => 'K1', 'range' => ['ne' => 'K1', 'deep' => ['gte' => 3]], 'n' => 7, 'nil' => null, 'plain' => 'self.code', 'flag' => true], $resolved);
	}

	// ── coerceBucketKey ──

	public function testBucketKeysOfEveryShape(): void {
		$runner = $this->runner();

		$this->assertSame('2026-05-01T10:00:00Z', $this->call($runner, 'coerceBucketKey', new DateTimeImmutable('2026-05-01 10:00:00', new DateTimeZone('UTC'))));
		$this->assertSame('2026-05-01T10:00:00Z', $this->call($runner, 'coerceBucketKey', new DateTimeImmutable('2026-05-01 10:00:00+02:00')), 'formatted as is, not converted');
		$this->assertSame('7', $this->call($runner, 'coerceBucketKey', 7));
		$this->assertSame('', $this->call($runner, 'coerceBucketKey', null));
		$this->assertSame('not a date', $this->call($runner, 'coerceBucketKey', 'not a date'));
		$this->assertSame('2026-05-01T08:00:00Z', $this->call($runner, 'coerceBucketKey', '2026-05-01 10:00:00+0200'));
	}

	public function testAnOffsetLessBucketIsReadAsUtcWhateverTheServerZone(): void {
		date_default_timezone_set('Europe/Amsterdam');
		$runner = $this->runner();

		$this->assertSame('2026-05-01T10:30:15Z', $this->call($runner, 'coerceBucketKey', ' 2026-05-01 10:30:15 '));
		$this->assertSame('2026-05-01T00:00:00Z', $this->call($runner, 'coerceBucketKey', '2026-05-01'));
		$this->assertSame('2026-05-01T08:30:15Z', $this->call($runner, 'coerceBucketKey', '2026-05-01T10:30:15+02:00'));
		$this->assertSame('2026-05-01T08:30:15Z', $this->call($runner, 'coerceBucketKey', '2026-05-01 10:30:15 Europe/Amsterdam'));
	}

	// ── findRegisterForSchema ──

	public function testASchemaWithoutIdHasNoRegister(): void {
		$this->registerMapper->expects($this->never())->method('findAll');

		$this->assertNull($this->call($this->runner(), 'findRegisterForSchema', new Schema()));
	}

	public function testTheFirstRegisterListingTheSchemaIdWins(): void {
		$other = new Register();
		$other->setSchemas([1, 2]);
		$match = new Register();
		$match->setSchemas(['4', 5]);
		$later = new Register();
		$later->setSchemas([4]);
		$this->registerMapper->expects($this->exactly(2))->method('findAll')->willReturnOnConsecutiveCalls([$other, $match, $later], [$other]);
		$schema = new Schema();
		$schema->setId(4);

		$this->assertSame($match, $this->call($this->runner(), 'findRegisterForSchema', $schema));
		$this->assertNull($this->call($this->runner(), 'findRegisterForSchema', $schema));
	}

	// ── tryDbalAggregation / dbalSourceConfig ──

	private function adhocRun(Schema $schema, string $metric = 'count'): AdhocAggregationRun {
		$query = AggregationQuery::create(metric: $metric);

		return new AdhocAggregationRun(register: new Register(), schema: $schema, query: $query, resolvedQuery: $query);
	}

	private function schemaWith(mixed $configuration): Schema {
		$schema = $this->createMock(Schema::class);
		$schema->method('getConfiguration')->willReturn($configuration);

		return $schema;
	}

	public function testNoDbalProviderMeansNoDbalAggregation(): void {
		$schema = $this->schemaWith(['x-openregister-object-source' => ['provider' => 'dbal-source', 'config' => []]]);
		$this->logger->expects($this->never())->method('warning');

		$this->assertNull($this->call($this->runner(), 'tryDbalAggregation', $this->adhocRun($schema)));
	}

	public function testOnlyADbalSourceSchemaIsAggregatedByTheProvider(): void {
		$provider = $this->createMock(DbalObjectSourceProvider::class);
		$provider->expects($this->once())->method('aggregate')
			->with($this->isInstanceOf(Register::class), $this->isInstanceOf(Schema::class), 'count', null, [], null, null, ['dsn' => 'x'])
			->willReturn(['value' => 5]);
		$this->logger->expects($this->never())->method('warning');
		$runner = $this->runner($provider);

		foreach ([null, ['x-openregister-object-source' => 'dbal-source'], ['x-openregister-object-source' => ['provider' => 'rest']], ['x-openregister-object-source' => ['provider' => 'dbal-source', 'config' => 'dsn']]] as $configuration) {
			$this->assertNull($this->call($runner, 'tryDbalAggregation', $this->adhocRun($this->schemaWith($configuration))));
		}

		$schema = $this->schemaWith(['x-openregister-object-source' => ['provider' => 'dbal-source', 'config' => ['dsn' => 'x']]]);
		$this->assertSame(['value' => 5], $this->call($runner, 'tryDbalAggregation', $this->adhocRun($schema)));
	}

	public function testADbalSourceWithoutConfigAggregatesWithAnEmptyOne(): void {
		$this->assertSame([], $this->call($this->runner(), 'dbalSourceConfig', $this->schemaWith(['x-openregister-object-source' => ['provider' => 'dbal-source']])));
	}
}//end class
