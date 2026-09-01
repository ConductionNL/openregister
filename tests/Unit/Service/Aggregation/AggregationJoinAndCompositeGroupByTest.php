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

use OCA\OpenRegister\Db\MagicMapper\MagicOrganizationHandler;
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
 * NO `@covers` / `#[CoversClass]` METADATA — deliberately, and measured.
 *
 * This suite runs under `beStrictAboutCoverageMetadata="true"`. In that mode
 * PHPUnit restricts recording to the named units and marks any test that
 * executes anything else RISKY, discarding that test's coverage wholesale.
 * Almost every test here legitimately runs a collaborator — `validateGroupBy()`
 * calls `AggregationQuery::normaliseGroupByFields()`, the runner tests go
 * through `PlaceholderResolver` — so naming the three classes under test threw
 * away the measurement instead of focusing it. Measured on this exact file:
 *
 *   with `@covers` (or the `#[CoversClass]` attribute form):  38 / 1621 stmts
 *   with no coverage metadata:                               661 / 1621 stmts
 *
 * Same tests, same assertions, same executed lines — only the attribution
 * differs. The five files in this directory that report healthy coverage
 * (AggregationAnnotationValidatorTest, AggregationCacheTest,
 * AggregationQueryTest, ThresholdEvaluationServiceTest,
 * WidgetAnnotationValidatorTest) all carry no metadata for the same reason;
 * the ten that declare `@covers` are exactly the ones whose subject reports
 * 0%. Restoring metadata here without also declaring a `#[UsesClass]` for
 * every collaborator would silently zero this file's coverage again.
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
	/**
	 * REGRESSION: the caller's narrowing filter must scope the JOINED
	 * aggregate, not only the parent rows.
	 *
	 * This is a cross-tenant read, and it shipped. The parent was correctly
	 * narrowed to one administration while the join was computed over every
	 * administration, so a group's `joined` figures described a population the
	 * caller is not allowed to see. Measured on a live instance before the fix:
	 * CommitmentLine narrowed to ADM-001 returned the right sums while
	 * `CommitmentBudget.authorised_amount` came back 160,000,000 — ADM-001's
	 * own 80,000,000 plus both of adm-demo's (50,000,000 + 30,000,000), because
	 * the join matches on `programmeCode` alone.
	 *
	 * Nothing errored. The page showed another tenant's money as this tenant's
	 * authorised budget, and the number looked entirely reasonable.
	 *
	 * The fixture mirrors that shape: tenant `A` holds 1000 for P1, tenant `B`
	 * holds 400 for the same P1. Unfiltered the join is 1400; scoped to `A` it
	 * must be 1000. A run of this test against the unfixed runner reports 1400.
	 */
	public function testJoinHonoursTheCallersNarrowingFilter(): void {
		$runner = $this->makeTenantJoinRunner();

		$result = $runner->run(
			registerRef: 'finance',
			schemaRef: 'commitment-line',
			name: 'committed',
			extraFilter: ['administrationId' => 'A']
		);

		$joined = [];
		foreach ($result['groups'] as $group) {
			$joined[$group['keys']['programme']] = $group['joined']['commitment-budget.authorisedAmount'];
		}

		$this->assertSame(
			1000.0,
			$joined['P1'],
			'the joined figure must cover tenant A only; 1400 means tenant B leaked in'
		);
	}//end testJoinHonoursTheCallersNarrowingFilter()

	/**
	 * The un-narrowed call is unchanged — the fix scopes, it does not clamp.
	 *
	 * Without this, a join that always returned the caller's own tenant would
	 * pass the test above for the wrong reason.
	 */
	public function testJoinWithoutACallerFilterStillSeesEveryTenant(): void {
		$result = $this->makeTenantJoinRunner()->run(
			registerRef: 'finance',
			schemaRef: 'commitment-line',
			name: 'committed'
		);

		$joined = [];
		foreach ($result['groups'] as $group) {
			$joined[$group['keys']['programme']] = $group['joined']['commitment-budget.authorisedAmount'];
		}

		$this->assertSame(1400.0, $joined['P1']);
	}//end testJoinWithoutACallerFilterStillSeesEveryTenant()

	/**
	 * A caller filter naming a property the JOINED schema does not declare is
	 * dropped rather than forwarded.
	 *
	 * Forwarding it would not raise: a filter on a property a schema does not
	 * have matches nothing and returns an empty set, so the join would silently
	 * become zero — trading a figure that is too big for one that is always
	 * wrong, and in the direction that looks like "no budget yet".
	 *
	 * `costCentre` is a PARENT property here and absent from
	 * `commitment-budget`, which is exactly the realistic case: the page sends
	 * one filter map and only some of its keys mean anything on the joined side.
	 */
	public function testJoinDropsACallerFilterTheJoinedSchemaDoesNotDeclare(): void {
		$result = $this->makeTenantJoinRunner()->run(
			registerRef: 'finance',
			schemaRef: 'commitment-line',
			name: 'committed',
			extraFilter: ['costCentre' => 'C1']
		);

		$joined = [];
		foreach ($result['groups'] as $group) {
			$joined[$group['keys']['programme']] = $group['joined']['commitment-budget.authorisedAmount'];
		}

		$this->assertSame(
			1400.0,
			$joined['P1'],
			'an undeclared key must leave the join as wide as it was, not zero it'
		);
	}//end testJoinDropsACallerFilterTheJoinedSchemaDoesNotDeclare()

	/**
	 * A caller may not relax what the join declares.
	 *
	 * Same asymmetry as {@see AggregationRunner::mergeNarrowingFilter()} on the
	 * parent: the declaration wins. Here the join declares
	 * `administrationId: A`, and a caller asking for `B` must not widen or
	 * switch it — otherwise a declared scoping filter becomes a request
	 * parameter, which is the whole thing that rule exists to prevent.
	 */
	public function testCallerCannotOverrideADeclaredJoinFilter(): void {
		$annotation = $this->joinAnnotation();
		$annotation['join']['filter'] = ['administrationId' => 'A'];

		$result = $this->makeTenantJoinRunner($annotation)->run(
			registerRef: 'finance',
			schemaRef: 'commitment-line',
			name: 'committed',
			extraFilter: ['administrationId' => 'B']
		);

		$joined = [];
		foreach ($result['groups'] as $group) {
			$joined[$group['keys']['programme']] = $group['joined']['commitment-budget.authorisedAmount'];
		}

		$this->assertSame(
			1000.0,
			$joined['P1'],
			"the declared administrationId 'A' must stand; 400 means the caller switched tenants"
		);
	}//end testCallerCannotOverrideADeclaredJoinFilter()

	/**
	 * A caller constraint the PARENT declaration drops must not reach the join
	 * either.
	 *
	 * This is the hole the obvious version of the fix opens. The parent
	 * declaration pins `administrationId: A`; a caller asking for `B` is
	 * refused there and the parent stays on A. Forwarding the RAW request to
	 * the join would then scope the joined figures to B while the parent rows
	 * are A — the same population mismatch as the original bug, pointed the
	 * other way, and arguably worse: the dropped key never reaches the cache
	 * key, so the mismatched envelope would be cached and served on.
	 *
	 * Hence applyJoin() receives what actually took effect on the parent
	 * (`array_diff_key` against the pre-merge filter), never `$extraFilter`.
	 */
	public function testACallerKeyTheParentDeclarationDropsDoesNotReachTheJoin(): void {
		$annotation = $this->joinAnnotation();
		$annotation['filter'] = ['administrationId' => 'A'];

		$result = $this->makeTenantJoinRunner($annotation)->run(
			registerRef: 'finance',
			schemaRef: 'commitment-line',
			name: 'committed',
			extraFilter: ['administrationId' => 'B']
		);

		$joined = [];
		foreach ($result['groups'] as $group) {
			$joined[$group['keys']['programme']] = $group['joined']['commitment-budget.authorisedAmount'];
		}

		$this->assertSame(
			1400.0,
			$joined['P1'],
			'the refused key must not scope the join; 400 means the parent stayed on A while the join followed the caller to B'
		);
	}//end testACallerKeyTheParentDeclarationDropsDoesNotReachTheJoin()

	/**
	 * Grouping by a field that lives on the JOINED schema rolls the parent
	 * rows up to that dimension.
	 *
	 * `groupBy: ["commitment-budget.parentCode"]` names a column the parent
	 * does not have. applyJoin() runs AFTER grouping and attaches figures to
	 * groups that already exist, so it cannot produce this — by then the rows
	 * are gone. The value has to be projected onto each parent row through the
	 * join key first.
	 *
	 * The fixture is built so a wrong answer is obvious rather than plausible:
	 * P1 and P2 both roll up to REGION-A, P3 to REGION-B. Grouping on the
	 * unprojected column would put all four rows in ONE null bucket (357);
	 * failing to roll up would leave three P-keyed groups instead of two
	 * region-keyed ones.
	 *
	 *   REGION-A = P1(100 + 50) + P2(200) = 350
	 *   REGION-B = P3(7)                  =   7
	 */
	public function testGroupByAJoinedFieldRollsParentRowsUp(): void {
		$annotation = $this->joinAnnotation();
		$annotation['groupBy'] = ['commitment-budget.parentCode'];
		// The explicit map, not the shorthand: the shorthand infers the parent
		// key from the group fields, which are the joined ones here.
		$annotation['join']['on'] = ['programme' => 'programmeCode'];

		$result = $this->makeHierarchyRunner($annotation)->run(
			registerRef: 'finance',
			schemaRef: 'commitment-line',
			name: 'committed'
		);

		$folded = [];
		foreach ($result['groups'] as $group) {
			$key = ($group['keys']['commitment-budget.parentCode'] ?? $group['key'] ?? null);
			$folded[(string)$key] = $group['value'];
		}

		ksort($folded);
		$this->assertSame(
			['REGION-A' => 350.0, 'REGION-B' => 7.0],
			$folded,
			'parent rows must roll up to the joined dimension, not stay on their own key'
		);

	}//end testGroupByAJoinedFieldRollsParentRowsUp()

	/**
	 * A parent row whose join key matches nothing keeps a NULL group, and is
	 * NOT dropped.
	 *
	 * Dropping it would quietly shrink the totals — the reported figure would
	 * be smaller than the data and nothing would say so. A null bucket is
	 * visible, and is the honest answer for "belongs to no dimension".
	 */
	public function testUnmatchedParentRowsLandInANullGroupRatherThanVanishing(): void {
		$annotation = $this->joinAnnotation();
		$annotation['groupBy'] = ['commitment-budget.parentCode'];
		$annotation['join']['on'] = ['programme' => 'programmeCode'];

		// P9 has no matching budget row.
		$result = $this->makeHierarchyRunner($annotation, orphanRow: true)->run(
			registerRef: 'finance',
			schemaRef: 'commitment-line',
			name: 'committed'
		);

		$total = 0.0;
		$sawNull = false;
		foreach ($result['groups'] as $group) {
			$total += (float)$group['value'];
			$key = ($group['keys']['commitment-budget.parentCode'] ?? $group['key'] ?? null);
			if ($key === null) {
				$sawNull = true;
				$this->assertSame(11.0, (float)$group['value'], 'the orphan row keeps its own amount');
			}
		}

		$this->assertTrue($sawNull, 'an unmatched row must surface in a null bucket');
		$this->assertSame(368.0, $total, '350 + 7 + 11 — nothing may be dropped');

	}//end testUnmatchedParentRowsLandInANullGroupRatherThanVanishing()

	/**
	 * The `on` SHORTHAND is refused when grouping by a joined field, rather
	 * than inferring the wrong parent key.
	 *
	 * `"on": "Schema.column"` infers the parent side from the group fields —
	 * same-named one if present, otherwise the first. When the group field is
	 * the joined one, that inference picks the JOINED field as the parent key,
	 * reads it off parent rows that do not have it, and lands every row in a
	 * single null bucket. Measured before this guard: a fixture summing
	 * 350 + 7 came back as one bucket of 357 keyed ''.
	 *
	 * A refusal naming the fix is worth more than a plausible total.
	 */
	public function testJoinedGroupByRefusesTheOnShorthand(): void {
		$annotation = $this->joinAnnotation();
		$annotation['groupBy'] = ['commitment-budget.parentCode'];
		// joinAnnotation() ships the string shorthand; leave it.

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessageMatches('/explicit join\.on map/');

		$this->makeHierarchyRunner($annotation)->run(
			registerRef: 'finance',
			schemaRef: 'commitment-line',
			name: 'committed'
		);

	}//end testJoinedGroupByRefusesTheOnShorthand()

	/**
	 * Grouping by a joined field marks the join as consumed instead of hanging
	 * a map of nulls on every group.
	 *
	 * mergeJoinedValues() reads the join key out of each group row, and after
	 * projection that key is gone — the group key IS the joined dimension. Left
	 * to run it would attach `joined` entries that are all null, which reads as
	 * "the joined schema had nothing" rather than "the grouping already
	 * answered this".
	 */
	public function testJoinedGroupByReportsTheJoinAsConsumed(): void {
		$annotation = $this->joinAnnotation();
		$annotation['groupBy'] = ['commitment-budget.parentCode'];
		$annotation['join']['on'] = ['programme' => 'programmeCode'];

		$result = $this->makeHierarchyRunner($annotation)->run(
			registerRef: 'finance',
			schemaRef: 'commitment-line',
			name: 'committed'
		);

		$this->assertTrue($result['join']['consumedForGrouping']);
		$this->assertSame('commitment-budget', $result['join']['through']);
		foreach ($result['groups'] as $group) {
			$this->assertArrayNotHasKey(
				'joined',
				$group,
				'a map of nulls would read as "the joined schema had nothing"'
			);
		}

	}//end testJoinedGroupByReportsTheJoinAsConsumed()

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
	// Validator — the remaining join rejection paths.
	// ------------------------------------------------------------------

	/**
	 * A `join` written as a LIST rather than an object. Worth its own case
	 * because `[]` and `{}` are the same type in PHP, so the guard has to
	 * test list-ness explicitly or a JSON array would fall through to the
	 * per-key reads and report five confusing errors instead of one.
	 */
	public function testJoinWrittenAsAListIsRejected(): void {
		$validator = new AggregationAnnotationValidator();

		$spec = $this->joinAnnotation();
		$spec['join'] = ['through', 'on', 'select'];

		$this->assertSame(
			['aggregation-join-malformed'],
			$this->codes($validator->validate($this->schemaDefinition(['committed' => $spec]))),
			'a list-shaped join MUST raise exactly one shape error, not a cascade'
		);
	}//end testJoinWrittenAsAListIsRejected()

	/**
	 * A join with no `through` names no schema to join to.
	 */
	public function testJoinWithoutThroughIsRejected(): void {
		$validator = new AggregationAnnotationValidator();

		$spec = $this->joinAnnotation();
		unset($spec['join']['through']);

		$this->assertContains(
			'aggregation-join-through-empty',
			$this->codes($validator->validate($this->schemaDefinition(['committed' => $spec])))
		);
	}//end testJoinWithoutThroughIsRejected()

	/**
	 * `on` must be a non-empty string or a non-empty map. An empty map, a
	 * list, and a non-string scalar all fail the same way.
	 *
	 * @param mixed $onClause The malformed `on` value.
	 *
	 * @dataProvider malformedOnProvider
	 */
	public function testMalformedJoinOnIsRejected(mixed $onClause): void {
		$validator = new AggregationAnnotationValidator();

		$spec = $this->joinAnnotation();
		$spec['join']['on'] = $onClause;

		$this->assertContains(
			'aggregation-join-on-malformed',
			$this->codes($validator->validate($this->schemaDefinition(['committed' => $spec])))
		);
	}//end testMalformedJoinOnIsRejected()

	/**
	 * @return array<string, array<int, mixed>>
	 */
	public static function malformedOnProvider(): array {
		return [
			'empty string' => [''],
			'empty map' => [[]],
			'list' => [['programmeCode']],
			'integer' => [42],
			'null' => [null],
		];
	}//end malformedOnProvider()

	/**
	 * A `select` entry that names no field — a bare number, or an object
	 * with a `metric` but no `field`.
	 *
	 * @param mixed $entry The malformed select entry.
	 *
	 * @dataProvider malformedSelectEntryProvider
	 */
	public function testMalformedJoinSelectEntryIsRejected(mixed $entry): void {
		$validator = new AggregationAnnotationValidator();

		$spec = $this->joinAnnotation();
		$spec['join']['select'] = [$entry];

		$this->assertContains(
			'aggregation-join-select-malformed',
			$this->codes($validator->validate($this->schemaDefinition(['committed' => $spec])))
		);
	}//end testMalformedJoinSelectEntryIsRejected()

	/**
	 * @return array<string, array<int, mixed>>
	 */
	public static function malformedSelectEntryProvider(): array {
		return [
			'integer' => [42],
			'empty string' => [''],
			'object without field' => [['metric' => 'sum']],
			'object with empty field' => [['field' => '']],
			'object with non-string field' => [['field' => 7]],
		];
	}//end malformedSelectEntryProvider()

	/**
	 * A `select` list that is not a list at all.
	 */
	public function testNonListJoinSelectIsRejected(): void {
		$validator = new AggregationAnnotationValidator();

		$spec = $this->joinAnnotation();
		$spec['join']['select'] = 'commitment-budget.authorisedAmount';

		$this->assertContains(
			'aggregation-join-select-empty',
			$this->codes($validator->validate($this->schemaDefinition(['committed' => $spec])))
		);
	}//end testNonListJoinSelectIsRejected()

	/**
	 * The joined-side filter must be a map when present.
	 */
	public function testNonMapJoinFilterIsRejected(): void {
		$validator = new AggregationAnnotationValidator();

		$spec = $this->joinAnnotation();
		$spec['join']['filter'] = 'afgesloten=false';

		$this->assertContains(
			'aggregation-join-filter-malformed',
			$this->codes($validator->validate($this->schemaDefinition(['committed' => $spec])))
		);
	}//end testNonMapJoinFilterIsRejected()

	/**
	 * An explicit `on` map whose parent halves ARE declared properties
	 * validates clean — the accept side of the check whose reject side
	 * testJoinOnMapRejectsUndeclaredParentField pins.
	 */
	public function testJoinOnMapWithDeclaredParentFieldsValidates(): void {
		$validator = new AggregationAnnotationValidator();

		$spec = $this->joinAnnotation();
		$spec['join']['on'] = ['programme' => 'programmeCode', 'costCentre' => 'ccCode'];

		$this->assertSame([], $validator->validate($this->schemaDefinition(['committed' => $spec])));
	}//end testJoinOnMapWithDeclaredParentFieldsValidates()

	// ------------------------------------------------------------------
	// Validator — groupBy shapes the composite check now leans on.
	// ------------------------------------------------------------------

	/**
	 * groupBy spellings that normalise to NO fields are malformed. An empty
	 * list is the interesting one: it is a perfectly good array, so a guard
	 * that only tested `is_array()` would accept it and then group on
	 * nothing.
	 *
	 * @param mixed $groupBy The groupBy value that names no field.
	 *
	 * @dataProvider emptyGroupByProvider
	 */
	public function testGroupByNamingNoFieldIsRejected(mixed $groupBy): void {
		$validator = new AggregationAnnotationValidator();

		$this->assertContains(
			'aggregation-groupby-malformed',
			$this->codes($validator->validate($this->schemaDefinition(
				['x' => ['metric' => 'count', 'groupBy' => $groupBy]]
			)))
		);
	}//end testGroupByNamingNoFieldIsRejected()

	/**
	 * @return array<string, array<int, mixed>>
	 */
	public static function emptyGroupByProvider(): array {
		return [
			'empty list' => [[]],
			'empty fields list' => [['fields' => []]],
			'object with neither field nor fields' => [['bucket' => 'day']],
			'scalar' => ['programme'],
		];
	}//end emptyGroupByProvider()

	/**
	 * A single-element LIST is the boundary between the two group-key row
	 * shapes: it must validate like the object form, and the runner must
	 * still emit the legacy scalar `key` (not a one-entry `keys` map), or a
	 * consumer that migrated its spelling would silently lose its keys.
	 */
	public function testSingleElementListGroupByValidatesAndKeepsScalarKeyShape(): void {
		$validator = new AggregationAnnotationValidator();
		$this->assertSame(
			[],
			$validator->validate($this->schemaDefinition(
				['x' => ['metric' => 'count', 'groupBy' => ['programme']]]
			))
		);

		$runner = $this->makeTranslatableRunner(
			groupBy: ['programme'],
			rows: [['programme' => 'P1', 'costCentre' => 'C1', 'remainingCommitted' => 10]]
		);
		$result = $runner->run(registerRef: 'finance', schemaRef: 'commitment-line', name: 'byTwo');

		$this->assertSame('P1', $result['groups'][0]['key']);
		$this->assertArrayNotHasKey('keys', $result['groups'][0]);
	}//end testSingleElementListGroupByValidatesAndKeepsScalarKeyShape()

	/**
	 * The `{field, bucket}` object form — the full legacy spelling, bucket
	 * included — still validates.
	 */
	public function testFieldAndBucketObjectGroupByValidates(): void {
		$validator = new AggregationAnnotationValidator();

		$this->assertSame(
			[],
			$validator->validate($this->schemaDefinition(
				['x' => ['metric' => 'count', 'groupBy' => ['field' => 'programme', 'bucket' => 'day']]]
			))
		);
	}//end testFieldAndBucketObjectGroupByValidates()

	// ------------------------------------------------------------------
	// Runner — join key spellings and merge edge cases.
	// ------------------------------------------------------------------

	/**
	 * The explicit `{parentField: joinedField}` map and the single-string
	 * shorthand must resolve to the SAME join. The shorthand infers the
	 * parent side (first group field, since `programmeCode` is not itself
	 * grouped); this pins that the inference lands where the explicit
	 * spelling says it should, which is the whole basis for accepting the
	 * shorthand at all.
	 */
	public function testExplicitOnMapAndStringShorthandAgree(): void {
		$stringSpec = $this->joinAnnotation();

		$mapSpec = $this->joinAnnotation();
		$mapSpec['join']['on'] = ['programme' => 'programmeCode'];

		$viaString = $this->makeJoinRunner(annotations: ['committed' => $stringSpec])
			->run(registerRef: 'finance', schemaRef: 'commitment-line', name: 'committed');

		$this->setUp();
		$viaMap = $this->makeJoinRunner(annotations: ['committed' => $mapSpec])
			->run(registerRef: 'finance', schemaRef: 'commitment-line', name: 'committed');

		$this->assertSame(['programme' => 'programmeCode'], $viaString['join']['on']);
		$this->assertSame(['programme' => 'programmeCode'], $viaMap['join']['on']);
		$this->assertSame($this->foldJoined($viaString['groups']), $this->foldJoined($viaMap['groups']));
	}//end testExplicitOnMapAndStringShorthandAgree()

	/**
	 * An explicit `on` naming a parent field that is NOT grouped is refused.
	 * The merge reads the join key out of the group row, so an ungrouped
	 * parent field has no value there — it would match nothing on every row
	 * while looking like a working join.
	 */
	public function testOnMapNamingUngroupedParentFieldIsRefused(): void {
		$spec = $this->joinAnnotation();
		$spec['join']['on'] = ['remainingCommitted' => 'programmeCode'];

		$runner = $this->makeJoinRunner(annotations: ['committed' => $spec]);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('is not one of the groupBy fields');

		$runner->run(registerRef: 'finance', schemaRef: 'commitment-line', name: 'committed');
	}//end testOnMapNamingUngroupedParentFieldIsRefused()

	/**
	 * Joined rows that match NO parent group are dropped silently, and
	 * SEVERAL joined rows sharing one key are reduced by the select metric
	 * (`sum` by default) rather than one arbitrarily winning.
	 */
	public function testJoinedRowsWithNoGroupAreDroppedAndDuplicateKeysReduce(): void {
		$runner = $this->makeJoinRunner(
			joinedRowsOverride: [
				['programmeCode' => 'P1', 'authorisedAmount' => 1000],
				['programmeCode' => 'P1', 'authorisedAmount' => 250],
				['programmeCode' => 'P2', 'authorisedAmount' => 2000],
				['programmeCode' => 'P404', 'authorisedAmount' => 999999],
			]
		);

		$result = $runner->run(registerRef: 'finance', schemaRef: 'commitment-line', name: 'committed');
		$folded = $this->foldJoined($result['groups']);

		$this->assertSame(1250.0, $folded['P1|C1']['joined'], 'duplicate join keys MUST reduce, not overwrite');
		$this->assertSame(1250.0, $folded['P1|C2']['joined']);
		$this->assertSame(2000.0, $folded['P2|C1']['joined']);
		$this->assertNull($folded['P3|C9']['joined']);
		$this->assertCount(4, $folded, 'the unmatched P404 budget MUST NOT invent a group');
	}//end testJoinedRowsWithNoGroupAreDroppedAndDuplicateKeysReduce()

	/**
	 * The `{field, metric}` select spelling overrides the `sum` default, and
	 * a metric outside the closed vocabulary is refused rather than quietly
	 * reduced some other way.
	 */
	public function testSelectObjectSpellingHonoursMetricAndRejectsUnknownOnes(): void {
		$maxSpec = $this->joinAnnotation();
		$maxSpec['join']['select'] = [['field' => 'authorisedAmount', 'metric' => 'max', 'alias' => 'cap']];

		$result = $this->makeJoinRunner(annotations: ['committed' => $maxSpec])
			->run(registerRef: 'finance', schemaRef: 'commitment-line', name: 'committed');

		$this->assertSame(['cap'], $result['join']['select'], 'the declared alias MUST be the response key');
		$this->assertSame(1000.0, $result['groups'][0]['joined']['cap']);

		$this->setUp();
		$badSpec = $this->joinAnnotation();
		$badSpec['join']['select'] = [['field' => 'authorisedAmount', 'metric' => 'median']];
		$runner = $this->makeJoinRunner(annotations: ['committed' => $badSpec]);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('join.select metric "median" is not supported');
		$runner->run(registerRef: 'finance', schemaRef: 'commitment-line', name: 'committed');
	}//end testSelectObjectSpellingHonoursMetricAndRejectsUnknownOnes()

	/**
	 * When the joined hydrate hits PHP_FALLBACK_ROW_CAP the envelope says so.
	 * The joined numbers are then computed over a PREFIX of the joined table,
	 * so a consumer that ignores `join.truncated` is reading a partial budget
	 * as if it were the whole one.
	 */
	public function testJoinTruncatedIsSetWhenJoinedHydrateHitsTheRowCap(): void {
		$capped = [];
		for ($i = 0; $i < 10000; $i++) {
			$capped[] = ['programmeCode' => 'P1', 'authorisedAmount' => 1];
		}

		$result = $this->makeJoinRunner(joinedRowsOverride: $capped)
			->run(registerRef: 'finance', schemaRef: 'commitment-line', name: 'committed');

		$this->assertTrue($result['join']['truncated'], 'a joined hydrate at the cap MUST flag truncation');
		$this->assertSame(10000.0, $result['groups'][0]['joined']['commitment-budget.authorisedAmount']);
	}//end testJoinTruncatedIsSetWhenJoinedHydrateHitsTheRowCap()

	/**
	 * The un-truncated control for the test above — without it, a bug that
	 * hardcoded `truncated: true` would pass.
	 */
	public function testJoinTruncatedIsFalseBelowTheRowCap(): void {
		$result = $this->makeJoinRunner()
			->run(registerRef: 'finance', schemaRef: 'commitment-line', name: 'committed');

		$this->assertFalse($result['join']['truncated']);
	}//end testJoinTruncatedIsFalseBelowTheRowCap()

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
	 * @param array<int, array<string, mixed>>|null $joinedRowsOverride Replace the joined-side rows.
	 *
	 * @return AggregationRunner
	 */
	private function makeJoinRunner(
		?array $annotations = null,
		bool $joinedSchemaListAllowed = true,
		bool $expectNoJoinedFetch = false,
		?array $joinedRowsOverride = null,
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
		$joinedRows = ($joinedRowsOverride ?? [
			['programmeCode' => 'P1', 'authorisedAmount' => 1000],
			['programmeCode' => 'P2', 'authorisedAmount' => 2000],
		]);

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
	 * A fixture where the JOINED schema carries a hierarchy column.
	 *
	 * Parent rows are keyed by `programme`; the joined `commitment-budget` rows
	 * map each programme to a `parentCode`, so grouping by
	 * `commitment-budget.parentCode` must roll P1 and P2 together under
	 * REGION-A and leave P3 under REGION-B.
	 *
	 * @param array<string, mixed> $annotation The aggregation annotation.
	 * @param bool                 $orphanRow  Add a parent row matching no budget.
	 *
	 * @return AggregationRunner The configured runner.
	 */
	private function makeHierarchyRunner(array $annotation, bool $orphanRow = false): AggregationRunner {
		$parentSchema = $this->makeSchema(
			'commitment-line',
			10,
			['committed' => $annotation],
			[
				'programme' => ['type' => 'string'],
				'costCentre' => ['type' => 'string'],
				'remainingCommitted' => ['type' => 'number'],
			]
		);
		$joinedSchema = $this->makeSchema(
			'commitment-budget',
			20,
			[],
			[
				'programmeCode' => ['type' => 'string'],
				'parentCode' => ['type' => 'string'],
				'authorisedAmount' => ['type' => 'number'],
			]
		);
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
		$this->usePhpFallback();

		$parentRows = [
			['programme' => 'P1', 'costCentre' => 'C1', 'remainingCommitted' => 100],
			['programme' => 'P1', 'costCentre' => 'C2', 'remainingCommitted' => 50],
			['programme' => 'P2', 'costCentre' => 'C1', 'remainingCommitted' => 200],
			['programme' => 'P3', 'costCentre' => 'C9', 'remainingCommitted' => 7],
		];
		if ($orphanRow === true) {
			$parentRows[] = ['programme' => 'P9', 'costCentre' => 'C9', 'remainingCommitted' => 11];
		}

		$joinedRows = [
			['programmeCode' => 'P1', 'parentCode' => 'REGION-A', 'authorisedAmount' => 1000],
			['programmeCode' => 'P2', 'parentCode' => 'REGION-A', 'authorisedAmount' => 2000],
			['programmeCode' => 'P3', 'parentCode' => 'REGION-B', 'authorisedAmount' => 3000],
		];

		$this->magicMapper->method('findAllInRegisterSchemaTable')->willReturnCallback(
			function (Register $register, Schema $schema) use ($parentRows, $joinedRows): array {
				if ((string)$schema->getSlug() === 'commitment-budget') {
					return $this->entities($joinedRows);
				}

				return $this->entities($parentRows);
			}
		);

		return $this->makeRunner();
	}//end makeHierarchyRunner()

	/**
	 * A `commitment-line` / `commitment-budget` fixture whose rows span two
	 * administrations, for the join-scoping tests.
	 *
	 * Differs from {@see makeJoinRunner()} in the two things those tests need:
	 * the joined schema DECLARES `administrationId` (the narrowing filter is
	 * restricted to declared keys, so a schema without it would drop the filter
	 * and the test would pass without exercising anything), and both sides
	 * carry rows for tenants `A` and `B` over the same programme `P1`.
	 *
	 * P1 authorised: A=1000, B=400. Unscoped the join reports 1400.
	 *
	 * @param array<string, mixed>|null $annotation Override the aggregation annotation.
	 *
	 * @return AggregationRunner
	 */
	private function makeTenantJoinRunner(?array $annotation = null): AggregationRunner {
		$annotations = ['committed' => ($annotation ?? $this->joinAnnotation())];

		$parentSchema = $this->makeSchema(
			'commitment-line',
			10,
			$annotations,
			[
				'programme' => ['type' => 'string'],
				'costCentre' => ['type' => 'string'],
				'administrationId' => ['type' => 'string'],
				'remainingCommitted' => ['type' => 'number'],
			]
		);
		$joinedSchema = $this->makeSchema(
			'commitment-budget',
			20,
			[],
			[
				'programmeCode' => ['type' => 'string'],
				'administrationId' => ['type' => 'string'],
				'authorisedAmount' => ['type' => 'number'],
			]
		);
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
		$this->usePhpFallback();

		$parentRows = [
			['programme' => 'P1', 'costCentre' => 'C1', 'administrationId' => 'A', 'remainingCommitted' => 100],
			['programme' => 'P1', 'costCentre' => 'C1', 'administrationId' => 'B', 'remainingCommitted' => 60],
		];
		$joinedRows = [
			['programmeCode' => 'P1', 'administrationId' => 'A', 'authorisedAmount' => 1000],
			['programmeCode' => 'P1', 'administrationId' => 'B', 'authorisedAmount' => 400],
		];

		$this->magicMapper->method('findAllInRegisterSchemaTable')->willReturnCallback(
			function (Register $register, Schema $schema) use ($parentRows, $joinedRows): array {
				if ((string)$schema->getSlug() === 'commitment-budget') {
					return $this->entities($joinedRows);
				}

				return $this->entities($parentRows);
			}
		);

		return $this->makeRunner();
	}//end makeTenantJoinRunner()

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
	 * Wrap plain row arrays in real ObjectEntity instances.
	 *
	 * Real entities rather than mocks: the row-cap test builds 10 000 of
	 * these, and PHPUnit's mock generator is far too slow at that volume.
	 * `ObjectEntity::getObject()` prepends its own `id` key; harmless here,
	 * since every assertion reads named fields.
	 *
	 * @param array<int, array<string, mixed>> $rows Row data.
	 *
	 * @return array<int, ObjectEntity>
	 */
	private function entities(array $rows): array {
		$entities = [];
		foreach ($rows as $row) {
			$entity = new ObjectEntity();
			$entity->setObject($row);
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
			organizationHandler: $this->orgHandlerScopedTo('__no_active_org__'),
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
	 * @param array<string, mixed> $properties Declared properties. The join's narrowing filter is
	 *                                         restricted to keys the joined schema declares, so a
	 *                                         fixture testing that path must set them.
	 *
	 * @return Schema
	 */
	private function makeSchema(string $slug, int $id, array $aggregations = [], array $properties = []): Schema {
		$schema = new Schema();
		$schema->setSlug($slug);
		$schema->setId($id);
		if ($aggregations !== []) {
			$schema->setConfiguration(['x-openregister-aggregations' => $aggregations]);
		}

		if ($properties !== []) {
			$schema->setProperties($properties);
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

	/**
	 * A MagicOrganizationHandler that reports the caller scoped to exactly one
	 * organisation. The fixtures in these tests seed rows carrying that same
	 * value in `_organisation`, so the rendered predicate matches them — which
	 * is what the old hard-coded `_organisation = :activeOrg` did implicitly.
	 *
	 * @param string $orgUuid The organisation the caller is scoped to.
	 *
	 * @return MagicOrganizationHandler
	 */
	private function orgHandlerScopedTo(string $orgUuid): MagicOrganizationHandler {
		$handler = $this->createMock(MagicOrganizationHandler::class);
		$handler->method('resolveOrganizationScope')->willReturn(
			['mode' => MagicOrganizationHandler::SCOPE_IN, 'uuids' => [$orgUuid]]
		);

		return $handler;
	}//end orgHandlerScopedTo()

}//end class
