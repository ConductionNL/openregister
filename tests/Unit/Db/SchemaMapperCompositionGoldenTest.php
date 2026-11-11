<?php

/**
 * Golden test for schema composition in SchemaMapper.
 *
 * Records what allOf, oneOf and anyOf resolve to on a read, what is left of a
 * schema after the delta on a save, where properties are said to come from and
 * who is found to extend a schema. Re-record with UPDATE_GOLDEN=1.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Db
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Schemas\PropertyValidatorHandler;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Db\SchemaMapper\SchemaMapperServices;
use OCA\OpenRegister\Db\Tenancy\TenancyServices;

/**
 * @covers \OCA\OpenRegister\Db\SchemaMapper
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Db\MultiTenancyTrait
 * @uses   \OCA\OpenRegister\Service\WritePhaseProbe
 * @uses \OCA\OpenRegister\Db\EntityChangeDetector
 * @uses \OCA\OpenRegister\Db\SchemaPropertyInheritance
 * @uses \OCA\OpenRegister\Event\SchemaUpdatedEvent
 * @uses \OCA\OpenRegister\Service\AnonymousEvaluationContext
 * @uses \OCA\OpenRegister\Service\Schemas\ScopedPropertyGovernance
 * @uses \OCA\OpenRegister\Db\SchemaMapper\AdvisoryAnnotationChecks
 * @uses \OCA\OpenRegister\Db\SchemaMapper\CalculationAnnotationCheck
 * @uses \OCA\OpenRegister\Db\SchemaMapper\DeclaredActionCheck
 * @uses \OCA\OpenRegister\Db\SchemaMapper\ReferenceLoopCheck
 * @uses \OCA\OpenRegister\Db\SchemaMapper\ReferenceLoops
 * @uses \OCA\OpenRegister\Db\SchemaMapper\ExchangeAnnotationRefusals
 * @uses \OCA\OpenRegister\Db\SchemaMapper\LifecycleAnnotationCheck
 * @uses \OCA\OpenRegister\Db\SchemaMapper\PropertyDeclarationRefusals
 * @uses \OCA\OpenRegister\Db\SchemaMapper\RecordAnnotationRefusals
 * @uses \OCA\OpenRegister\Db\SchemaMapper\RelationDeclarationCheck
 * @uses \OCA\OpenRegister\Db\SchemaMapper\SchemaAnnotation
 * @uses \OCA\OpenRegister\Service\BulkJob\ReversibilityAnnotationValidator
 * @uses \OCA\OpenRegister\Service\Calculation\PropertyCalculations
 * @uses \OCA\OpenRegister\Service\Decision\ReviewAnnotationValidator
 * @uses \OCA\OpenRegister\Service\Flow\MacroActionBinding
 * @uses \OCA\OpenRegister\Service\ObjectAction\ObjectActionDefinition
 * @uses \OCA\OpenRegister\Service\Rbac\MetadataConditionFindings
 * @uses \OCA\OpenRegister\Service\Rbac\RecordMetadataConditions
 * @uses \OCA\OpenRegister\Service\Rbac\RuleColumns
 * @uses \OCA\OpenRegister\Service\Rbac\StoredRuleBlocks
 * @uses \OCA\OpenRegister\Service\Rbac\UnknownRuleKeyFindings
 * @uses \OCA\OpenRegister\Service\Relation\RelationAnnotationValidator
 * @uses \OCA\OpenRegister\Service\Relation\RelationTypeResolver
 * @uses \OCA\OpenRegister\Service\Rules\DependentValueValidator
 * @uses \OCA\OpenRegister\Service\Rules\ExpressionDefaultResolver
 * @uses \OCA\OpenRegister\Service\Schemas\ExtendingFormDeclaration
 * @uses \OCA\OpenRegister\Db\SchemaMapper\ReferenceNormaliser
 * @uses \OCA\OpenRegister\Db\SchemaMapper\SchemaDefaults
 * @uses \OCA\OpenRegister\Db\SchemaMapper\ConfigurationFields
 * @uses \OCA\OpenRegister\Db\SchemaMapper\FacetConfiguration
 * @uses \OCA\OpenRegister\Db\SchemaMapper\SchemaComposition
 * @uses \OCA\OpenRegister\Db\SchemaMapper\ParentReference
 * @uses \OCA\OpenRegister\Db\SchemaMapper\PropertyMerge
 * @uses \OCA\OpenRegister\Db\SchemaMapper\PropertyDelta
 * @uses \OCA\OpenRegister\Db\SchemaMapper\ExtendedByReads
 * @uses \OCA\OpenRegister\Db\SchemaMapper\SchemaReferenceSearch
 * @uses \OCA\OpenRegister\Db\SchemaMapper\AccessDeclarationChecks
 * @uses \OCA\OpenRegister\Db\SchemaMapper\SubjectDeclarationRefusals
 * @uses \OCA\OpenRegister\Db\SchemaMapper\AuthorizationBlockCheck
 */
