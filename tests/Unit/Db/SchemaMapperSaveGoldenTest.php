<?php

/**
 * Golden test for what SchemaMapper does to a schema on its way to the database.
 *
 * Runs cleanObject() over every annotation with a set of broken and sound
 * shapes, and records what the save refuses, what it logs, in which order, and
 * how it leaves the schema. Re-record with UPDATE_GOLDEN=1.
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
use ReflectionMethod;

/**
 * @covers \OCA\OpenRegister\Db\SchemaMapper
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Db\MultiTenancyTrait
 * @uses \OCA\OpenRegister\Db\SchemaMapper\AdvisoryAnnotationChecks
 * @uses \OCA\OpenRegister\Db\SchemaMapper\SchemaAnnotation
 * @uses \OCA\OpenRegister\Db\SchemaMapper\AnnotationFaults
 * @uses \OCA\OpenRegister\Exception\UniqueHintException
 * @uses \OCA\OpenRegister\Notifications\Service\Notification\ForcedChannelPolicy
 * @uses \OCA\OpenRegister\Notifications\Service\Notification\NotificationAnnotationValidator
 * @uses \OCA\OpenRegister\Notifications\Service\Notification\NotificationRecipientValidator
 * @uses \OCA\OpenRegister\Notifications\Service\Notification\NotificationRuleContext
 * @uses \OCA\OpenRegister\Notifications\Service\Notification\NotificationSchemaFacts
 * @uses \OCA\OpenRegister\Notifications\Service\Notification\NotificationTriggerValidator
 * @uses \OCA\OpenRegister\Search\Service\Aggregation\AggregationAnnotationValidator
 * @uses \OCA\OpenRegister\Search\Service\Aggregation\AggregationSpecEntry
 * @uses \OCA\OpenRegister\Search\Service\Quality\UniqueHintAnnotationValidator
 * @uses \OCA\OpenRegister\Service\Aggregation\WidgetAnnotationValidator
 * @uses \OCA\OpenRegister\Service\Archival\ArchivalAnnotationValidator
 * @uses \OCA\OpenRegister\Service\Archival\ArchivalFactsValidator
 * @uses \OCA\OpenRegister\Service\Archival\ElementMappingValidator
 * @uses \OCA\OpenRegister\Service\Archival\MdtoElementCatalogue
 * @uses \OCA\OpenRegister\Service\BulkJob\ReversibilityAnnotationValidator
 * @uses \OCA\OpenRegister\Service\BulkJob\ReversibilityDeclarationException
 * @uses \OCA\OpenRegister\Service\Calculation\CalculationAnnotationValidator
 * @uses \OCA\OpenRegister\Service\Calculation\CalculationDeclarationException
 * @uses \OCA\OpenRegister\Service\Calculation\CalculationWalk
 * @uses \OCA\OpenRegister\Service\Calculation\DependencyCycleFinder
 * @uses \OCA\OpenRegister\Service\Calculation\PropertyCalculations
 * @uses \OCA\OpenRegister\Service\Consent\ConsentAnnotationValidator
 * @uses \OCA\OpenRegister\Service\Consent\ConsentDeclarationException
 * @uses \OCA\OpenRegister\Service\Decision\ReviewAnnotationValidator
 * @uses \OCA\OpenRegister\Service\Decision\ReviewDeclarationException
 * @uses \OCA\OpenRegister\Service\ExternalLink\ExternalLinkAnnotationValidator
 * @uses \OCA\OpenRegister\Service\Flow\MacroActionBinding
 * @uses \OCA\OpenRegister\Service\Handoff\HandoffAnnotationValidator
 * @uses \OCA\OpenRegister\Service\Handoff\HandoffContractBindingValidator
 * @uses \OCA\OpenRegister\Service\Handoff\HandoffKindContracts
 * @uses \OCA\OpenRegister\Service\Hinge\HingeAnnotationValidator
 * @uses \OCA\OpenRegister\Service\Lifecycle\LifecycleAnnotationValidator
 * @uses \OCA\OpenRegister\Service\Lifecycle\LifecycleDeclarationForms
 * @uses \OCA\OpenRegister\Service\Lifecycle\LifecycleFieldCheck
 * @uses \OCA\OpenRegister\Service\Lifecycle\LifecycleGraphValidator
 * @uses \OCA\OpenRegister\Service\Lifecycle\LifecycleStateValidator
 * @uses \OCA\OpenRegister\Mcp\Service\Mcp\McpAnnotationValidator
 * @uses \OCA\OpenRegister\Mcp\Service\Mcp\McpVerbAnnotation
 * @uses \OCA\OpenRegister\Service\Merge\MergeAnnotationValidator
 * @uses \OCA\OpenRegister\Service\ObjectAction\ObjectActionDefinition
 * @uses \OCA\OpenRegister\Service\ObjectAction\ObjectActionElevation
 * @uses \OCA\OpenRegister\Service\ObjectAction\ObjectActionInputSchema
 * @uses \OCA\OpenRegister\Service\ObjectAction\ObjectActionShape
 * @uses \OCA\OpenRegister\Service\Party\PartyAnnotationValidator
 * @uses \OCA\OpenRegister\Service\Quality\DedupAnnotationValidator
 * @uses \OCA\OpenRegister\Service\Quality\QualityAnnotationValidator
 * @uses \OCA\OpenRegister\Service\Rbac\HierarchyAnnotationValidator
 * @uses \OCA\OpenRegister\Service\Rbac\MetadataConditionFindings
 * @uses \OCA\OpenRegister\Service\Rbac\RecordMetadataConditions
 * @uses \OCA\OpenRegister\Service\Rbac\RuleColumns
 * @uses \OCA\OpenRegister\Service\Rbac\StoredRuleBlocks
 * @uses \OCA\OpenRegister\Service\Rbac\UnknownRuleKeyFindings
 * @uses \OCA\OpenRegister\Service\Registry\RegistryAnnotationValidator
 * @uses \OCA\OpenRegister\Service\Relation\LinkExposure
 * @uses \OCA\OpenRegister\Service\Relation\RelationAnnotationValidator
 * @uses \OCA\OpenRegister\Service\Relation\RelationDeclarationException
 * @uses \OCA\OpenRegister\Service\Relation\RelationTypeResolver
 * @uses \OCA\OpenRegister\Service\Rules\ConditionDialect
 * @uses \OCA\OpenRegister\Service\Rules\DependentValueDeclarationException
 * @uses \OCA\OpenRegister\Service\Rules\DependentValueValidator
 * @uses \OCA\OpenRegister\Service\Rules\ExpressionDefaultResolver
 * @uses \OCA\OpenRegister\Service\Schemas\ExtendingFormDeclaration
 * @uses \OCA\OpenRegister\Service\Schemas\PropertyVocabularyException
 * @uses \OCA\OpenRegister\Service\Survivorship\SurvivorshipAnnotationValidator
 * @uses \OCA\OpenRegister\Db\SchemaMapper\RecordAnnotationRefusals
 * @uses \OCA\OpenRegister\Db\SchemaMapper\ExchangeAnnotationRefusals
 * @uses \OCA\OpenRegister\Db\SchemaMapper\PropertyDeclarationRefusals
 * @uses \OCA\OpenRegister\Db\SchemaMapper\LifecycleAnnotationCheck
 * @uses \OCA\OpenRegister\Db\SchemaMapper\CalculationAnnotationCheck
 * @uses \OCA\OpenRegister\Db\SchemaMapper\RelationDeclarationCheck
 * @uses \OCA\OpenRegister\Db\SchemaMapper\DeclaredActionCheck
 * @uses \OCA\OpenRegister\Service\WritePhaseProbe
 * @uses \OCA\OpenRegister\Db\EntityChangeDetector
 * @uses \OCA\OpenRegister\Db\SchemaPropertyInheritance
 * @uses \OCA\OpenRegister\Event\SchemaUpdatedEvent
 * @uses \OCA\OpenRegister\Service\AnonymousEvaluationContext
 * @uses \OCA\OpenRegister\Service\Schemas\ScopedPropertyGovernance
 * @uses \OCA\OpenRegister\Db\SchemaMapper\ReferenceNormaliser
 * @uses \OCA\OpenRegister\Db\SchemaMapper\SchemaDefaults
 * @uses \OCA\OpenRegister\Db\SchemaMapper\ConfigurationFields
 * @uses \OCA\OpenRegister\Db\SchemaMapper\FacetConfiguration
 * @uses \OCA\OpenRegister\Db\SchemaMapper\SchemaComposition
 * @uses \OCA\OpenRegister\Db\SchemaMapper\ParentReference
 * @uses \OCA\OpenRegister\Db\SchemaMapper\PropertyMerge
 * @uses \OCA\OpenRegister\Db\SchemaMapper\PropertyDelta
 * @uses \OCA\OpenRegister\Db\SchemaMapper\ExtendedByReads
 * @uses \OCA\OpenRegister\Db\SchemaMapper\AccessDeclarationChecks
 * @uses \OCA\OpenRegister\Db\SchemaMapper\SubjectDeclarationRefusals
 * @uses \OCA\OpenRegister\Db\SchemaMapper\AuthorizationBlockCheck
 * @uses \OCA\OpenRegister\Event\PermissionsDeclaringEvent
 * @uses \OCA\OpenRegister\Service\Rbac\AuthorizationDenyValidator
 * @uses \OCA\OpenRegister\Service\Rbac\DenyEntryMatcher
 * @uses \OCA\OpenRegister\Service\Rbac\DenyResolver
 * @uses \OCA\OpenRegister\Service\Rbac\DepartmentMatrixValidator
 * @uses \OCA\OpenRegister\Service\Rbac\MatrixRowWhere
 * @uses \OCA\OpenRegister\Service\Rbac\PermissionCatalogue
 * @uses \OCA\OpenRegister\Service\Lifecycle\LifecycleTransitionOptionsValidator
 */
