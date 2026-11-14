<?php

/**
 * OpenRegister SchemaMapperDiscardedAnnotationsTest
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\Schema;
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
 * @uses \OCA\OpenRegister\Db\SchemaMapper\ReferenceLoopCheck
 * @uses \OCA\OpenRegister\Db\SchemaMapper\ReferenceLoops
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
 * @uses \OCA\OpenRegister\Db\MappingMapper
 * @uses \OCA\OpenRegister\Db\SchemaMapper\NotificationAnnotationCheck
 * @uses \OCA\OpenRegister\Db\SchemaMapper\SchemaLookup
 * @uses \OCA\OpenRegister\Db\SchemaMapper\SchemaMapperServices
 * @uses \OCA\OpenRegister\Db\SchemaMapper\SchemaReadTrace
 * @uses \OCA\OpenRegister\Db\Schema\ConfigurationEntryRules
 * @uses \OCA\OpenRegister\Db\Schema\ConfigurationValidator
 * @uses \OCA\OpenRegister\Db\Tenancy\OrganisationFilterOptions
 * @uses \OCA\OpenRegister\Db\Tenancy\TenancyServices
 * @uses \OCA\OpenRegister\Service\ObjectAction\ObjectActionDeclarationReader
 * @uses \OCA\OpenRegister\Service\ObjectAction\ObjectActionDeclarations
 * @uses \OCA\OpenRegister\Service\ObjectAction\SchemaRules
 * @uses \OCA\OpenRegister\Service\Rbac\AuthorizationEdit
 * @uses \OCA\OpenRegister\Service\Rbac\RbacChecks
 * @uses \OCA\OpenRegister\Service\Rbac\SchemaSave
 * @uses \OCA\OpenRegister\Service\Relation\DeclaredProperty
 * @uses \OCA\OpenRegister\Service\Relation\DescribeRun
 * @uses \OCA\OpenRegister\Service\Relation\ExposureDeclaration
 * @uses \OCA\OpenRegister\Service\Relation\RelationValueReader
 * @uses \OCA\OpenRegister\Service\Rules\ExpressionDefaultDeclarations
 * @uses \OCA\OpenRegister\Service\Schemas\SchemaDeclarationValidators
 * @uses \OCA\OpenRegister\Service\WritePhase
 * @uses \OCA\OpenRegister\Db\RegisterMapper
 * @uses \OCA\OpenRegister\Db\Tenancy\EntityRbacTrait
 * @uses \OCA\OpenRegister\Db\Tenancy\OrganisationFilterTrait
 * @uses \OCA\OpenRegister\Db\Tenancy\OrganisationWriteGuardTrait
 * @uses \OCA\OpenRegister\Db\Tenancy\TenancyIdentityTrait
 * @uses \OCA\OpenRegister\Db\Schema\AnnotationVocabulary
 * @uses \OCA\OpenRegister\Db\Schema\ConfigurationValueRules
 * @uses \OCA\OpenRegister\Db\Schema\DeclaredActions
 * @uses \OCA\OpenRegister\Mcp\Service\Mcp\McpAnnotationValidators
 * @uses \OCA\OpenRegister\Notifications\Service\Notification\DeclaredTrigger
 * @uses \OCA\OpenRegister\Notifications\Service\Notification\NotificationAnnotationValidators
 * @uses \OCA\OpenRegister\Search\Service\SearchAnnotationValidators
 * @uses \OCA\OpenRegister\Service\Archival\ArchivalText
 * @uses \OCA\OpenRegister\Service\Archival\MdtoMappingEntry
 * @uses \OCA\OpenRegister\Service\Archival\MdtoMappingRun
 * @uses \OCA\OpenRegister\Service\Handoff\HandoffAnnotationEntry
 * @uses \OCA\OpenRegister\Service\Handoff\HandoffKindBinding
 * @uses \OCA\OpenRegister\Service\Handoff\HandoffKindContract
 * @uses \OCA\OpenRegister\Service\Handoff\HandoffMappingChecks
 * @uses \OCA\OpenRegister\Service\Handoff\HandoffSuccessUpdateChecks
 * @uses \OCA\OpenRegister\Service\Hinge\DeclaredLens
 * @uses \OCA\OpenRegister\Service\Lifecycle\StateDeclarationScope
 * @uses \OCA\OpenRegister\Service\ObjectAction\ObjectActionBody
 * @uses \OCA\OpenRegister\Service\ObjectAction\ObjectActionEntry
 * @uses \OCA\OpenRegister\Service\ObjectAction\ObjectActionExecution
 * @uses \OCA\OpenRegister\Service\ObjectAction\ObjectActionIdentity
 * @uses \OCA\OpenRegister\Service\ObjectAction\ObjectActionOffer
 * @uses \OCA\OpenRegister\Service\ObjectAction\ObjectActionPresentation
 * @uses \OCA\OpenRegister\Service\ObjectAction\ObjectActionRunPolicy
 * @uses \OCA\OpenRegister\Service\Party\PartyPropertyFields
 * @uses \OCA\OpenRegister\Service\Quality\MasterDataAnnotationValidators
 * @uses \OCA\OpenRegister\Service\Rbac\AccessDeclarationValidators
 * @uses \OCA\OpenRegister\Service\Rbac\DenyCollision
 * @uses \OCA\OpenRegister\Service\Rbac\GrantDeclaration
 * @uses \OCA\OpenRegister\Service\Rbac\MatrixRowRun
 * @uses \OCA\OpenRegister\Service\Rbac\MatrixShape
 * @uses \OCA\OpenRegister\Service\Rules\DependentValueDeclaration
 * @uses \OCA\OpenRegister\Service\Schemas\SchemaAnnotationValidators
 * @uses \OCA\OpenRegister\Service\Survivorship\MdmAnnotationChecks
 * @uses \OCA\OpenRegister\Db\SchemaMapper\DiscardedAnnotations
 *
 * A schema save that ignores advisory annotations lists them, one entry per family,
 * still logs each one, and still saves (aggregation-dialect-repair, Fix 4).
 *
 * @spec openspec/changes/aggregation-dialect-repair/specs/annotation-validation-surfacing/spec.md
 */