class SchemaMapperCompositionGoldenTest extends TestCase {
	use RecordingQueryBuilderTrait;

	private const NAME = ['type' => 'string', 'title' => 'Name'];

	private static function row(int $id, string $slug, array $extra = []): array {
		$row = ['id' => $id, 'uuid' => 'uuid-' . $id, 'slug' => $slug, 'title' => ucfirst($slug), 'properties' => '{}', 'required' => '[]'];
		foreach ($extra as $key => $value) {
			$row[$key] = json_encode($value);
		}

		return $row;
	}//end row()

	/**
	 * A schema already in hand, with the identifiers the composition search matches on.
	 */
	private static function known(?int $id, ?string $uuid, ?string $slug): Schema {
		$schema = new Schema();
		$schema->setId($id);
		$schema->setUuid($uuid);
		$schema->setSlug($slug);
		return $schema;
	}//end known()

	private static function parent(int $id = 2, array $properties = ['name' => self::NAME], array $required = ['name']): array {
		return self::row($id, 'parent-' . $id, ['properties' => $properties, 'required' => $required]);
	}//end parent()

	public function testResolveOnReadMatchesGolden(): void {
		$child = static fn (array $extra): array => self::row(1, 'child', $extra);
		$own = ['properties' => ['own' => ['type' => 'string']], 'required' => ['own']];
		$cases = [
			'no composition' => [[$child($own)]],
			'all of one parent' => [[$child($own + ['all_of' => ['parent-2']]), self::parent()]],
			'all of two parents' => [[$child($own + ['all_of' => ['parent-2', 3]]), self::parent(), self::parent(3, ['age' => ['type' => 'integer']], ['age'])]],
			'all of parents sharing a property' => [[
				$child(['all_of' => ['parent-2', 'parent-3']]),
				self::parent(2, ['a' => ['title' => 'A', 'enum' => [1, 2], 'properties' => ['x' => ['title' => 'X']], 'items' => ['p' => 1]], 's' => 'scalar', 'n' => ['title' => 'N']], []),
				self::parent(3, ['a' => ['description' => 'D', 'enum' => [3], 'properties' => ['y' => ['title' => 'Y']], 'items' => ['q' => 2]], 's' => ['now' => 'array'], 'n' => 'scalar'], []),
			]],
			'all of a parent with parents' => [[$child(['all_of' => ['parent-2']]), self::row(2, 'parent-2', ['all_of' => ['parent-3']]), self::parent(3)]],
			'all of an override' => [[$child(['all_of' => ['parent-2'], 'properties' => ['name' => ['title' => 'Full name']]]), self::parent()]],
			'all of an empty entry' => [[$child(['all_of' => ['', null, 0]])]],
			'all of an inline entry' => [[$child($own + ['all_of' => [['required' => ['own']]]])]],
			'all of a ref entry' => [[$child($own + ['all_of' => [['$ref' => 'parent-2']]])]],
			'all of itself by slug' => [[$child(['all_of' => ['child']])]],
			'all of itself by uuid' => [[$child(['all_of' => ['uuid-1']])]],
			'all of itself by id' => [[$child(['all_of' => [1]])]],
			'all of a missing parent' => [[$child(['all_of' => ['gone']]), null]],
			'all of a loop' => [[$child(['all_of' => ['parent-2']]), self::row(2, 'parent-2', ['all_of' => ['child']]), self::row(1, 'child', ['all_of' => ['parent-2']])]],
			'one of' => [[$child($own + ['one_of' => ['parent-2', ['required' => ['own']]]]), self::parent()]],
			'one of itself' => [[$child(['one_of' => ['child']])]],
			'one of missing' => [[$child(['one_of' => ['gone']]), null]],
			'one of a loop' => [[$child(['one_of' => ['parent-2']]), self::row(2, 'parent-2', ['one_of' => ['child']]), self::row(1, 'child', ['one_of' => ['parent-2']])]],
			'any of' => [[$child($own + ['any_of' => ['parent-2', ['required' => ['own']]]]), self::parent()]],
			'any of itself' => [[$child(['any_of' => ['uuid-1']])]],
			'any of missing' => [[$child(['any_of' => ['gone']]), null]],
			'all of before one of' => [[$child(['all_of' => ['parent-2'], 'one_of' => ['child']]), self::parent()]],
			'one of before any of' => [[$child(['one_of' => ['parent-2'], 'any_of' => ['child']]), self::parent()]],
			'empty lists' => [[$child(['all_of' => [], 'one_of' => [], 'any_of' => []])]],
		];
		$actual = [];
		foreach ($cases as $name => [$rows]) {
			$actual[$name] = $this->outcomeOf($rows, function (SchemaMapper $mapper): array {
				$schema = $mapper->find('child', [], false, false);
				return ['properties' => $schema->getProperties(), 'required' => $schema->getRequired()];
			});
		}

		$this->assertGolden($actual, 'schema-mapper-resolve-golden.json');
	}//end testResolveOnReadMatchesGolden()