class SchemaMapperSaveGoldenTest extends TestCase {
	use RecordingQueryBuilderTrait;

	private const FIXTURE = __DIR__ . '/fixtures/schema-mapper-save-golden.json';

	private const PROPERTIES = [
		'name' => ['type' => 'string', 'required' => true],
		'status' => ['type' => 'string', 'enum' => ['open', 'closed']],
		'startDate' => ['type' => 'string', 'format' => 'date'],
		'count' => ['type' => 'integer', 'facetable' => 'TRUE '],
		'link' => ['type' => 'string', '$ref' => 'far-schema', 'relation' => ['type' => 'owns', 'exposes' => ['nope']]],
	];

	private const BROKEN = [
		'scalar' => 'nonsense',
		'empty list' => [],
		'unknown key' => ['bogus' => true],
		'list of nothing' => [['bogus' => true]],
		'named nothing' => ['first' => ['bogus' => true]],
		'property that does not exist' => ['property' => 'nope', 'properties' => ['nope'], 'identity' => 'nope', 'owned' => ['nope']],
	];

	private const ANNOTATIONS = [
		'x-openregister-lifecycle',
		'x-openregister-mdto-mapping',
		'x-openregister-aggregations',
		'x-openregister-calculations',
		'x-openregister-relation-types',
		'x-openregister-quality',
		'x-openregister-dedup',
		'x-openregister-unique-hint',
		'x-openregister-lenses',
		'x-openregister-list',
		'x-openregister-geo-inheritance',
		'x-openregister-survivorship',
		'x-openregister-merge',
		'x-openregister-party',
		'x-openregister-notifications',
		'x-openregister-widgets',
		'x-openregister-archival',
		'x-openregister-handoff',
		'handoffContract',
		'x-openregister-mcp',
		'x-openregister-registry',
		'x-openregister-external-links',
		'x-openregister-extends-form',
		'x-openregister-hierarchy',
		'x-openregister-action',
		'x-openregister-review',
		'x-openregister-no-such-key',
	];

