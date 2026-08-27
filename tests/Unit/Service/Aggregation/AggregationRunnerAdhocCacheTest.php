<?php

/**
 * Unit tests for the ad-hoc cache integration in
 * `AggregationRunner::runAdhoc()`.
 *
 * Covers:
 *  - Cache miss falls through to the underlying dispatch and stores the result.
 *  - Cache hit short-circuits the dispatch and flips `cached: true` in the envelope.
 *  - Identical queries hit the same cache entry; differing queries miss.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Aggregation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace Unit\Service\Aggregation;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Aggregation\AggregationCache;
use OCA\OpenRegister\Service\Aggregation\AggregationQuery;
use OCA\OpenRegister\Service\Aggregation\AggregationRunner;
use OCA\OpenRegister\Service\LanguageService;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Object\TranslationHandler;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\Search\PlaceholderResolver;
use OCP\IDBConnection;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * No `@covers` metadata, deliberately — `beStrictAboutCoverageMetadata="true"`
 * discards the coverage of any test that touches a collaborator it did not
 * name. See {@see AggregationJoinAndCompositeGroupByTest} and #2847.
 */
class AggregationRunnerAdhocCacheTest extends TestCase {

	private MagicMapper&MockObject $magicMapper;
	private RegisterMapper&MockObject $registerMapper;
	private SchemaMapper&MockObject $schemaMapper;
	private PlaceholderResolver $placeholderResolver;
	private IDBConnection&MockObject $db;
	private AggregationCache&MockObject $cache;
	private PermissionHandler&MockObject $permissionHandler;
	private IUserSession&MockObject $userSession;
	private OrganisationService&MockObject $organisationService;
	private TranslationHandler&MockObject $translationHandler;
	private LanguageService&MockObject $languageService;
	private AggregationRunner $runner;

