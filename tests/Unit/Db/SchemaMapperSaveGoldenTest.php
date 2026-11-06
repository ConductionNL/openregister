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
 * @uses \OCA\OpenRegister\Service\Lifecycle\LifecycleTransitionOptionsValidator