	private array $logs = [];

	private static function cases(): array {
		$cases = [
			'nothing declared' => [[], []],
			'properties only' => [[], self::PROPERTIES],
			'name and description found' => [[], ['titel' => ['type' => 'string'], 'summary' => ['type' => 'string']]],
			'name field given' => [['objectNameField' => 'name', 'objectDescriptionField' => 'status'], self::PROPERTIES],
			'name field missing' => [['objectNameField' => 'nope'], self::PROPERTIES],
			'description field missing' => [['objectDescriptionField' => 'nope'], self::PROPERTIES],
			'name template' => [['objectNameField' => '{{ name }} {{ status }}'], self::PROPERTIES],
			'name template unknown' => [['objectNameField' => '{{ name }} {{ nope }}'], self::PROPERTIES],
			'name template empty' => [['objectNameField' => '{{ }}'], self::PROPERTIES],
			'name fallback' => [['objectNameField' => 'nope | name'], self::PROPERTIES],
			'name fallback unknown' => [['objectNameField' => 'nope | gone'], self::PROPERTIES],
			'name dotted' => [['objectNameField' => 'name.nl.long'], self::PROPERTIES],
			'name dotted from nothing' => [['objectNameField' => '.nl'], ['' => ['type' => 'string']]],
			'name dotted unknown' => [['objectNameField' => 'nope.nl'], self::PROPERTIES],
			'ref as array' => [[], ['a' => ['$ref' => ['id' => 'x']]]],
			'ref as object' => [[], ['a' => ['$ref' => (object) ['id' => 'x']]]],
			'ref as int' => [[], ['a' => ['$ref' => 5]]],
			'ref as float' => [[], ['a' => ['$ref' => 1.5]]],
			'ref in items' => [[], ['a' => ['items' => ['b' => ['$ref' => ['id' => 'y']]]]]],
			'ref in nested' => [[], ['a' => ['properties' => ['b' => ['$ref' => ['id' => 'y']]]]]],
			'ref bad in nested' => [[], ['a' => ['properties' => ['b' => ['$ref' => ['x' => 1]]]]]],
			'required flags' => [[], ['a' => ['required' => 'true'], 'b' => ['required' => ' TRUE '], 'c' => ['required' => false], 'd' => ['required' => true]]],
			'consent clean' => [[], ['a' => ['type' => 'string']]],
			'consent broken' => [[], ['a' => ['type' => 'string', 'x-openregister-consent' => 'nonsense']]],
			'dependent broken' => [[], ['a' => ['type' => 'string', 'x-openregister-dependent-values' => ['on' => 'nope']]]],
			'default expression broken' => [[], ['a' => ['type' => 'string', 'x-openregister-default-expression' => ['nope' => 1]]]],
			'audit without read' => [[], ['a' => ['type' => 'string', 'authorization' => ['audit' => true]]]],
			'audit with read' => [[], ['a' => ['type' => 'string', 'authorization' => ['audit' => true, 'read' => ['admin']]]]],
			'forwarded calculation broken' => [[], ['a' => ['type' => 'number', 'calculation' => ['nope' => 1]]]],
			'calculation on both' => [
				['x-openregister-calculations' => ['a' => ['expression' => ['+' => [1, 2]]]]],
				['a' => ['type' => 'number', 'calculation' => ['+' => [1, 2]]]],
			],
			'two broken annotations in order' => [
				['x-openregister-quality' => ['bogus' => true], 'x-openregister-dedup' => ['bogus' => true], 'x-openregister-merge' => ['bogus' => true], 'x-openregister-widgets' => 'x'],
				self::PROPERTIES,
			],
			'merge window' => [['x-openregister-merge' => ['reversalWindowDays' => 0]], self::PROPERTIES],
			'party kind' => [['x-openregister-party' => ['kind' => '']], self::PROPERTIES],
			'list column' => [['x-openregister-list' => ['columns' => [['property' => 'nope'], []]]], self::PROPERTIES],
			'lifecycle provider' => [['x-openregister-lifecycle' => ['provider' => '']], self::PROPERTIES],
			'lifecycle condition' => [
				['x-openregister-lifecycle' => ['property' => 'status', 'transitions' => [['from' => 'open', 'to' => 'closed', 'condition' => 'x']]]],
				self::PROPERTIES,
			],
			'archival unknown key' => [['x-openregister-archival' => ['retention' => [['condition' => 'x', 'retention' => 'P1Y', 'reason' => 'x']], 'bogus' => 1]], self::PROPERTIES],
			'action kind' => [['x-openregister-action' => ['close' => ['name' => 'Close', 'kind' => 'nonsense']]], self::PROPERTIES],
			'action destroy reversible' => [['x-openregister-action' => ['purge' => ['name' => 'Purge', 'kind' => 'destroy', 'reversible' => true]]], self::PROPERTIES],
			'action flow' => [['x-openregister-action' => ['run' => ['name' => 'Run', 'flow' => 'a-flow']]], self::PROPERTIES],
			'action macro' => [['x-openregister-action' => ['run' => ['name' => 'Run', 'macro' => ['flow' => 'a-flow']]]], self::PROPERTIES],
			'mdto property' => [['x-openregister-mdto-mapping' => ['naam' => ['property' => 'name']]], self::PROPERTIES],
			'mdto unknown property' => [['x-openregister-mdto-mapping' => ['naam' => ['property' => 'nope']]], self::PROPERTIES],
			'handoff lifecycle state' => [
				[
					'x-openregister-handoff' => [['id' => 'send', 'trigger' => 'lifecycle:nope']],
					'x-openregister-lifecycle' => ['field' => 'status', 'property' => 'status'],
				],
				self::PROPERTIES,
			],
			'contract implements' => [['implements' => ['https://openregister.app/ns#Case'], 'handoffContract' => ['https://openregister.app/ns#Case' => []]], self::PROPERTIES],
			'contract jsonld' => [['jsonld' => ['type' => 'https://openregister.app/ns#Case'], 'handoffContract' => ['https://openregister.app/ns#Case' => []]], self::PROPERTIES],
			'contract schema org' => [['x-schema-org' => 'https://openregister.app/ns#Case', 'handoffContract' => ['https://openregister.app/ns#Case' => []]], self::PROPERTIES],
			'extends form on schema and property' => [
				['x-openregister-extends-form' => ['forwards' => ['nope-a']]],
				['a' => ['type' => 'string', 'x-openregister-extends-form' => ['forwards' => ['nope-b']]]],
			],
			'extends form on property' => [[], ['a' => ['type' => 'string', 'x-openregister-extends-form' => ['forwards' => ['nope-b']]]]],
			'calculation references broken' => [
				[
					'x-openregister-calculations' => ['t' => ['expression' => ['+' => [1, 2]]]],
					'x-openregister-references' => ['r' => 'nonsense'],
				],
				self::PROPERTIES,
			],
			'calculation aggregate refs broken' => [
				[
					'x-openregister-calculations' => ['t' => ['expression' => ['+' => [1, 2]]]],
					'x-openregister-aggregate-refs' => ['r' => 'nonsense'],
				],
				self::PROPERTIES,
			],
			'action two bodies' => [['x-openregister-action' => ['run' => ['name' => 'Run', 'flow' => 'a-flow', 'steps' => [['id' => 'x']]]]], self::PROPERTIES],
			'action inputs scalar' => [['x-openregister-action' => ['run' => ['name' => 'Run', 'flow' => 'a-flow', 'inputs' => 'x']]], self::PROPERTIES],
			'name field integer' => [['objectNameField' => 5], ['5' => ['type' => 'string']]],
			'name field true' => [['objectNameField' => true], self::PROPERTIES],
			'name field list' => [['objectNameField' => ['name']], self::PROPERTIES],
			'property not an array' => [[], ['a' => 'nonsense']],
			'hinge with two keys' => [['x-openregister-lenses' => ['bogus' => true], 'x-openregister-list' => 'x'], self::PROPERTIES],
		];
		foreach (self::ANNOTATIONS as $key) {
			foreach (self::BROKEN as $shape => $value) {
				$cases[$key . ' ' . $shape] = [[$key => $value], self::PROPERTIES];
			}
		}

		return $cases;
	}//end cases()