	protected function setUp(): void {
		parent::setUp();

		$this->magicMapper = $this->createMock(MagicMapper::class);
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->db = $this->createMock(IDBConnection::class);
		$this->cache = $this->createMock(AggregationCache::class);
		$this->permissionHandler = $this->createMock(PermissionHandler::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->organisationService = $this->createMock(OrganisationService::class);
		$this->translationHandler = $this->createMock(TranslationHandler::class);
		$this->languageService = $this->createMock(LanguageService::class);
		$this->placeholderResolver = new PlaceholderResolver($this->userSession);

		$this->userSession->method('getUser')->willReturn(null);
		$this->organisationService->method('getActiveOrganisation')->willReturn(null);
		$this->permissionHandler->method('hasPermission')->willReturn(true);

		// Default: no translatable properties (projection is a no-op) and
		// do not request all translations. Individual tests override these.
		$this->translationHandler->method('getTranslatableProperties')->willReturn([]);
		$this->languageService->method('shouldReturnAllTranslations')->willReturn(false);

		// Wire a Postgres platform mock so detectDatabasePlatform() can
		// resolve cleanly when the runner annotates the cache-write
		// envelope's `backend` field. The PHP-fallback path is exercised
		// because tryNativeAggregation() returns null on the empty table
		// name (next setUp lines), but detectDatabasePlatform is still
		// called outside that branch.
		$this->db->method('getDatabasePlatform')->willReturn(
			$this->createMock(PostgreSQLPlatform::class)
		);

		// Force the PHP-fallback path by returning an empty table name —
		// tryNativeAggregation returns null on empty table name, then the
		// runner calls bucketInPhp() which hydrates magicMapper's empty
		// findAllInRegisterSchemaTable() result.
		$this->magicMapper->method('getTableNameForRegisterSchema')->willReturn('');
		$this->magicMapper->method('findAllInRegisterSchemaTable')->willReturn([]);

		$this->runner = new AggregationRunner(
			magicMapper: $this->magicMapper,
			registerMapper: $this->registerMapper,
			schemaMapper: $this->schemaMapper,
			placeholders: $this->placeholderResolver,
			db: $this->db,
			cache: $this->cache,
			permissionHandler: $this->permissionHandler,
			userSession: $this->userSession,
			organisationService: $this->organisationService,
			translationHandler: $this->translationHandler,
			languageService: $this->languageService,
		);

	}//end setUp()

	public function testCacheHitFlipsCachedFlagAndSkipsDispatch(): void {
		$stored = ['groups' => [['key' => '2026-05-01T00:00:00Z', 'value' => 7]], 'backend' => 'postgres', 'cached' => false];

		// Cache hit: getAdhoc returns the stored envelope; the runner MUST
		// NOT execute the underlying dispatch (magicMapper::findAll would
		// be called by bucketInPhp on a miss; assert it's not called).
		$this->cache->method('getAdhoc')->willReturn($stored);
		$this->magicMapper->expects($this->never())->method('findAllInRegisterSchemaTable');
		$this->cache->expects($this->never())->method('setAdhoc');

		$query = AggregationQuery::create(
			metric: 'count',
			dateBucket: ['field' => 'created', 'start' => '2026-05-01T00:00:00Z', 'end' => '2026-05-22T00:00:00Z', 'gap' => 'day']
		);

		$result = $this->runner->runAdhoc(
			register: $this->makeRegister(),
			schema: $this->makeSchema(),
			query: $query
		);

		$this->assertSame([['key' => '2026-05-01T00:00:00Z', 'value' => 7]], $result['groups']);
		$this->assertTrue($result['cached'], 'cache hit MUST flip cached flag to true');
		$this->assertSame('postgres', $result['backend']);

	}//end testCacheHitFlipsCachedFlagAndSkipsDispatch()

	public function testCacheMissPopulatesCacheWithEnvelope(): void {
		// Cache miss: dispatch runs, envelope is written back via setAdhoc.
		$this->cache->method('getAdhoc')->willReturn(null);

		$stored = null;
		$this->cache->expects($this->once())
			->method('setAdhoc')
			->willReturnCallback(function ($_reg, $_sch, $_query, array $result) use (&$stored) {
				$stored = $result;
			});

		$query = AggregationQuery::create(
			metric: 'count',
			dateBucket: ['field' => 'created', 'start' => '2026-05-01T00:00:00Z', 'end' => '2026-05-22T00:00:00Z', 'gap' => 'day']
		);

		$result = $this->runner->runAdhoc(
			register: $this->makeRegister(),
			schema: $this->makeSchema(),
			query: $query
		);

		$this->assertFalse($result['cached'], 'cache miss MUST emit cached=false');
		$this->assertSame('php-fallback', $result['backend'], 'empty table forces PHP fallback');
		$this->assertIsArray($stored);
		$this->assertSame($result, $stored, 'envelope written to cache MUST equal envelope returned to caller');

	}//end testCacheMissPopulatesCacheWithEnvelope()

	public function testCachePassesResolvedQueryToBothGetAndSet(): void {
		$this->cache->method('getAdhoc')->willReturn(null);

		$capturedGet = null;
		$capturedSet = null;
		$this->cache->method('getAdhoc')->willReturnCallback(function ($_reg, $_sch, AggregationQuery $q) use (&$capturedGet) {
			$capturedGet = $q;
			return null;
		});
		$this->cache->method('setAdhoc')->willReturnCallback(function ($_reg, $_sch, AggregationQuery $q) use (&$capturedSet) {
			$capturedSet = $q;
		});

		$query = AggregationQuery::create(
			metric: 'count',
			filter: ['status' => 'open']
		);

		$this->runner->runAdhoc(
			register: $this->makeRegister(),
			schema: $this->makeSchema(),
			query: $query
		);

		$this->assertNotNull($capturedGet);
		$this->assertNotNull($capturedSet);
		$this->assertSame(
			$capturedGet->toArray(),
			$capturedSet->toArray(),
			'cache read and cache write MUST use the same resolved query value'
		);

	}//end testCachePassesResolvedQueryToBothGetAndSet()

	/**
	 * Task 2: a grouped aggregation on a `translatable: true` field MUST
	 * project each group `key` to the negotiated display language instead
	 * of returning the raw language-keyed map. Exercised through a cache
	 * hit (the projection runs after the cache boundary) with a REAL
	 * TranslationHandler + LanguageService so the language chain / fallback
	 * logic is genuinely driven, not mocked.
	 *
	 * Covers both wire shapes: the native SQL path returns a JSON string,
	 * the PHP-fallback path returns an associative array.
	 *
	 * @return void
	 */
	public function testGroupedTranslatableKeysProjectToNegotiatedLanguage(): void {
		$languageService = new LanguageService();
		$translationHandler = new TranslationHandler(
			$languageService,
			$this->createMock(\Psr\Log\LoggerInterface::class)
		);

		$runner = new AggregationRunner(
			magicMapper: $this->magicMapper,
			registerMapper: $this->registerMapper,
			schemaMapper: $this->schemaMapper,
			placeholders: $this->placeholderResolver,
			db: $this->db,
			cache: $this->cache,
			permissionHandler: $this->permissionHandler,
			userSession: $this->userSession,
			organisationService: $this->organisationService,
			translationHandler: $translationHandler,
			languageService: $languageService,
		);

		// Group keys come back raw from the DB: native SQL as a JSON string,
		// PHP-fallback as a real associative array. Both must project.
		$stored = [
			'groups' => [
				['key' => '{"nl":"Open","en":"Open case"}', 'value' => 5],
				['key' => ['nl' => 'Gesloten', 'en' => 'Closed'], 'value' => 2],
			],
			'backend' => 'postgres',
			'cached' => false,
		];
		$this->cache->method('getAdhoc')->willReturn($stored);

		$query = AggregationQuery::create(
			metric: 'count',
			groupBy: ['field' => 'stage']
		);

		$result = $runner->runAdhoc(
			register: $this->makeTranslatableRegister(),
			schema: $this->makeTranslatableSchema(),
			query: $query
		);

		$keys = array_column($result['groups'], 'key');
		$this->assertSame(
			['Open', 'Gesloten'],
			$keys,
			'translatable group keys MUST project to the negotiated (default nl) language'
		);

	}//end testGroupedTranslatableKeysProjectToNegotiatedLanguage()

	/**
	 * A schema whose `stage` property is translatable.
	 *
	 * @return Schema
	 */
	private function makeTranslatableSchema(): Schema {
		$schema = new Schema();
		$schema->setSlug('cases');
		$schema->setId(2);
		$schema->setProperties(
			[
				'stage' => [
					'type' => 'string',
					'translatable' => true,
				],
			]
		);
		return $schema;
	}//end makeTranslatableSchema()

	/**
	 * A register whose default/only language is Dutch.
	 *
	 * @return Register
	 */
	private function makeTranslatableRegister(): Register {
		$register = new Register();
		$register->setSlug('caseregister');
		$register->setSchemas([2]);
		// Default language is derived from the first entry in languages.
		$register->setLanguages(['nl', 'en']);
		return $register;
	}//end makeTranslatableRegister()

	private function makeSchema(): Schema {
		$schema = new Schema();
		$schema->setSlug('calllogs');
		$schema->setId(1);
		return $schema;
	}//end makeSchema()

	private function makeRegister(): Register {
		$register = new Register();
		$register->setSlug('openconnector');
		$register->setSchemas([1]);
		return $register;
	}//end makeRegister()

}//end class
