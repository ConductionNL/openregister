<?php

declare(strict_types=1);

/**
 * ObjectService::validateObject(): a sample checked against the schema and the
 * save rules, as a create or an update, writing nothing.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/records-validate-without-saving/specs/objects-crud/spec.md#requirement-a-record-can-be-validated-without-saving-it
 *
 * @covers \OCA\OpenRegister\Service\ObjectService
 * @uses   \OCA\OpenRegister\Service\Object\BulkCacheInvalidator
 * @uses   \OCA\OpenRegister\Service\Object\ObjectSaveRun
 * @uses   \OCA\OpenRegister\Service\Object\ObjectCacheInvalidator
 * @uses   \OCA\OpenRegister\Service\Object\ReferencePreviewInvalidation
 * @uses   \OCA\OpenRegister\Service\Object\SavedObjectScope
 * @uses   \OCA\OpenRegister\Service\Object\SavedScopeIds
 * @uses \OCA\OpenRegister\Service\Object\DraftStatusPolicy
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Db\Register
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Exception\NotAuthorizedException
 * @uses \OCA\OpenRegister\Search\Db\View
 * @uses \OCA\OpenRegister\Service\RegisterScopedSchemaResolver
 * @uses \OCA\OpenRegister\Service\Object\ObjectFindRequest
 * @uses \OCA\OpenRegister\Service\Object\ObjectFindScope
 * @uses \OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget
 * @uses \OCA\OpenRegister\Service\Rbac\HierarchyGrantExpander
 * @uses   \OCA\OpenRegister\Service\Object\SaveAccessRequest
 * @uses \OCA\OpenRegister\Service\WritePhaseProbe
 * @uses \OCA\OpenRegister\Service\Object\NotSuppliedHandler
 * @uses \OCA\OpenRegister\Service\Object\RepeatingGroupValidator
 * @uses \OCA\OpenRegister\Service\Geo\GeoJsonGeometryValidator
 * @uses \OCA\OpenRegister\Service\Hinge\LensResolver
 * @uses \OCA\OpenRegister\Service\AnonymousEvaluationContext
 * @uses \OCA\OpenRegister\Service\Object\DraftCallerRule
 * @uses \OCA\OpenRegister\Service\Rbac\ExplicitReveal
 * @uses \OCA\OpenRegister\Service\SystemOperationContext
 * @uses   \OCA\OpenRegister\Service\Object\ObjectDateValues
 * @uses \OCA\OpenRegister\Db\Schema\ObjectSourceDeclaration
 * @uses \OCA\OpenRegister\Db\Schema\PresentationAnnotations
 * @uses \OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions
 * @uses \OCA\OpenRegister\Kernel\Contracts\Value\SaveActor
 * @uses \OCA\OpenRegister\Kernel\Contracts\Value\SavePolicy
 * @uses \OCA\OpenRegister\Service\Object\Collaborators\ObjectAccessServices
 * @uses \OCA\OpenRegister\Service\Object\Collaborators\ObjectAuthorisationServices
 * @uses \OCA\OpenRegister\Service\Object\Collaborators\ObjectCallerServices
 * @uses \OCA\OpenRegister\Service\Object\Collaborators\ObjectHistoryServices
 * @uses \OCA\OpenRegister\Service\Object\Collaborators\ObjectMapperServices
 * @uses \OCA\OpenRegister\Service\Object\Collaborators\ObjectPlatformServices
 * @uses \OCA\OpenRegister\Service\Object\Collaborators\ObjectQueryServices
 * @uses \OCA\OpenRegister\Service\Object\Collaborators\ObjectReadServices
 * @uses \OCA\OpenRegister\Service\Object\Collaborators\ObjectRemovalServices
 * @uses \OCA\OpenRegister\Service\Object\Collaborators\ObjectRuntimeServices
 * @uses \OCA\OpenRegister\Service\Object\Collaborators\ObjectSaveServices
 * @uses \OCA\OpenRegister\Service\Object\Collaborators\ObjectSearchServices
 * @uses \OCA\OpenRegister\Service\Object\Collaborators\ObjectShapingServices
 * @uses \OCA\OpenRegister\Service\Object\Collaborators\ObjectStorageServices
 * @uses \OCA\OpenRegister\Service\Object\Collaborators\ObjectWriteServices
 * @uses \OCA\OpenRegister\Service\Object\DraftCaller
 * @uses \OCA\OpenRegister\Service\Object\DraftSubject
 * @uses \OCA\OpenRegister\Service\Object\PermissionCheckOptions
 * @uses \OCA\OpenRegister\Service\Object\WriteConstraintCheck
 * @uses \OCA\OpenRegister\Db\Schema\ConfigurationEntryRules
 * @uses \OCA\OpenRegister\Db\Schema\ConfigurationValidator
 * @uses \OCA\OpenRegister\Db\Schema\ConfigurationValueRules
 * @uses   \OCA\OpenRegister\Service\Object\ObjectSaveChecks
 * @uses   \OCA\OpenRegister\Service\Object\SampleValidationErrors
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\ObjectValidationVerdict
 * @uses   \OCA\OpenRegister\Listener\CodedValueValidationListener
 * @uses   \OCA\OpenRegister\Listener\DependentValueListener
 * @uses   \OCA\OpenRegister\Listener\Support\EventSchemaLookup
 * @uses   \OCA\OpenRegister\Exception\CustomValidationException
 * @uses   \OCA\OpenRegister\Exception\CodedValueException
 */