final class SchemaMapperDiscardedAnnotationsTest extends TestCase {

	/**
	 * The warnings logged, in order.
	 *
	 * @var list<string>
	 */
	private array $warnings = [];

	/**
	 * Two invalid families on one save are two entries, each still logged, and the save stands.
	 *
	 * @return void
	 */
	public function testEachDiscardedFamilyIsListedAndStillLogged(): void {
		$mapper = $this->mapper();

		$this->save(mapper: $mapper, configuration: [
			'x-openregister-quality' => ['bogus' => ['nested' => true], 7 => 'x'],
			'x-openregister-merge' => ['reversalWindowDays' => -1],
		]);

		$entries = $mapper->discardedAnnotations();
		self::assertSame(['quality', 'merge'], array_column($entries, 'family'));
		self::assertSame(['meeting', 'meeting'], array_column($entries, 'schema'));
		self::assertNotEmpty($entries[0]['messages']);
		self::assertNotEmpty($entries[1]['messages']);
		$annotationWarnings = array_values(array_filter($this->warnings, static fn (string $w): bool => str_contains($w, 'is invalid and was ignored')));
		self::assertCount(2, $annotationWarnings);
		self::assertStringStartsWith('x-openregister-quality annotation on schema "meeting"', $annotationWarnings[0]);
		self::assertStringStartsWith('x-openregister-merge annotation on schema "meeting"', $annotationWarnings[1]);
	}//end testEachDiscardedFamilyIsListedAndStillLogged()

	/**
	 * A clean save lists nothing, and replaces what an earlier save listed.
	 *
	 * @return void
	 */
	public function testACleanSaveListsNothing(): void {
		$mapper = $this->mapper();
		self::assertSame([], $mapper->discardedAnnotations(), 'nothing before any save');

		$this->save(mapper: $mapper, configuration: ['x-openregister-merge' => ['reversalWindowDays' => -1]]);
		self::assertCount(1, $mapper->discardedAnnotations());

		$this->save(mapper: $mapper, configuration: []);
		self::assertSame([], $mapper->discardedAnnotations());
	}//end testACleanSaveListsNothing()

	/**
	 * Run the save-time checks on a schema slugged `meeting`.
	 *
	 * @param SchemaMapper         $mapper        The mapper.
	 * @param array<string, mixed> $configuration The schema configuration.
	 *
	 * @return void
	 */
	private function save(SchemaMapper $mapper, array $configuration): void {
		$schema = new Schema();
		$schema->setSlug('meeting');
		$schema->setTitle('Meeting');
		$schema->setProperties(['title' => ['type' => 'string']]);
		$schema->setConfiguration($configuration);

		(new ReflectionMethod(SchemaMapper::class, 'cleanObject'))->invoke($mapper, $schema);
	}//end save()

	/**
	 * A mapper whose logger keeps the warnings.
	 *
	 * @return SchemaMapper The mapper.
	 */
	private function mapper(): SchemaMapper {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string|\Stringable $message): void {
				$this->warnings[] = (string)$message;
			}
		);

		return new SchemaMapper(
			db: $this->createMock(IDBConnection::class),
			tenancy: new TenancyServices(
				organisationMapper: $this->createMock(OrganisationMapper::class),
				userSession: $this->createMock(IUserSession::class),
				groupManager: $this->createMock(IGroupManager::class)
			),
			appConfig: $this->createMock(IAppConfig::class),
			services: new SchemaMapperServices(
				eventDispatcher: $this->createMock(IEventDispatcher::class),
				validator: $this->createMock(PropertyValidatorHandler::class),
				logger: $logger
			)
		);
	}//end mapper()
}//end class
