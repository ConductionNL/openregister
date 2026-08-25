<?php

/**
 * Unit tests for the composite-`groupBy` annotation vocabulary and the
 * `join` clause in `AggregationAnnotationValidator` + `AggregationRunner`.
 *
 * Three things are pinned here:
 *
 * 1. The legacy single-field `groupBy: {field: ...}` object form behaves
 *    EXACTLY as before — in the validator (same two error codes, same
 *    accept/reject verdicts) and in the runner (same `{key, value}` row
 *    shape, same translatable-key projection). This is the regression that
 *    matters most: the object form is live in other fleet apps.
 * 2. A composite `groupBy` naming several fields validates per member and
 *    projects EVERY translatable member of the `keys` map.
 * 3. `join` attaches aggregates from a second schema per group, is refused
 *    when the caller lacks `list` on the joined schema, and participates in
 *    the cache key.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Aggregation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/aggregation-api/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Aggregation;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Aggregation\AggregationAnnotationValidator;
use OCA\OpenRegister\Service\Aggregation\AggregationCache;
use OCA\OpenRegister\Service\Aggregation\AggregationRunner;
use OCA\OpenRegister\Service\LanguageService;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Object\TranslationHandler;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\Search\PlaceholderResolver;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use OCP\DB\IPreparedStatement;
use OCP\DB\IResult;
use OCP\IDBConnection;
use OCP\IUserSession;
use PDO;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\Aggregation\AggregationAnnotationValidator
 * @covers \OCA\OpenRegister\Service\Aggregation\AggregationRunner
 */
class AggregationJoinAndCompositeGroupByTest extends TestCase {

	private MagicMapper&MockObject $magicMapper;

	private RegisterMapper&MockObject $registerMapper;

	private SchemaMapper&MockObject $schemaMapper;

	private IDBConnection&MockObject $db;

	private AggregationCache&MockObject $cache;

	private PermissionHandler&MockObject $permissionHandler;

	private IUserSession&MockObject $userSession;

	private OrganisationService&MockObject $organisationService;

	private TranslationHandler&MockObject $translationHandler;

	private LanguageService&MockObject $languageService;

	/**
	 * Every `filter:` argument handed to AggregationCache::set(), in call
	 * order. This IS the cache key, so capturing it is the only honest way
	 * to assert two specs do not collide.
	 *
	 * @var array<int, mixed>
	 */
	private array $capturedCacheKeys = [];

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

		$this->userSession->method('getUser')->willReturn(null);
		$this->organisationService->method('getActiveOrganisation')->willReturn(null);
		$this->languageService->method('shouldReturnAllTranslations')->willReturn(false);

		// Cache always misses; every key handed to set() is recorded.
		$this->cache->method('get')->willReturn(null);
		$this->cache->method('set')->willReturnCallback(
			function (string $registerSlug, string $schemaSlug, string $name, mixed $filter, mixed $result): void {
				$this->capturedCacheKeys[] = $filter;
			}
		);