	public function testSaveMatchesGolden(): void {
		$actual = [];
		foreach (self::cases() as $name => [$configuration, $properties]) {
			$this->logs = [];
			$this->queuedRows = [];
			$schema = new Schema();
			$schema->setTitle('Golden Schema');
			$schema->setConfiguration($configuration);
			$schema->setProperties($properties);
			$actual[$name] = $this->saved($schema);
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$expected = json_decode((string) file_get_contents(self::FIXTURE), true);
		foreach ($expected as $name => $outcome) {
			$this->assertSame($outcome, $actual[$name] ?? null, $name);
		}

		$this->assertSame(array_keys($expected), array_keys($actual));
	}//end testSaveMatchesGolden()

	/**
	 * The exposure check reads the far schema through the mapper's own lookup.
	 */
	public function testFarSchemaLookupMatchesGolden(): void {
		$far = ['id' => 9, 'uuid' => 'far-uuid', 'slug' => 'besluit', 'title' => 'Besluit', 'properties' => '{"zaaknummer":{"type":"string"}}'];
		$link = ['type' => 'string', 'x-openregister-relation' => ['type' => 'gerelateerd']];
		$exposing = ['key' => 'gerelateerd', 'label' => 'related to', 'exposes' => ['zaaknummer', 'zaknummer']];
		$silent = ['key' => 'gerelateerd', 'label' => 'related to'];
		$cases = [
			'ref path' => [$exposing, $link + ['$ref' => '#/components/schemas/besluit'], [[$far]]],
			'ref bare' => [$exposing, $link + ['$ref' => ' besluit '], [[$far]]],
			'ref items' => [$exposing, $link + ['items' => ['$ref' => 'schemas/besluit']], [[$far]]],
			'ref items object' => [$exposing, $link + ['items' => (object) ['$ref' => 'schemas/besluit']], [[$far]]],
			'ref trailing slash' => [$exposing, $link + ['$ref' => 'schemas/'], [[$far]]],
			'ref not a string' => [$exposing, $link + ['$ref' => 5], [[$far]]],
			'ref empty' => [$exposing, $link + ['$ref' => '  '], [[$far]]],
			'far missing' => [$exposing, $link + ['$ref' => 'besluit'], [[]]],
			'exposes nothing' => [$silent, $link + ['$ref' => 'besluit'], [[$far]]],
			'exposes known only' => [['exposes' => ['zaaknummer']] + $silent, $link + ['$ref' => 'besluit'], [[$far]]],
		];
		$actual = [];
		foreach ($cases as $name => [$type, $property, $rows]) {
			$this->logs = [];
			$this->queryLog = [];
			$this->queuedRows = $rows;
			$schema = new Schema();
			$schema->setId(7);
			$schema->setTitle('Golden Schema');
			$schema->setProperties(['link' => $property]);
			$schema->setConfiguration(['x-openregister-relation-types' => [$type]]);
			$outcome = $this->saved($schema);
			$actual[$name] = ['thrown' => ($outcome['thrown'] ?? null), 'errors' => ($outcome['errors'] ?? null), 'rowsLeft' => count($this->queuedRows)];
		}

		$fixture = __DIR__ . '/fixtures/schema-mapper-save-far-golden.json';
		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents($fixture, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$this->assertSame(json_decode((string) file_get_contents($fixture), true), $actual);
	}//end testFarSchemaLookupMatchesGolden()

	/**
	 * The authorization block, the reveal audit and a hierarchy over the schema's own identities.
	 */
	public function testAccessDeclarationsMatchGolden(): void {
		$self = ['type' => 'string', '$ref' => 'uuid-1'];
		$cases = [
			'grant' => [['read' => ['team']], [], []],
			'unknown verb' => [['bogus' => ['team']], [], []],
			'grant and deny the same' => [['read' => ['team'], 'deny' => ['read' => ['team']]], [], []],
			'matrix on a missing field' => [['read' => ['team'], 'matrix' => ['field' => 'afdeling', 'userSource' => ['groups' => true], 'rows' => [['group' => 'team', 'value' => 'x']]]], [], []],
			'matrix with no rows' => [['matrix' => []], [], []],
			'read rule without audit' => [[], [], ['a' => ['authorization' => ['read' => []]]]],
			'audit with an empty read' => [[], [], ['a' => ['authorization' => ['audit' => true, 'read' => []]]]],
			'audit false' => [[], [], ['a' => ['authorization' => ['audit' => false]]]],
			'hierarchy on itself by uuid' => [[], ['x-openregister-hierarchy' => ['parent' => 'up', 'property' => 'up']], ['up' => $self]],
			'hierarchy on another schema' => [[], ['x-openregister-hierarchy' => ['parent' => 'up', 'property' => 'up']], ['up' => ['type' => 'string', '$ref' => 'other']]],
		];
		$actual = [];
		foreach ($cases as $name => [$authorization, $configuration, $properties]) {
			$this->logs = [];
			$this->queuedRows = [];
			$schema = new Schema();
			$schema->setId(1);
			$schema->setUuid('uuid-1');
			$schema->setSlug('golden');
			$schema->setTitle('Golden Schema');
			$schema->setConfiguration($configuration);
			$schema->setProperties($properties);
			$schema->setAuthorization($authorization);
			$outcome = $this->saved($schema);
			$actual[$name] = ['thrown' => ($outcome['thrown'] ?? null), 'logs' => $outcome['logs']];
		}

		$this->assertGolden($actual, 'schema-mapper-save-access-golden.json');
	}//end testAccessDeclarationsMatchGolden()

	/**
	 * What the save fills in depends on what the schema already carries.
	 */
	public function testDefaultsMatchGolden(): void {
		$cases = [
			'all given' => ['setUuid' => 'u-1', 'setSlug' => 'given', 'setVersion' => '2.0.0', 'setSource' => 'external', 'setRequired' => ['a']],
			'empty source' => ['setSource' => ''],
			'empty slug' => ['setSlug' => '', 'setTitle' => '  Één  Titel!! -- 2 '],
			'no title' => ['setTitle' => null],
		];
		$actual = [];
		foreach ($cases as $name => $setters) {
			$this->logs = [];
			$schema = new Schema();
			$schema->setTitle('Golden Schema');
			$schema->setProperties(['a' => ['required' => true], 'b' => ['required' => true]]);
			foreach ($setters as $setter => $value) {
				$schema->$setter($value);
			}

			$actual[$name] = $this->saved($schema)['schema'];
			unset($actual[$name]['properties'], $actual[$name]['configuration']);
		}

		$this->assertGolden($actual, 'schema-mapper-save-defaults-golden.json');
	}//end testDefaultsMatchGolden()

	/**
	 * The facet block is derived on update, after the save checks.
	 */
	public function testFacetsMatchGolden(): void {
		$cases = [
			'off wins over name' => ['status' => ['type' => 'string', 'facetable' => false]],
			'off as word' => ['status' => ['facetable' => ' FALSE ']],
			'off as string' => ['status' => ['facetable' => 'false']],
			'on' => ['x' => ['facetable' => true], 'y' => ['facetable' => 'true'], 'z' => ['facetable' => ' True ']],
			'on date type' => ['x' => ['facetable' => true, 'type' => 'date'], 'y' => ['facetable' => true, 'type' => 'datetime'], 'z' => ['facetable' => true, 'type' => 'date-time']],
			'on date format' => ['x' => ['facetable' => true, 'format' => 'date'], 'y' => ['facetable' => true, 'format' => 'date-time'], 'z' => ['facetable' => true, 'format' => 'datetime']],
			'on enum and number' => ['x' => ['facetable' => true, 'enum' => ['a']], 'y' => ['facetable' => true, 'type' => 'integer']],
			'unknown flag' => ['x' => ['facetable' => 'yes'], 'y' => ['facetable' => 1], 'status' => ['facetable' => 'maybe']],
			'common names' => ['Status' => [], 'TAGS' => [], 'language' => ['format' => 'date'], 'version' => ['type' => 'date']],
			'enum' => ['x' => ['enum' => ['a']], 'y' => ['enum' => []], 'z' => ['enum' => 'a']],
			'detected date' => ['x' => ['type' => 'date'], 'y' => ['type' => 'datetime'], 'z' => ['type' => 'date-time'], 'w' => ['format' => 'date']],
			'date words' => ['createdAt' => [], 'UPDATED' => [], 'lastModified' => [], 'birthdate' => [], 'runtime' => [], 'timestamp' => [], 'other' => []],
			'integer name' => ['0' => ['enum' => ['a']]],
		];
		$actual = [];
		foreach ($cases as $name => $properties) {
			$this->queuedRows = [['id' => 7, 'uuid' => 'u-7', 'title' => 'Old', 'version' => '1.0.0']];
			$this->queuedRows = [[$this->queuedRows[0]]];
			$schema = new Schema();
			$schema->setId(7);
			$schema->setTitle('Golden Schema');
			$schema->setProperties($properties);
			try {
				$this->makeMapper()->update($schema);
			} catch (\Throwable $e) {
				$actual[$name] = ['thrown' => get_class($e) . ': ' . $e->getMessage()];
			}

			$actual[$name] = ($actual[$name] ?? []) + ['facets' => $schema->getFacets()];
		}

		$this->assertGolden($actual, 'schema-mapper-save-facets-golden.json');
	}//end testFacetsMatchGolden()

	private function assertGolden(array $actual, string $file): void {
		$fixture = __DIR__ . '/fixtures/' . $file;
		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents($fixture, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$this->assertSame(json_decode((string) file_get_contents($fixture), true), $actual);
	}//end assertGolden()

	private function saved(Schema $schema): array {
		$outcome = [];
		try {
			(new ReflectionMethod(SchemaMapper::class, 'cleanObject'))->invoke($this->makeMapper(), $schema);
		} catch (\Throwable $e) {
			$outcome['thrown'] = get_class($e);
			if ($e instanceof \TypeError === false) {
				$outcome['thrown'] .= ': ' . $e->getMessage();
			}

			if (method_exists($e, 'getErrors') === true) {
				$outcome['errors'] = $e->getErrors();
			}
		}

		$outcome['logs'] = $this->logs;
		$outcome['schema'] = [
			'hasUuid' => $schema->getUuid() !== null,
			'slug' => $schema->getSlug(),
			'version' => $schema->getVersion(),
			'source' => $schema->getSource(),
			'required' => $schema->getRequired(),
			'properties' => $schema->getProperties(),
			'configuration' => $schema->getConfiguration(),
		];

		return $outcome;
	}//end saved()

	private function makeMapper(): SchemaMapper {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn () => $this->recordingBuilder());
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'info', 'notice', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string|\Stringable $message, array $context = []) use ($level): void {
					unset($context['file'], $context['line']);
					$this->logs[] = [$level, (string) $message, $context];
				}
			);
		}

		return new SchemaMapper(
			$db,
			$this->createMock(IEventDispatcher::class),
			$this->createMock(PropertyValidatorHandler::class),
			$this->createMock(OrganisationMapper::class),
			$this->createMock(IUserSession::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(IAppConfig::class),
			$logger
		);
	}//end makeMapper()
}//end class