	public function testDeltaOnSaveMatchesGolden(): void {
		$same = ['name' => self::NAME, 'own' => ['type' => 'string']];
		$cases = [
			'equal property dropped' => [['all_of' => ['parent-2'], 'properties' => $same, 'required' => ['name', 'own']], [self::parent()]],
			'changed scalar field kept' => [['all_of' => ['parent-2'], 'properties' => ['name' => ['type' => 'string', 'title' => 'Other']]], [self::parent()]],
			'new field kept' => [['all_of' => ['parent-2'], 'properties' => ['name' => self::NAME + ['maxLength' => 5]]], [self::parent()]],
			'nested properties' => [
				['all_of' => ['parent-2'], 'properties' => ['a' => ['type' => 'object', 'properties' => ['x' => ['title' => 'X'], 'y' => ['title' => 'New']]]]],
				[self::parent(2, ['a' => ['type' => 'object', 'properties' => ['x' => ['title' => 'X'], 'y' => ['title' => 'Y']]]], [])],
			],
			'property turned scalar' => [['all_of' => ['parent-2'], 'properties' => ['name' => 'scalar']], [self::parent()]],
			'nested properties turned scalar' => [
				['all_of' => ['parent-2'], 'properties' => ['a' => ['properties' => 'x']]],
				[self::parent(2, ['a' => ['properties' => ['x' => 1]]], [])],
			],
			'two parents' => [['all_of' => ['parent-2', 'parent-3'], 'properties' => $same + ['age' => ['type' => 'integer']], 'required' => ['age']], [self::parent(), self::parent(3, ['age' => ['type' => 'integer']], ['age'])]],
			'parent with parents' => [['all_of' => ['parent-2'], 'properties' => $same], [self::row(2, 'parent-2', ['all_of' => ['parent-3']]), self::row(2, 'parent-2', ['all_of' => ['parent-3']]), self::parent(3)]],
			'ref entry' => [['all_of' => [['$ref' => '#/components/schemas/parent-2']], 'properties' => $same], [self::parent()]],
			'ref bare' => [['all_of' => [['$ref' => 'parent-2']], 'properties' => $same], [self::parent()]],
			'ref ending in a slash' => [['all_of' => [['$ref' => 'schemas/']], 'properties' => $same], [self::parent()]],
			'ref empty' => [['all_of' => [['$ref' => '']], 'properties' => $same], [self::parent()]],
			'ref not a string' => [['all_of' => [['$ref' => 5]], 'properties' => $same], [self::parent()]],
			'conditional entry' => [['all_of' => [['if' => [], 'then' => []]], 'properties' => $same], []],
			'empty entries' => [['all_of' => ['', 0], 'properties' => $same], [self::parent()]],
			'missing parent' => [['all_of' => ['gone'], 'properties' => $same], [null]],
			'one of' => [['one_of' => ['parent-2'], 'all_of' => ['parent-2'], 'properties' => $same], [self::parent()]],
			'any of' => [['any_of' => ['parent-2'], 'all_of' => ['parent-2'], 'properties' => $same], [self::parent()]],
			'no composition' => [['properties' => $same], []],
		];
		$actual = [];
		foreach ($cases as $name => [$fields, $parents]) {
			$actual[$name] = $this->outcomeOf(
				array_merge([self::row(1, 'child')], $parents),
				function (SchemaMapper $mapper) use ($fields): array {
					$schema = new Schema();
					$schema->setId(1);
					$schema->setUuid('uuid-1');
					$schema->setSlug('child');
					$schema->setTitle('Child');
					$schema->setProperties($fields['properties']);
					$schema->setRequired(($fields['required'] ?? []));
					$schema->setAllOf(($fields['all_of'] ?? null));
					$schema->setOneOf(($fields['one_of'] ?? null));
					$schema->setAnyOf(($fields['any_of'] ?? null));
					$saved = $mapper->update($schema);
					return ['properties' => $saved->getProperties(), 'required' => $saved->getRequired()];
				}
			);
		}

		$this->assertGolden($actual, 'schema-mapper-delta-golden.json');
	}//end testDeltaOnSaveMatchesGolden()