		// Register-scoped schema resolution, mirroring the production
		// resolver: a schema the register does not carry does not resolve.
		$this->schemaMapper->method('findInIds')->willReturnCallback(
			function (string|int $id, array $schemaIds): ?Schema {
				try {
					$schema = $this->schemaMapper->find($id, [], true, false);
				} catch (\Throwable $e) {
					return null;
				}

				if (($schema instanceof Schema) === false) {
					return null;
				}

				$normalised = array_map(static fn ($sid) => (int)$sid, $schemaIds);
				if (in_array((int)$schema->getId(), $normalised, true) === false) {
					return null;
				}

				return $schema;
			}
		);
	}//end setUp()

	// ------------------------------------------------------------------
	// Validator — the legacy object form is untouched.
	// ------------------------------------------------------------------

	/**
	 * REGRESSION GUARD. The single-field `groupBy: {field}` object form must
	 * behave exactly as it did before composite groupBy existed: a declared
	 * field validates clean, an undeclared one raises
	 * `aggregation-groupby-field-unknown` and NOTHING else.
	 */
	public function testLegacyObjectGroupByFormIsUnchanged(): void {
		$validator = new AggregationAnnotationValidator();

		$ok = $validator->validate($this->schemaDefinition(
			['byStatus' => ['metric' => 'count', 'groupBy' => ['field' => 'programme']]]
		));
		$this->assertSame([], $ok, 'a declared single groupBy field MUST still validate clean');

		$bad = $validator->validate($this->schemaDefinition(
			['byStatus' => ['metric' => 'count', 'groupBy' => ['field' => 'nope']]]
		));
		$this->assertSame(
			['aggregation-groupby-field-unknown'],
			$this->codes($bad),
			'an undeclared single groupBy field MUST still raise exactly the legacy code'
		);
	}//end testLegacyObjectGroupByFormIsUnchanged()

	/**
	 * A composite groupBy over declared properties validates clean in both
	 * the plain-list and the {fields: [...]} spellings.
	 */
	public function testCompositeGroupByOverDeclaredFieldsValidates(): void {
		$validator = new AggregationAnnotationValidator();

		$list = $validator->validate($this->schemaDefinition(
			['x' => ['metric' => 'sum', 'field' => 'remainingCommitted', 'groupBy' => ['programme', 'costCentre']]]
		));
		$this->assertSame([], $list, 'a plain-list composite groupBy MUST validate clean');

		$fields = $validator->validate($this->schemaDefinition(
			[
				'x' => [
					'metric' => 'sum',
					'field' => 'remainingCommitted',
					'groupBy' => ['fields' => ['programme', 'costCentre']],
				],
			]
		));
		$this->assertSame([], $fields, 'a {fields: [...]} composite groupBy MUST validate clean');
	}//end testCompositeGroupByOverDeclaredFieldsValidates()

	/**
	 * A composite groupBy naming an UNDECLARED property is rejected — the
	 * per-member check the single-field form has always applied, now applied
	 * to every member so a composite cannot smuggle an unknown column past
	 * the validator and into `GROUP BY`.
	 */
	public function testCompositeGroupByRejectsUndeclaredProperty(): void {
		$validator = new AggregationAnnotationValidator();

		$errors = $validator->validate($this->schemaDefinition(
			['x' => ['metric' => 'count', 'groupBy' => ['programme', 'notAProperty', 'costCentre']]]
		));

		$this->assertSame(['aggregation-groupby-field-unknown'], $this->codes($errors));
		$this->assertStringContainsString('notAProperty', $errors[0]['message']);
	}//end testCompositeGroupByRejectsUndeclaredProperty()

	/**
	 * The same field named twice is a declaration bug, not a silent no-op.
	 */
	public function testCompositeGroupByRejectsDuplicateField(): void {
		$validator = new AggregationAnnotationValidator();

		$errors = $validator->validate($this->schemaDefinition(
			['x' => ['metric' => 'count', 'groupBy' => ['programme', 'programme']]]
		));

		$this->assertSame(['aggregation-groupby-duplicate-field'], $this->codes($errors));
	}//end testCompositeGroupByRejectsDuplicateField()

	// ------------------------------------------------------------------
	// Validator — join.
	// ------------------------------------------------------------------

	/**
	 * The shillinq `committedVsRealisedPerBudgetLine` join shape validates
	 * clean once the metric/field vocabulary is spelled the engine's way.
	 */
	public function testJoinSpecValidates(): void {
		$validator = new AggregationAnnotationValidator();

		$errors = $validator->validate($this->schemaDefinition(['committed' => $this->joinAnnotation()]));

		$this->assertSame([], $errors);
	}//end testJoinSpecValidates()

	/**
	 * A join with no groupBy has nothing to attach its values to, and is
	 * refused at save time rather than throwing on every call.
	 */
	public function testJoinWithoutGroupByIsRejected(): void {
		$validator = new AggregationAnnotationValidator();

		$spec = $this->joinAnnotation();
		unset($spec['groupBy']);

		$this->assertContains(
			'aggregation-join-requires-groupby',
			$this->codes($validator->validate($this->schemaDefinition(['committed' => $spec])))
		);
	}//end testJoinWithoutGroupByIsRejected()

	/**
	 * `join` beside `from` names three schemas with no declared relationship
	 * between the second and third, and the cross-schema runner has no join
	 * stage — so it is refused rather than silently half-executed.
	 */
	public function testJoinCombinedWithFromIsRejected(): void {
		$validator = new AggregationAnnotationValidator();

		$errors = $validator->validate($this->schemaDefinition(
			[
				'committed' => [
					'from' => 'other-schema',
					'select' => 'count',
					'join' => ['through' => 'x', 'on' => 'y', 'select' => ['z']],
				],
			]
		));

		$this->assertContains('aggregation-join-with-from', $this->codes($errors));
	}//end testJoinCombinedWithFromIsRejected()

	/**
	 * An empty / missing `select` list means the join would attach nothing.
	 */
	public function testJoinWithoutSelectIsRejected(): void {
		$validator = new AggregationAnnotationValidator();

		$spec = $this->joinAnnotation();
		$spec['join']['select'] = [];

		$this->assertContains(
			'aggregation-join-select-empty',
			$this->codes($validator->validate($this->schemaDefinition(['committed' => $spec])))
		);
	}//end testJoinWithoutSelectIsRejected()

	/**
	 * An explicit `{parentField: joinedField}` on-map is checked on its
	 * parent half — the joined half cannot be checked here because the
	 * joined schema is not loadable at annotation-save time.
	 */
	public function testJoinOnMapRejectsUndeclaredParentField(): void {
		$validator = new AggregationAnnotationValidator();

		$spec = $this->joinAnnotation();
		$spec['join']['on'] = ['notAProperty' => 'programmeCode'];

		$this->assertContains(
			'aggregation-join-on-field-unknown',
			$this->codes($validator->validate($this->schemaDefinition(['committed' => $spec])))
		);
	}//end testJoinOnMapRejectsUndeclaredParentField()

	// ------------------------------------------------------------------
	// Runner — join behaviour.
	// ------------------------------------------------------------------

	/**
	 * A join attaches the joined schema's aggregate to every parent group
	 * sharing the join key.
	 *
	 * Note the deliberate fan-out: both P1 groups (P1/C1 and P1/C2) receive
	 * the SAME authorised amount, because the budget is declared per
	 * programme while the parent groups per (programme, costCentre). The
	 * join repeats the joined value across the finer groups; it does not
	 * divide it. A group with no matching joined row gets an explicit null
	 * rather than a missing key.
	 */
	public function testJoinAttachesAggregatesPerGroup(): void {
		$runner = $this->makeJoinRunner();

		$result = $runner->run(
			registerRef: 'finance',
			schemaRef: 'commitment-line',
			name: 'committed'
		);

		$this->assertSame('php-fallback', $result['backend']);
		$this->assertSame('commitment-budget', $result['join']['through']);
		$this->assertSame(['programme' => 'programmeCode'], $result['join']['on']);

		$folded = [];
		foreach ($result['groups'] as $group) {
			$key = $group['keys']['programme'] . '|' . $group['keys']['costCentre'];
			$folded[$key] = [
				'value' => $group['value'],
				'joined' => $group['joined']['commitment-budget.authorisedAmount'],
			];
		}

		ksort($folded);
		$this->assertSame(
			[
				'P1|C1' => ['value' => 100.0, 'joined' => 1000.0],
				'P1|C2' => ['value' => 50.0, 'joined' => 1000.0],
				'P2|C1' => ['value' => 200.0, 'joined' => 2000.0],
				'P3|C9' => ['value' => 7.0, 'joined' => null],
			],
			$folded
		);
	}//end testJoinAttachesAggregatesPerGroup()

	/**
	 * The NATIVE path produces the same joined envelope as the PHP fallback
	 * on the same data.
	 *
	 * The two paths read the join key from different places — the native one
	 * out of a DB driver (where a value arrives as a string), the fallback
	 * out of decoded JSON — so this is the test that would catch the merge
	 * key silently matching nothing on one path.
	 */
	public function testNativeJoinAgreesWithPhpFallback(): void {
		$php = $this->makeJoinRunner()->run(
			registerRef: 'finance',
			schemaRef: 'commitment-line',
			name: 'committed'
		);

		// Fresh mocks: the SQLite-backed runner needs its own DB stub.
		$this->setUp();
		$native = $this->makeNativeJoinRunner()->run(
			registerRef: 'finance',
			schemaRef: 'commitment-line',
			name: 'committed'
		);

		// assertEquals, not assertSame, and deliberately so: the two paths
		// agree on every NUMBER and on every join match (including the P3
		// miss), but disagree on the PHP numeric TYPE — SQLite hands back
		// an int for SUM over an INTEGER column while the PHP reducer
		// always yields float. That divergence is PRE-EXISTING in the
		// grouped aggregate itself (tryNativeAggregation() only casts to
		// float when the driver returned a string, which Postgres does and
		// SQLite does not); it is not introduced by the join and is not
		// silently blessed here. Asserting identity would either fail on
		// an unrelated defect or, if "fixed" by casting in the test,
		// pretend the engine agrees when it does not.
		$this->assertEquals(
			$this->foldJoined($php['groups']),
			$this->foldJoined($native['groups']),
			'the native and PHP-fallback join paths MUST agree on values and matches'
		);
		$this->assertEquals(
			[
				'P1|C1' => ['value' => 100, 'joined' => 1000],
				'P1|C2' => ['value' => 50, 'joined' => 1000],
				'P2|C1' => ['value' => 200, 'joined' => 2000],
				'P3|C9' => ['value' => 7, 'joined' => null],
			],
			$this->foldJoined($native['groups'])
		);

		// The join match itself IS pinned strictly: a group with no budget
		// row gets an explicit null, never a stray number from another key.
		$this->assertNull($this->foldJoined($native['groups'])['P3|C9']['joined']);
	}//end testNativeJoinAgreesWithPhpFallback()

	/**
	 * SECURITY. A join MUST NOT become a way to read a schema the caller
	 * cannot read. The caller holds `list` on the parent but not on the
	 * joined schema: the run is refused, and refused BEFORE any joined row
	 * is fetched.
	 */
	public function testJoinIsRefusedWhenCallerLacksListOnJoinedSchema(): void {
		$runner = $this->makeJoinRunner(
			joinedSchemaListAllowed: false,
			expectNoJoinedFetch: true
		);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Forbidden: caller lacks list permission on join target "commitment-budget"');

		$runner->run(
			registerRef: 'finance',
			schemaRef: 'commitment-line',
			name: 'committed'
		);
	}//end testJoinIsRefusedWhenCallerLacksListOnJoinedSchema()

	// ------------------------------------------------------------------
	// Runner — cache key separation.
	// ------------------------------------------------------------------

	/**
	 * SECURITY-RELEVANT. Two aggregations differing ONLY in `groupBy` must
	 * not share a cache entry — otherwise the second caller is served the
	 * first one's grouping.
	 */
	public function testCacheKeyDiffersOnGroupByAlone(): void {
		$one = ['metric' => 'sum', 'field' => 'remainingCommitted', 'groupBy' => ['programme']];
		$two = ['metric' => 'sum', 'field' => 'remainingCommitted', 'groupBy' => ['programme', 'costCentre']];

		$keys = $this->cacheKeysFor(specOne: $one, specTwo: $two);

		$this->assertNotEquals($keys[0], $keys[1], 'a differing groupBy MUST produce a differing cache key');
		$this->assertSame($keys[0]['metric'], $keys[1]['metric'], 'the control: everything else is identical');
	}//end testCacheKeyDiffersOnGroupByAlone()

	/**
	 * SECURITY-RELEVANT. Two aggregations differing ONLY in `join` must not
	 * share a cache entry — otherwise a spec that asked for no joined data
	 * can be served a cached envelope carrying values read out of a second
	 * schema.
	 */
	public function testCacheKeyDiffersOnJoinAlone(): void {
		$base = ['metric' => 'sum', 'field' => 'remainingCommitted', 'groupBy' => ['programme']];
		$withJoin = $base;
		$withJoin['join'] = [
			'through' => 'commitment-budget',
			'on' => 'commitment-budget.programmeCode',
			'select' => ['commitment-budget.authorisedAmount'],
		];

		$keys = $this->cacheKeysFor(specOne: $base, specTwo: $withJoin);

		$this->assertNotEquals($keys[0], $keys[1], 'a differing join MUST produce a differing cache key');
		$this->assertNull($keys[0]['join'], 'the join-less spec keys on a null join');
		$this->assertSame('commitment-budget', $keys[1]['join']['through']);
	}//end testCacheKeyDiffersOnJoinAlone()

	// ------------------------------------------------------------------
	// Runner — translatable key projection across both group shapes.
	// ------------------------------------------------------------------

	/**
	 * A composite groupBy projects EVERY translatable member of the `keys`
	 * map, and leaves non-translatable members byte-for-byte alone.
	 *
	 * Without the composite branch in projectTranslatableGroupKeys() the
	 * guard `isset($groupBy['field'])` is false for a list-form groupBy, the
	 * method early-returns, and the caller receives the raw language map.
	 */
	public function testCompositeGroupByProjectsEveryTranslatableKey(): void {
		$this->translationHandler->method('getTranslatableProperties')->willReturn(['programme']);
		$this->translationHandler->method('resolveTranslationsForRender')->willReturnCallback(
			static fn (array $objectData): array => array_map(
				static fn (mixed $map): mixed => (is_array($map) === true) ? ($map['nl'] ?? null) : $map,
				$objectData
			)
		);

		$runner = $this->makeTranslatableRunner(
			groupBy: ['programme', 'costCentre'],
			rows: [
				['programme' => ['nl' => 'Wonen', 'en' => 'Housing'], 'costCentre' => 'C1', 'remainingCommitted' => 10],
			]
		);

		$result = $runner->run(registerRef: 'finance', schemaRef: 'commitment-line', name: 'byTwo');

		$this->assertSame('Wonen', $result['groups'][0]['keys']['programme'], 'translatable member MUST be projected');
		$this->assertSame('C1', $result['groups'][0]['keys']['costCentre'], 'non-translatable member MUST be untouched');
	}//end testCompositeGroupByProjectsEveryTranslatableKey()

	/**
	 * REGRESSION GUARD. The single-field form still projects its scalar
	 * `key` and still emits no `keys` map.
	 */
	public function testSingleFieldGroupByStillProjectsScalarKey(): void {
		$this->translationHandler->method('getTranslatableProperties')->willReturn(['programme']);
		$this->translationHandler->method('resolveTranslationsForRender')->willReturnCallback(
			static fn (array $objectData): array => array_map(
				static fn (mixed $map): mixed => (is_array($map) === true) ? ($map['nl'] ?? null) : $map,
				$objectData
			)
		);

		$runner = $this->makeTranslatableRunner(
			groupBy: ['field' => 'programme'],
			rows: [
				['programme' => ['nl' => 'Wonen', 'en' => 'Housing'], 'costCentre' => 'C1', 'remainingCommitted' => 10],
			]
		);

		$result = $runner->run(registerRef: 'finance', schemaRef: 'commitment-line', name: 'byTwo');

		$this->assertSame('Wonen', $result['groups'][0]['key']);
		$this->assertArrayNotHasKey('keys', $result['groups'][0], 'single-field groups MUST NOT grow a `keys` map');
	}//end testSingleFieldGroupByStillProjectsScalarKey()

	// ------------------------------------------------------------------
	// Fixtures + helpers.
	// ------------------------------------------------------------------

	/**
	 * The shillinq-shaped join annotation, spelled in the engine's
	 * vocabulary (`metric`/`field` rather than `sum: [...]`).
	 *
	 * @return array<string, mixed>
	 */
	private function joinAnnotation(): array {
		return [
			'metric' => 'sum',
			'field' => 'remainingCommitted',
			'groupBy' => ['programme', 'costCentre'],
			'join' => [
				'through' => 'commitment-budget',
				'on' => 'commitment-budget.programmeCode',
				'filter' => [],
				'select' => ['commitment-budget.authorisedAmount'],
			],
		];
	}//end joinAnnotation()

	/**
	 * A schema definition carrying the given aggregation annotation.
	 *
	 * @param array<string, mixed> $aggregations The annotation map.
	 *
	 * @return array<string, mixed>
	 */
	private function schemaDefinition(array $aggregations): array {
		return [
			'properties' => [
				'programme' => ['type' => 'string'],
				'costCentre' => ['type' => 'string'],
				'remainingCommitted' => ['type' => 'number'],
			],
			'x-openregister-aggregations' => $aggregations,
		];
	}//end schemaDefinition()

	/**
	 * Reduce a validation error list to its codes.
	 *
	 * @param array<int, array{code: string, message: string}> $errors Error list.
	 *
	 * @return array<int, string>
	 */
	private function codes(array $errors): array {
		return array_map(static fn (array $error): string => $error['code'], $errors);
	}//end codes()

	/**
	 * Run two annotation specs through the runner and return the two cache
	 * keys they produced, in order.
	 *
	 * @param array<string, mixed> $specOne First aggregation spec.
	 * @param array<string, mixed> $specTwo Second aggregation spec.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function cacheKeysFor(array $specOne, array $specTwo): array {
		$runner = $this->makeJoinRunner(annotations: ['one' => $specOne, 'two' => $specTwo]);

		$runner->run(registerRef: 'finance', schemaRef: 'commitment-line', name: 'one');
		$runner->run(registerRef: 'finance', schemaRef: 'commitment-line', name: 'two');

		$this->assertCount(2, $this->capturedCacheKeys, 'both runs MUST have written a cache entry');
		return $this->capturedCacheKeys;
	}//end cacheKeysFor()

	/**
	 * Build a runner over a `commitment-line` parent and a
	 * `commitment-budget` join target, both on the PHP-fallback path.
	 *
	 * @param array<string, mixed>|null $annotations Override the parent annotation map.
	 * @param bool $joinedSchemaListAllowed Whether the caller holds `list` on the joined schema.
	 * @param bool $expectNoJoinedFetch Assert the joined table is never hydrated (RBAC refusal test).
	 *
	 * @return AggregationRunner
	 */
	private function makeJoinRunner(
		?array $annotations = null,
		bool $joinedSchemaListAllowed = true,
		bool $expectNoJoinedFetch = false,
	): AggregationRunner {
		$annotations = ($annotations ?? ['committed' => $this->joinAnnotation()]);

		$parentSchema = $this->makeSchema('commitment-line', 10, $annotations);
		$joinedSchema = $this->makeSchema('commitment-budget', 20);
		$register = $this->makeRegister('finance', [10, 20]);

		$this->registerMapper->method('find')->willReturn($register);
		$this->registerMapper->method('findAll')->willReturn([$register]);
		$this->schemaMapper->method('find')->willReturnMap(
			[
				['commitment-line', [], true, false, $parentSchema],
				['commitment-budget', [], true, false, $joinedSchema],
			]
		);

		$this->permissionHandler->method('hasPermission')->willReturnCallback(
			static function (Schema $schema) use ($joinedSchemaListAllowed): bool {
				if ((string)$schema->getSlug() === 'commitment-budget') {
					return $joinedSchemaListAllowed;
				}

				return true;
			}
		);

		$this->usePhpFallback();

		$parentRows = [
			['programme' => 'P1', 'costCentre' => 'C1', 'remainingCommitted' => 100],
			['programme' => 'P1', 'costCentre' => 'C2', 'remainingCommitted' => 50],
			['programme' => 'P2', 'costCentre' => 'C1', 'remainingCommitted' => 200],
			['programme' => 'P3', 'costCentre' => 'C9', 'remainingCommitted' => 7],
		];
		$joinedRows = [
			['programmeCode' => 'P1', 'authorisedAmount' => 1000],
			['programmeCode' => 'P2', 'authorisedAmount' => 2000],
		];

		$this->magicMapper->method('findAllInRegisterSchemaTable')->willReturnCallback(
			function (Register $register, Schema $schema) use ($parentRows, $joinedRows, $expectNoJoinedFetch): array {
				if ((string)$schema->getSlug() === 'commitment-budget') {
					if ($expectNoJoinedFetch === true) {
						$this->fail('the joined schema MUST NOT be read when the RBAC gate refuses the join');
					}

					return $this->entities($joinedRows);
				}

				return $this->entities($parentRows);
			}
		);

		return $this->makeRunner();
	}//end makeJoinRunner()

	/**
	 * The same `commitment-line` / `commitment-budget` fixture as
	 * {@see makeJoinRunner()}, but backed by a real in-memory SQLite
	 * database so the emitted `GROUP BY` SQL is genuinely parsed and
	 * executed and the joined values are real query output.
	 *
	 * @return AggregationRunner
	 */
	private function makeNativeJoinRunner(): AggregationRunner {
		$parentSchema = $this->makeSchema('commitment-line', 10, ['committed' => $this->joinAnnotation()]);
		$joinedSchema = $this->makeSchema('commitment-budget', 20);
		$register = $this->makeRegister('finance', [10, 20]);

		$this->registerMapper->method('find')->willReturn($register);
		$this->registerMapper->method('findAll')->willReturn([$register]);
		$this->schemaMapper->method('find')->willReturnMap(
			[
				['commitment-line', [], true, false, $parentSchema],
				['commitment-budget', [], true, false, $joinedSchema],
			]
		);
		$this->permissionHandler->method('hasPermission')->willReturn(true);

		$pdo = $this->seedSqlite();
		$this->db->method('getDatabasePlatform')->willReturn($this->createMock(SqlitePlatform::class));
		$this->db->method('prepare')->willReturnCallback(
			function (string $sql) use ($pdo): IPreparedStatement {
				$pdoStmt = $pdo->prepare($sql);
				$stmt = $this->createMock(IPreparedStatement::class);
				$stmt->method('execute')->willReturnCallback(
					function ($bindings = []) use ($pdoStmt): IResult {
						$pdoStmt->execute(($bindings ?? []));
						return $this->createMock(IResult::class);
					}
				);
				$stmt->method('fetch')->willReturnCallback(
					static fn () => $pdoStmt->fetch(PDO::FETCH_ASSOC)
				);
				return $stmt;
			}
		);

		$this->magicMapper->method('getTableNameForRegisterSchema')->willReturnCallback(
			static function (Register $register, Schema $schema): string {
				if ((string)$schema->getSlug() === 'commitment-budget') {
					return 'register_1_commitment_budget';
				}

				return 'register_1_commitment_line';
			}
		);

		return $this->makeRunner();
	}//end makeNativeJoinRunner()

	/**
	 * Create + seed the two in-memory magic tables the native join reads.
	 * Column names mirror MagicMapper's snake_case sanitisation.
	 *
	 * @return PDO
	 */
	private function seedSqlite(): PDO {
		$pdo = new PDO('sqlite::memory:');
		$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$pdo->exec(
			'CREATE TABLE "oc_register_1_commitment_line" (
                "_deleted" TEXT,
                "_organisation" TEXT,
                "programme" TEXT,
                "cost_centre" TEXT,
                "remaining_committed" INTEGER
            )'
		);
		$pdo->exec(
			'CREATE TABLE "oc_register_1_commitment_budget" (
                "_deleted" TEXT,
                "_organisation" TEXT,
                "programme_code" TEXT,
                "authorised_amount" INTEGER
            )'
		);

		$line = $pdo->prepare(
			'INSERT INTO "oc_register_1_commitment_line"
                ("_deleted", "_organisation", "programme", "cost_centre", "remaining_committed")
             VALUES (NULL, ?, ?, ?, ?)'
		);
		foreach (
			[
				['P1', 'C1', 100],
				['P1', 'C2', 50],
				['P2', 'C1', 200],
				['P3', 'C9', 7],
			] as $row
		) {
			$line->execute(['__no_active_org__', $row[0], $row[1], $row[2]]);
		}

		$budget = $pdo->prepare(
			'INSERT INTO "oc_register_1_commitment_budget"
                ("_deleted", "_organisation", "programme_code", "authorised_amount")
             VALUES (NULL, ?, ?, ?)'
		);
		foreach ([['P1', 1000], ['P2', 2000]] as $row) {
			$budget->execute(['__no_active_org__', $row[0], $row[1]]);
		}

		return $pdo;
	}//end seedSqlite()

	/**
	 * Fold joined group rows into an order-independent
	 * `"programme|costCentre" => {value, joined}` map.
	 *
	 * @param array<int, array<string, mixed>> $groups Grouped rows.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function foldJoined(array $groups): array {
		$folded = [];
		foreach ($groups as $group) {
			$key = $group['keys']['programme'] . '|' . $group['keys']['costCentre'];
			$folded[$key] = [
				'value' => $group['value'],
				'joined' => $group['joined']['commitment-budget.authorisedAmount'],
			];
		}

		ksort($folded);
		return $folded;
	}//end foldJoined()

	/**
	 * Build a runner for the translatable-group-key projection tests.
	 *
	 * @param mixed $groupBy The groupBy spec, in any accepted spelling.
	 * @param array<int, array<string, mixed>> $rows The parent rows.
	 *
	 * @return AggregationRunner
	 */
	private function makeTranslatableRunner(mixed $groupBy, array $rows): AggregationRunner {
		$parentSchema = $this->makeSchema(
			'commitment-line',
			10,
			['byTwo' => ['metric' => 'sum', 'field' => 'remainingCommitted', 'groupBy' => $groupBy]]
		);
		$register = $this->makeRegister('finance', [10]);

		$this->registerMapper->method('find')->willReturn($register);
		$this->registerMapper->method('findAll')->willReturn([$register]);
		$this->schemaMapper->method('find')->willReturn($parentSchema);
		$this->permissionHandler->method('hasPermission')->willReturn(true);
		$this->usePhpFallback();
		$this->magicMapper->method('findAllInRegisterSchemaTable')->willReturn($this->entities($rows));

		return $this->makeRunner();
	}//end makeTranslatableRunner()

	/**
	 * Wrap plain row arrays in ObjectEntity stubs.
	 *
	 * @param array<int, array<string, mixed>> $rows Row data.
	 *
	 * @return array<int, ObjectEntity&MockObject>
	 */
	private function entities(array $rows): array {
		$entities = [];
		foreach ($rows as $row) {
			$entity = $this->createMock(ObjectEntity::class);
			$entity->method('getObject')->willReturn($row);
			$entities[] = $entity;
		}

		return $entities;
	}//end entities()

	/**
	 * Point the DB mock at an unrecognised platform so
	 * tryNativeAggregation() bails and the PHP fallback runs.
	 *
	 * @return void
	 */
	private function usePhpFallback(): void {
		$platform = new class {
			public function __toString(): string {
				return 'OtherPlatform';
			}
		};
		$this->db->method('getDatabasePlatform')->willReturn($platform);
	}//end usePhpFallback()

	/**
	 * Assemble the runner from the shared mocks.
	 *
	 * @return AggregationRunner
	 */
	private function makeRunner(): AggregationRunner {
		return new AggregationRunner(
			magicMapper: $this->magicMapper,
			registerMapper: $this->registerMapper,
			schemaMapper: $this->schemaMapper,
			placeholders: new PlaceholderResolver($this->userSession),
			db: $this->db,
			cache: $this->cache,
			permissionHandler: $this->permissionHandler,
			userSession: $this->userSession,
			organisationService: $this->organisationService,
			translationHandler: $this->translationHandler,
			languageService: $this->languageService,
		);
	}//end makeRunner()

	/**
	 * Build a Schema entity.
	 *
	 * @param string $slug Schema slug.
	 * @param int $id Schema DB id.
	 * @param array<string, mixed> $aggregations Aggregation annotation map.
	 *
	 * @return Schema
	 */
	private function makeSchema(string $slug, int $id, array $aggregations = []): Schema {
		$schema = new Schema();
		$schema->setSlug($slug);
		$schema->setId($id);
		if ($aggregations !== []) {
			$schema->setConfiguration(['x-openregister-aggregations' => $aggregations]);
		}

		return $schema;
	}//end makeSchema()

	/**
	 * Build a Register entity.
	 *
	 * @param string $slug Register slug.
	 * @param array<int, int> $schemaIds Schema IDs the register carries.
	 *
	 * @return Register
	 */
	private function makeRegister(string $slug, array $schemaIds = []): Register {
		$register = new Register();
		$register->setSlug($slug);
		$register->setSchemas($schemaIds);
		return $register;
	}//end makeRegister()
}//end class
