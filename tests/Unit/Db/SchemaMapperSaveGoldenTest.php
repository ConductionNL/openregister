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
 * @uses \OCA\OpenRegister\Service\Hinge\HingeAnnotationValidator
 * @uses \OCA\OpenRegister\Service\Lifecycle\LifecycleAnnotationValidator
 * @uses \OCA\OpenRegister\Service\Lifecycle\LifecycleDeclarationForms
 * @uses \OCA\OpenRegister\Service\Lifecycle\LifecycleFieldCheck
 * @uses \OCA\OpenRegister\Service\Lifecycle\LifecycleGraphValidator
 * @uses \OCA\OpenRegister\Service\Lifecycle\LifecycleStateValidator
 * @uses \OCA\OpenRegister\Service\Mcp\McpAnnotationValidator
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

	private function saved(Schema $schema): array {
		$outcome = [];
		try {
			(new ReflectionMethod(SchemaMapper::class, 'cleanObject'))->invoke($this->makeMapper(), $schema);
		} catch (\Throwable $e) {
			$outcome['thrown'] = get_class($e) . ': ' . $e->getMessage();
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