	public function testPropertySourceMatchesGolden(): void {
		$cases = [
			'native' => [['own' => []], ['own' => []], [], []],
			'inherited' => [['own' => []], ['own' => [], 'name' => []], ['parent-2'], [self::parent()]],
			'second parent' => [[], ['age' => []], ['parent-2', 'parent-3'], [self::parent(), self::parent(3, ['age' => []], [])]],
			'parent missing' => [[], ['age' => []], ['gone', 'parent-3'], [null, self::parent(3, ['age' => []], [])]],
			'found nowhere' => [[], ['age' => []], ['parent-2'], [self::parent()]],
			'empty parent entry' => [[], ['age' => []], ['', 'parent-3'], [self::parent(3, ['age' => []], [])]],
			'no stored row' => [null, ['age' => []], [], []],
		];
		$actual = [];
		foreach ($cases as $name => [$stored, $all, $allOf, $parents]) {
			$raw = null;
			if ($stored !== null) {
				$raw = ['properties' => json_encode((object) $stored)];
			}

			$actual[$name] = $this->outcomeOf(
				array_merge([$raw], $parents),
				function (SchemaMapper $mapper) use ($all, $allOf): array {
					$schema = new Schema();
					$schema->setId(1);
					$schema->setProperties($all);
					$schema->setAllOf($allOf);
					return $mapper->getPropertySourceMetadata($schema);
				}
			);
		}

		$this->assertGolden($actual, 'schema-mapper-property-source-golden.json');
	}//end testPropertySourceMatchesGolden()

	public function testExtendedByMatchesGolden(): void {
		$found = [['uuid' => 'a'], ['uuid' => null], ['uuid' => 'b']];
		$cases = [
			'known uuid and slug' => [[$found], [self::known(5, 'u', 's')]],
			'known uuid' => [[$found], [self::known(5, 'u', null)]],
			'known slug' => [[$found], [self::known(5, null, 's')]],
			'known empty' => [[$found], [self::known(null, '', '')]],
			'looked up' => [[[self::row(7, 'seven')], $found], ['seven']],
			'lookup finds nothing' => [[[]], ['gone']],
		];
		$actual = [];
		foreach ($cases as $name => [$queue, $args]) {
			$this->queryLog = [];
			$this->builderCount = 0;
			$this->parameterCount = 0;
			$this->queuedRows = $queue;
			$actual[$name] = ['uuids' => $this->makeMapper()->findExtendedBy(...$args), 'queries' => $this->queryLog];
		}

		$lookup = [['id' => 1, 'uuid' => 'uuid-1', 'slug' => 'one'], ['id' => 2, 'uuid' => null, 'slug' => null], ['id' => 3, 'uuid' => 'uuid-3', 'slug' => 'three'], ['id' => 9, 'uuid' => 'uuid-9', 'slug' => '']];
		$map = [
			'references' => [
				['id' => 4, 'uuid' => 'ext-4', 'all_of' => '["one", 2]', 'one_of' => '[{"$ref":"#/components/schemas/three"}]', 'any_of' => null],
				['id' => 5, 'uuid' => 'ext-5', 'all_of' => '["uuid-1", "one"]', 'one_of' => null, 'any_of' => '[{"if":{}}, "gone", ""]'],
				['id' => 6, 'uuid' => null, 'all_of' => '["one"]', 'one_of' => null, 'any_of' => null],
				['id' => 8, 'uuid' => 'ext-8', 'all_of' => '["uuid-3"]', 'one_of' => null, 'any_of' => null],
				['id' => 7, 'uuid' => 'ext-7', 'all_of' => '"scalar"', 'one_of' => 'not json', 'any_of' => null],
			],
			'nothing' => [],
		];
		foreach ($map as $name => $rows) {
			$this->queryLog = [];
			$this->builderCount = 0;
			$this->parameterCount = 0;
			$this->queuedRows = [$rows, $lookup];
			$actual['all ' . $name] = ['map' => $this->makeMapper()->findAllExtendedBy(), 'queries' => $this->queryLog];
		}

		$this->assertGolden($actual, 'schema-mapper-extended-by-golden.json');
	}//end testExtendedByMatchesGolden()