namespace OCA\OpenRegister\Tests\Unit\Service;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Exception\CodedValueException;
use OCA\OpenRegister\Exception\CustomValidationException;
use OCA\OpenRegister\Listener\CodedValueValidationListener;
use OCA\OpenRegister\Listener\DependentValueListener;
use OCA\OpenRegister\Listener\Support\EventSchemaLookup;
use OCA\OpenRegister\Search\Db\ViewMapper;
use OCA\OpenRegister\Service\DateTimeNormalizer;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\Object\AuditHandler;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\Object\CascadingHandler;
use OCA\OpenRegister\Service\Object\DataManipulationHandler;
use OCA\OpenRegister\Service\Object\DeleteObject;
use OCA\OpenRegister\Service\Object\FacetHandler;
use OCA\OpenRegister\Service\Object\GetObject;
use OCA\OpenRegister\Service\Object\LockHandler;
use OCA\OpenRegister\Service\Object\MergeHandler;
use OCA\OpenRegister\Service\Object\MetadataHandler;
use OCA\OpenRegister\Service\Object\MigrationHandler;
use OCA\OpenRegister\Service\Object\ObjectSaveChecks;
use OCA\OpenRegister\Service\Object\PerformanceOptimizationHandler;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Object\QueryHandler;
use OCA\OpenRegister\Service\Object\RelationHandler;
use OCA\OpenRegister\Service\Object\RenderObject;
use OCA\OpenRegister\Service\Object\RevertHandler;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Object\SaveObjects;
use OCA\OpenRegister\Service\Object\SearchQueryHandler;
use OCA\OpenRegister\Service\Object\UtilityHandler;
use OCA\OpenRegister\Service\Object\ValidateObject;
use OCA\OpenRegister\Service\Object\ValidationHandler;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\ObjectSource\ObjectSourceRegistry;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\Rules\DependentValueTableInterface;
use OCA\OpenRegister\Service\SearchTrailService;
use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Service\Vocabulary\CodedValueGuardInterface;
use OCA\OpenRegister\Service\Vocabulary\ConceptShapeGuardInterface;
use OCA\OpenRegister\Tests\Unit\Service\Object\Support\ObjectServiceFactory;
use OCP\AppFramework\IAppContainer;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;
use Opis\JsonSchema\ValidationResult;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ObjectServiceValidateTest extends TestCase {

	private ObjectService $service;

	private SaveObject&MockObject $saveHandler;

	private MagicMapper&MockObject $objectMapper;

	private ValidateObject&MockObject $validator;

	private DependentValueTableInterface&MockObject $tables;

	private CodedValueGuardInterface&MockObject $guard;

	private Register $register;

	private Schema $schema;

	protected function setUp(): void {
		parent::setUp();

		$this->register = new Register();
		$this->register->setId(1);
		$this->schema = new Schema();
		$this->schema->setId(2);
		$this->schema->setSlug('aanvraag');
		$this->schema->setProperties(['gemeente' => ['type' => 'string'], 'wijk' => ['type' => 'string']]);

		$this->saveHandler = $this->createMock(SaveObject::class);
		$this->saveHandler->method('applyAlwaysDefaults')->willReturnArgument(1);
		$this->saveHandler->method('applyExpressionDefaults')->willReturnArgument(1);
		$this->saveHandler->method('seedLifecycleFieldOnCreate')->willReturnArgument(1);
		// Nothing is saved: not one call reaches the save handler's write.
		$this->saveHandler->expects($this->never())->method('saveObject');

		$this->objectMapper = $this->createMock(MagicMapper::class);
		$this->objectMapper->expects($this->never())->method('insert');
		$this->objectMapper->expects($this->never())->method('update');

		$this->validator = $this->createMock(ValidateObject::class);
		$this->validator->method('validateObject')->willReturn(new ValidationResult(null));

		$this->tables = $this->createMock(DependentValueTableInterface::class);
		$this->guard = $this->createMock(CodedValueGuardInterface::class);
		$checks = new ObjectSaveChecks(
			new CodedValueValidationListener($this->guard, $this->createMock(ConceptShapeGuardInterface::class), $this->createMock(SchemaMapperInterface::class), $this->createMock(LoggerInterface::class)),
			new DependentValueListener($this->tables, new EventSchemaLookup($this->createMock(\OCA\OpenRegister\Db\SchemaCatalogInterface::class), $this->createMock(LoggerInterface::class)))
		);
		$container = $this->createMock(IAppContainer::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($checks): mixed {
				if ($id === ObjectSaveChecks::class) {
					return $checks;
				}

				// No event dispatcher is ever asked for: a validate dispatches no event.
				$this->assertNotSame(IEventDispatcher::class, $id);
				return $this->createMock($id);
			}
		);

		$cascading = $this->createMock(CascadingHandler::class);
		$cascading->method('handlePreValidationCascading')->willReturnCallback(static fn (array $obj, mixed $schema, ?string $uuid, ?int $register): array => [$obj, $uuid]);

		$this->service = new ObjectService(...ObjectServiceFactory::args(
			$this->createMock(DataManipulationHandler::class),
			$this->createMock(DeleteObject::class),
			$this->createMock(GetObject::class),
			$this->createMock(PermissionHandler::class),
			$this->createMock(RenderObject::class),
			$this->saveHandler,
			$this->createMock(SaveObjects::class),
			$this->createMock(SearchQueryHandler::class),
			$this->validator,
			$this->createMock(LockHandler::class),
			$this->createMock(AuditHandler::class),
			$this->createMock(RelationHandler::class),
			$this->createMock(MergeHandler::class),
			$this->createMock(FacetHandler::class),
			$this->createMock(MetadataHandler::class),
			$this->createMock(PerformanceOptimizationHandler::class),
			$this->createMock(QueryHandler::class),
			$this->createMock(RevertHandler::class),
			$this->createMock(UtilityHandler::class),
			$this->createMock(ValidationHandler::class),
			$cascading,
			$this->createMock(MigrationHandler::class),
			$this->createMock(RegisterMapper::class),
			$this->createMock(SchemaMapper::class),
			$this->createMock(ViewMapper::class),
			$this->objectMapper,
			$this->createMock(FileService::class),
			$this->createMock(IUserSession::class),
			$this->createMock(SearchTrailService::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(IUserManager::class),
			$this->createMock(OrganisationService::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(CacheHandler::class),
			$this->createMock(SettingsService::class),
			$this->createMock(DateTimeNormalizer::class),
			$container,
			$this->createMock(ObjectSourceRegistry::class)
		));
	}

	public function testAValidSampleIsValid(): void {
		$this->tables->method('declaresAny')->willReturn(true);
		$this->tables->method('violations')->willReturn([]);

		$verdict = $this->service->validateObject(['gemeente' => 'Utrecht'], $this->register, $this->schema);

		$this->assertTrue($verdict->isValid());
		$this->assertSame(['valid' => true, 'errors' => []], $verdict->jsonSerialize());
	}

	public function testASampleBreakingADependentValueIsInvalidOnThatProperty(): void {
		$this->tables->method('declaresAny')->willReturn(true);
		$this->tables->method('violations')->willReturn([
			['property' => 'wijk', 'controlledBy' => 'gemeente', 'value' => 'Zuid', 'controllingValue' => 'Utrecht', 'message' => 'wijk Zuid is not allowed for gemeente Utrecht.'],
		]);

		$verdict = $this->service->validateObject(['gemeente' => 'Utrecht', 'wijk' => 'Zuid'], $this->register, $this->schema);

		$this->assertFalse($verdict->isValid());
		$this->assertSame(
			[['property' => 'wijk', 'message' => 'wijk Zuid is not allowed for gemeente Utrecht.', 'rule' => 'dependent-value']],
			$verdict->errors
		);
	}

	public function testARefusedCodedValueIsNamedWithItsRule(): void {
		$this->guard->method('enforce')->willThrowException(new CodedValueException('Atlantis is not in the list.', ['gemeente' => 'Atlantis is not in the list.']));

		$verdict = $this->service->validateObject(['gemeente' => 'Atlantis'], $this->register, $this->schema);

		$this->assertSame([['property' => 'gemeente', 'message' => 'Atlantis is not in the list.', 'rule' => 'coded-value']], $verdict->errors);
	}

	public function testAUniqueRefusalOfTheSchemaValidationIsNamed(): void {
		$this->schema->setHardValidation(true);
		$this->validator->method('validateObject')->willThrowException(
			new CustomValidationException('Fields are not unique: code (values: code=A)', ['code' => 'The identifying fields (code) are not unique.'])
		);

		$verdict = $this->service->validateObject(['code' => 'A'], $this->register, $this->schema);

		$this->assertSame([['property' => 'code', 'message' => 'The identifying fields (code) are not unique.', 'rule' => 'unique']], $verdict->errors);
	}

	public function testAnUpdateMergesTheSampleOntoTheStoredObject(): void {
		$stored = new ObjectEntity();
		$stored->setUuid('00000000-0000-0000-0000-000000000001');
		$stored->setObject(['gemeente' => 'Utrecht', 'wijk' => 'Noord']);
		$this->objectMapper->method('find')->willReturn($stored);
		$this->tables->method('declaresAny')->willReturn(true);
		$seen = null;
		$this->tables->method('violations')->willReturnCallback(
			static function (array $properties, $write) use (&$seen): array {
				$seen = $write;
				return [];
			}
		);

		$verdict = $this->service->validateObject(['wijk' => 'Zuid'], $this->register, $this->schema, '00000000-0000-0000-0000-000000000001');

		$this->assertTrue($verdict->isValid());
		// The sample names only wijk; the stored gemeente is merged in, as an update would carry it.
		$this->assertSame('Zuid', $seen->proposed('wijk'));
		$this->assertSame('Utrecht', $seen->proposed('gemeente'));
	}

	public function testTheServiceKeepsItsCurrentRegisterAndSchema(): void {
		$other = new Schema();
		$other->setId(9);
		$this->service->setSchema($other);

		$this->service->validateObject(['gemeente' => 'Utrecht'], $this->register, $this->schema);

		$this->assertSame($other, $this->service->getCurrentSchemaEntity());
	}
}