	public function testFlagReadsMatchGolden(): void {
		$actual = [];
		foreach (['findNonSearchableIds', 'findSearchableIds', 'findSmartPickerEnabledIds'] as $read) {
			$this->queryLog = [];
			$this->builderCount = 0;
			$this->parameterCount = 0;
			$this->queuedRows = [[['id' => '3'], ['other' => 1], ['id' => 4]]];
			$actual[$read] = ['ids' => $this->makeMapper()->$read(), 'queries' => $this->queryLog];
		}

		$this->assertGolden($actual, 'schema-mapper-flag-reads-golden.json');
	}//end testFlagReadsMatchGolden()

	public function testReferenceSearchMatchesGolden(): void {
		$target = ['12', 'a0b1-uuid', 'zaak'];
		$cases = [
			'no properties' => [],
			'scalar property' => ['a' => 'x'],
			'no ref' => ['a' => ['type' => 'string']],
			'other ref' => ['a' => ['$ref' => 'besluit']],
			'exact id' => ['a' => ['$ref' => '12']],
			'integer id' => ['a' => ['$ref' => 12]],
			'other integer' => ['a' => ['$ref' => 13]],
			'exact uuid' => ['a' => ['$ref' => 'a0b1-uuid']],
			'exact slug' => ['a' => ['$ref' => 'zaak']],
			'component path' => ['a' => ['$ref' => '#/components/schemas/zaak']],
			'bare schemas path' => ['a' => ['$ref' => 'schemas/zaak']],
			'ends in slug' => ['a' => ['$ref' => 'https://example.org/x/zaak']],
			'uuid in url' => ['a' => ['$ref' => 'https://example.org/a0b1-uuid/x']],
			'ref is a list' => ['a' => ['$ref' => ['zaak']]],
			'nested' => ['a' => ['properties' => ['b' => ['$ref' => 'zaak']]]],
			'nested scalar' => ['a' => ['properties' => 'zaak']],
			'items' => ['a' => ['items' => ['$ref' => 'zaak']]],
			'items nested' => ['a' => ['items' => ['properties' => ['b' => ['$ref' => 'zaak']]]]],
			'items scalar' => ['a' => ['items' => 'zaak']],
			'second property' => ['a' => ['$ref' => 'x'], 'b' => ['$ref' => 'zaak']],
		];
		$actual = [];
		foreach ($cases as $name => $properties) {
			$actual[$name] = $this->makeMapper()->hasReferenceToSchema($properties, Schema::fromRow(['id' => (int)$target[0], 'uuid' => $target[1], 'slug' => $target[2]]));
		}

		$actual['empty slug and uuid'] = $this->makeMapper()->hasReferenceToSchema(['a' => ['$ref' => 'schemas/anything']], Schema::fromRow(['id' => 12, 'uuid' => '', 'slug' => '']));
		$this->assertGolden($actual, 'schema-mapper-reference-search-golden.json');
	}//end testReferenceSearchMatchesGolden()

	/**
	 * Run an action against queued rows, one single-row answer per query.
	 *
	 * @param array<int, array<string, mixed>|null> $rows   Each query's row; null answers with none.
	 * @param callable                              $action What to run on the mapper.
	 */
	private function outcomeOf(array $rows, callable $action): array {
		$this->queuedRows = array_map(static fn (?array $row): array => array_filter([$row]), $rows);
		try {
			return ['result' => $action($this->makeMapper()), 'rowsLeft' => count($this->queuedRows)];
		} catch (\Throwable $e) {
			$thrown = get_class($e);
			if ($e instanceof \TypeError === false) {
				$thrown .= ': ' . $e->getMessage();
			}

			return ['thrown' => $thrown, 'rowsLeft' => count($this->queuedRows)];
		}
	}//end outcomeOf()

	private function assertGolden(array $actual, string $file): void {
		$fixture = __DIR__ . '/fixtures/' . $file;
		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents($fixture, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$this->assertSame(json_decode((string) file_get_contents($fixture), true), $actual);
	}//end assertGolden()

	private function makeMapper(): SchemaMapper {
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
	}//end makeMapper()
}//end class
