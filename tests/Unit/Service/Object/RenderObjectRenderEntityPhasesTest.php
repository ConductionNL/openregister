<?php

/**
 * The render phases of RenderObject::renderEntity() that other tests leave unpinned.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Service\Object\TranslationScope;
use OCA\OpenRegister\Files\Db\FileMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\TranslationMapper;
use OCA\OpenRegister\Service\Calculation\CalculationEvaluator;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\Hinge\LensResolver;
use OCA\OpenRegister\Service\LanguageService;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\Object\LinkedEntityEnricher;
use OCA\OpenRegister\Service\Object\RenderObject;
use OCA\OpenRegister\Service\Object\SaveObject\ComputedFieldHandler;
use OCA\OpenRegister\Service\Object\TranslationHandler;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use OCA\OpenRegister\Service\Registry\RegistrySubscriptionService;
use OCA\OpenRegister\Service\TranslationStatusService;
use OCA\OpenRegister\Service\UrnService;
use OCP\IRequest;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Characterises the linked-entity, calculation, lens, URN, translation and
 * registry phases of renderEntity(), and how often it looks a schema up.
 */
class RenderObjectRenderEntityPhasesTest extends TestCase {

	/** @var RegisterMapper&MockObject */
	private RegisterMapper&MockObject $registers;

	private const UUID = '5b0c1f0e-0000-4000-8000-000000000001';

	private SchemaMapper&MockObject $schemaMapper;

	private LoggerInterface&MockObject $logger;

	private LinkedEntityEnricher&MockObject $enricher;

	private CalculationEvaluator&MockObject $evaluator;

	private UrnService&MockObject $urnService;

	private TranslationStatusService&MockObject $translationStatus;

	private TranslationHandler&MockObject $translationHandler;

	/**
	 * Build the collaborators shared by every test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->schemaMapper      = $this->createMock(SchemaMapper::class);
		$this->registers         = $this->createMock(RegisterMapper::class);
		$this->logger            = $this->createMock(LoggerInterface::class);
		$this->enricher          = $this->createMock(LinkedEntityEnricher::class);
		$this->evaluator         = $this->createMock(CalculationEvaluator::class);
		$this->urnService        = $this->createMock(UrnService::class);
		$this->translationStatus = $this->createMock(TranslationStatusService::class);

		$this->translationHandler = $this->createMock(TranslationHandler::class);
		$this->translationHandler->method('resolveTranslationsForRender')->willReturnArgument(0);
	}//end setUp()

	/**
	 * Build a RenderObject over the shared collaborators.
	 *
	 * @param IRequest|null           $request   The current request.
	 * @param ContainerInterface|null $container The DI container.
	 * @param LensResolver|null       $lens      The lens resolver.
	 *
	 * @return RenderObject
	 */
	private function render(
		?IRequest $request = null,
		?ContainerInterface $container = null,
		?LensResolver $lens = null
	): RenderObject {
		return new RenderObject(
			stores: new \OCA\OpenRegister\Service\Object\RenderStores(
				mappers: new \OCA\OpenRegister\Service\Object\RenderMappers(
					catalog: new \OCA\OpenRegister\Db\SchemaCatalog(registers: $this->registers, schemas: $this->schemaMapper, tables: $this->createMock(MagicMapper::class)),
					objectCacheService: $this->createMock(CacheHandler::class)
				),
				files: new \OCA\OpenRegister\Service\Object\RenderFileSources(
					fileMapper: $this->createMock(FileMapper::class),
					fileService: $this->createMock(FileService::class),
					systemTagManager: $this->createMock(ISystemTagManager::class),
					systemTagMapper: $this->createMock(ISystemTagObjectMapper::class)
				)
			),
			enrichers: new \OCA\OpenRegister\Service\Object\RenderEnrichers(
				translations: new \OCA\OpenRegister\Service\Object\RenderTranslations(
					translationHandler: $this->translationHandler,
					translationStatusService: $this->translationStatus,
					translationMapper: $this->createMock(TranslationMapper::class),
					languageService: $this->createMock(LanguageService::class)
				),
				derivations: new \OCA\OpenRegister\Service\Object\RenderDerivations(
					computedFieldHandler: $this->createMock(ComputedFieldHandler::class),
					calculationEvaluator: $this->evaluator,
					linkedEntityEnricher: $this->enricher,
					urnService: $this->urnService
				)
			),
			runtime: new \OCA\OpenRegister\Service\Object\RenderRuntime(
				logger: $this->logger,
				extensions: new \OCA\OpenRegister\Service\Object\RenderReadExtensions(objectSourceRegistry: null, fieldEncryptionHandler: null, lensResolver: $lens),
				request: $request,
				container: $container
			),
			propertyRbacHandler: $this->createMock(PropertyRbacHandler::class)
		);
	}//end render()

	/**
	 * A schema the mapper returns for every lookup.
	 *
	 * @param array $configuration The schema configuration.
	 *
	 * @return Schema
	 */
	private function givenSchema(array $configuration = []): Schema {
		$schema = new Schema();
		$schema->setId(7);
		$schema->setSlug('case');
		$schema->setProperties(['title' => ['type' => 'string']]);
		$schema->setConfiguration($configuration);
		$this->schemaMapper->method('find')->willReturn($schema);

		return $schema;
	}//end givenSchema()

	/**
	 * An entity of schema 7 in register 3.
	 *
	 * @param array $data The object body.
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $data = ['title' => 'Z-1']): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid(self::UUID);
		$entity->setSchema('7');
		$entity->setRegister('3');
		$entity->setObject($data);

		return $entity;
	}//end entity()

	/**
	 * Record every debug message the logger receives.
	 *
	 * @return \ArrayObject<int, string>
	 */
	private function collectDebugMessages(): \ArrayObject {
		$messages = new \ArrayObject();
		$this->logger->method('debug')->willReturnCallback(
			static function (string $message) use ($messages): void {
				$messages[] = $message;
			}
		);

		return $messages;
	}//end collectDebugMessages()

	/**
	 * A linked-entity extend asks the enricher and writes its answer to the column.
	 *
	 * @return void
	 */
	public function testLinkedEntityExtendWritesTheEnrichedColumn(): void {
		$this->givenSchema();
		$this->enricher->expects($this->once())
			->method('enrich')
			->with($this->isType('array'), ['_mail' => 0])
			->willReturn(['_mail' => [['messageId' => 'm-1']]]);

		$rendered = $this->render()->renderEntity(entity: $this->entity(), _extend: ['_mail']);

		$this->assertSame([['messageId' => 'm-1']], $rendered->getMail());
	}//end testLinkedEntityExtendWritesTheEnrichedColumn()

	/**
	 * Without a linked-entity extend the enricher is never asked.
	 *
	 * @return void
	 */
	public function testNoLinkedEntityExtendSkipsTheEnricher(): void {
		$this->givenSchema();
		$this->enricher->expects($this->never())->method('enrich');

		$this->render()->renderEntity(entity: $this->entity(), _extend: ['title']);
	}//end testNoLinkedEntityExtendSkipsTheEnricher()

	/**
	 * A virtual calculation is evaluated only when `calculations` is extended.
	 *
	 * @return void
	 */
	public function testVirtualCalculationsRunOnlyWhenExtended(): void {
		$this->givenSchema(
			[
				'x-openregister-calculations' => [
					'total'  => ['expression' => 'a + b'],
					'stored' => ['expression' => 'c', 'materialise' => true],
				],
			]
		);
		$this->evaluator->method('evaluate')->willReturn(42);

		$plain    = $this->render()->renderEntity(entity: $this->entity())->getObject();
		$asString = $this->render()->renderEntity(entity: $this->entity(), _extend: 'calculations')->getObject();
		$asArray  = $this->render()->renderEntity(entity: $this->entity(), _extend: ['calculations'])->getObject();

		$this->assertArrayNotHasKey('total', $plain);
		$this->assertSame(42, $asString['total']);
		$this->assertSame(42, $asArray['total']);
		$this->assertArrayNotHasKey('stored', $asArray, 'A materialised calculation is not evaluated on read');
	}//end testVirtualCalculationsRunOnlyWhenExtended()

	/**
	 * An expression sees the object's `@self` values, and a date comes back as ISO 8601.
	 *
	 * @return void
	 */
	public function testACalculationSeesSelfAndAnswersDatesInIso(): void {
		$this->givenSchema(['x-openregister-calculations' => ['due' => ['expression' => 'x']]]);
		$seen = [];
		$this->evaluator->method('evaluate')->willReturnCallback(
			static function (array $payload, ?string $expression) use (&$seen): \DateTimeImmutable {
				$seen = [$payload, $expression];

				return new \DateTimeImmutable('2026-03-01T10:00:00+00:00');
			}
		);
		$entity = $this->entity();
		$entity->setOwner('alice');
		$entity->setCreated(new \DateTime('2026-01-02T03:04:05+00:00'));

		$data = $this->render()->renderEntity(entity: $entity, _extend: ['calculations'])->getObject();

		$this->assertSame('2026-03-01T10:00:00+00:00', $data['due']);
		$this->assertSame('x', $seen[1]);
		$this->assertSame('Z-1', $seen[0]['title']);
		$this->assertSame(
			[
				'id' => self::UUID,
				'uuid' => self::UUID,
				'register' => '3',
				'schema' => '7',
				'owner' => 'alice',
				'created' => '2026-01-02T03:04:05+00:00',
				'updated' => null,
			],
			$seen[0]['@self']
		);
	}//end testACalculationSeesSelfAndAnswersDatesInIso()

	/**
	 * A failed calculation is left out and the others still run; a malformed entry is skipped.
	 *
	 * @return void
	 */
	public function testAFailedCalculationOnlyLeavesItselfOut(): void {
		$this->givenSchema(
			['x-openregister-calculations' => ['bad' => ['expression' => 'boom'], 'broken' => 'nope', 'good' => ['expression' => 'ok']]]
		);
		$this->evaluator->method('evaluate')->willReturnCallback(
			static function (array $payload, ?string $expression): int {
				if ($expression === 'boom') {
					throw new \RuntimeException('no');
				}

				return 1;
			}
		);
		$messages = $this->collectDebugMessages();

		$data = $this->render()->renderEntity(entity: $this->entity(), _extend: ['calculations'])->getObject();

		$this->assertSame(1, $data['good']);
		$this->assertArrayNotHasKey('bad', $data);
		$this->assertArrayNotHasKey('broken', $data);
		$this->assertStringContainsString('Virtual calculation "bad" failed: no', implode("\n", (array)$messages));
	}//end testAFailedCalculationOnlyLeavesItselfOut()

	/**
	 * Calculations that are not a list do nothing.
	 *
	 * @return void
	 */
	public function testCalculationsThatAreNotAListDoNothing(): void {
		$this->givenSchema(['x-openregister-calculations' => 'nope']);
		$this->evaluator->expects($this->never())->method('evaluate');

		$data = $this->render()->renderEntity(entity: $this->entity(), _extend: ['calculations'])->getObject();

		$this->assertSame('Z-1', $data['title']);
	}//end testCalculationsThatAreNotAListDoNothing()

	/**
	 * One recurrence property answers `_occurrences`, with the requested number of dates.
	 *
	 * @return void
	 */
	public function testOneRecurrencePropertyAnswersOccurrences(): void {
		$this->givenSchema()->setProperties(['rule' => ['type' => 'recurrence'], 'empty' => ['type' => 'recurrence']]);
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => $key === 'recurrenceOccurrences' ? '3' : $default
		);

		$data = $this->render($request)->renderEntity(entity: $this->entity(['rule' => 'FREQ=DAILY', 'empty' => '']))->getObject();

		$this->assertCount(3, $data['_occurrences']);
		$this->assertSame('FREQ=DAILY', $data['rule']);
		$this->assertArrayNotHasKey('rule_occurrences', $data);
	}//end testOneRecurrencePropertyAnswersOccurrences()

	/**
	 * Several recurrence properties answer per property, with the default number of dates.
	 *
	 * @return void
	 */
	public function testSeveralRecurrencePropertiesAnswerPerProperty(): void {
		$this->givenSchema()->setProperties(['a' => ['type' => 'recurrence'], 'b' => ['type' => 'recurrence'], 'c' => ['type' => 'string']]);

		$data = $this->render()->renderEntity(entity: $this->entity(['a' => 'FREQ=DAILY', 'b' => 'FREQ=WEEKLY', 'c' => 'FREQ=DAILY']))->getObject();

		$this->assertCount(5, $data['a_occurrences']);
		$this->assertCount(5, $data['b_occurrences']);
		$this->assertArrayNotHasKey('_occurrences', $data);
		$this->assertArrayNotHasKey('c_occurrences', $data);
	}//end testSeveralRecurrencePropertiesAnswerPerProperty()

	/**
	 * A count that is no number falls back to the default.
	 *
	 * @return void
	 */
	public function testACountThatIsNoNumberFallsBackToTheDefault(): void {
		$this->givenSchema()->setProperties(['rule' => ['type' => 'recurrence']]);
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => $key === 'recurrenceOccurrences' ? '3 days' : $default
		);

		$data = $this->render($request)->renderEntity(entity: $this->entity(['rule' => 'FREQ=DAILY']))->getObject();

		$this->assertCount(5, $data['_occurrences']);
	}//end testACountThatIsNoNumberFallsBackToTheDefault()

	/**
	 * Row translations are told the row's schema and register, for both row shapes.
	 *
	 * @return void
	 */
	public function testRowTranslationsAreToldTheRowsRegister(): void {
		$schema = $this->givenSchema();
		$register = new Register();
		$this->registers->method('find')->willReturn($register);
		$seen = [];
		$this->translationHandler = $this->createMock(TranslationHandler::class);
		$this->translationHandler->method('resolveTranslationsForRender')->willReturnCallback(
			static function (array $objectData, TranslationScope $scope) use (&$seen): array {
				$seen[] = [$scope->schema, $scope->register];
				$objectData['translated'] = true;

				return $objectData;
			}
		);
		$rows = [$this->entity(), ['@self' => ['schema' => 7, 'register' => 3]]];

		$this->render()->resolveTranslationsForRows(rows: $rows);

		$this->assertTrue($rows[0]->getObject()['translated']);
		$this->assertTrue($rows[1]['translated']);
		$this->assertSame([[$schema, $register], [$schema, $register]], $seen);
	}//end testRowTranslationsAreToldTheRowsRegister()

	/**
	 * A row without a schema, or with one that cannot be found, stays as it is.
	 *
	 * @return void
	 */
	public function testARowWithoutAKnownSchemaStaysAsItIs(): void {
		$this->schemaMapper->method('find')->willThrowException(new \RuntimeException('gone'));
		$this->translationHandler = $this->createMock(TranslationHandler::class);
		$this->translationHandler->expects($this->never())->method('resolveTranslationsForRender');
		$noSchema = new ObjectEntity();
		$noSchema->setObject(['title' => 'Z-1']);
		$rows = [$noSchema, $this->entity(), ['@self' => ['schema' => 7]], ['@self' => ['schema' => ['x']]]];

		$this->render()->resolveTranslationsForRows(rows: $rows);

		$this->assertSame('Z-1', $rows[0]->getObject()['title']);
		$this->assertSame(['@self' => ['schema' => 7]], $rows[2]);
	}//end testARowWithoutAKnownSchemaStaysAsItIs()

	/**
	 * A declared link that can be filled lands on `@self.externalLinks`, next to what `@self` holds.
	 *
	 * @return void
	 */
	public function testAFilledLinkLandsOnSelf(): void {
		$this->givenSchema(
			['x-openregister-external-links' => [['title' => 'Viewer', 'url' => 'https://example.org/{title}'], ['title' => 'Other', 'url' => 'https://example.org/{gone}'], 'nope']]
		);

		$data = $this->render()->renderEntity(entity: $this->entity(['title' => 'Z-1', '@self' => ['kept' => 1]]))->getObject();

		$this->assertSame([['title' => 'Viewer', 'url' => 'https://example.org/Z-1']], $data['@self']['externalLinks']);
		$this->assertSame(1, $data['@self']['kept']);
	}//end testAFilledLinkLandsOnSelf()

	/**
	 * No declared link, or none that can be filled, leaves no key behind.
	 *
	 * @return void
	 */
	public function testNoLinkLeavesNoKey(): void {
		$this->givenSchema(['x-openregister-external-links' => [['title' => 'Other', 'url' => 'https://example.org/{gone}']]]);

		$data = $this->render()->renderEntity(entity: $this->entity())->getObject();

		$this->assertArrayNotHasKey('externalLinks', ($data['@self'] ?? []));
	}//end testNoLinkLeavesNoKey()

	/**
	 * The annotation block joins what the row's retention already held.
	 *
	 * @return void
	 */
	public function testTheRetentionBlockJoinsTheRowsRetention(): void {
		$this->givenSchema(['x-openregister-archival' => ['retention' => ['default' => 'P1Y']]]);
		$entity = $this->entity();
		$entity->setCreated(new \DateTime('2026-01-02T00:00:00+00:00'));
		$entity->setRetention(['kept' => 1]);

		$retention = $this->render()->renderEntity(entity: $entity)->getRetention();

		$this->assertSame(1, $retention['kept']);
		$this->assertSame('P1Y', $retention['annotation']['effectiveRetention']);
		$this->assertStringStartsWith('2027-01-02', (string)$retention['annotation']['expiresAt']);
	}//end testTheRetentionBlockJoinsTheRowsRetention()

	/**
	 * A row without a creation date gets no annotation block.
	 *
	 * @return void
	 */
	public function testARowWithoutACreationDateGetsNoRetentionBlock(): void {
		$this->givenSchema(['x-openregister-archival' => ['retention' => ['default' => 'P1Y']]]);
		$entity = $this->entity();
		$entity->setCreated(null);
		$messages = $this->collectDebugMessages();

		$retention = ($this->render()->renderEntity(entity: $entity)->getRetention() ?? []);

		$this->assertArrayNotHasKey('annotation', $retention);
		$this->assertStringNotContainsString('archival retention', implode("\n", (array)$messages));
	}//end testARowWithoutACreationDateGetsNoRetentionBlock()

	/**
	 * A malformed annotation is a debug line, not a failed render.
	 *
	 * @return void
	 */
	public function testAMalformedAnnotationIsOnlyLogged(): void {
		$this->givenSchema(['x-openregister-archival' => ['nope' => true]]);
		$entity = $this->entity();
		$entity->setCreated(new \DateTime('2026-01-02T00:00:00+00:00'));
		$messages = $this->collectDebugMessages();

		$data = $this->render()->renderEntity(entity: $entity)->getObject();

		$this->assertSame('Z-1', $data['title']);
		$this->assertStringContainsString(
			'[RenderObject] archival retention compute failed for ' . self::UUID . ': ',
			implode("\n", (array)$messages)
		);
	}//end testAMalformedAnnotationIsOnlyLogged()

	/**
	 * The archival decision of a row with a retention block lands on the entity.
	 *
	 * @return void
	 */
	public function testTheArchivalDecisionLandsOnTheEntity(): void {
		$this->givenSchema(['x-openregister-archival' => ['retention' => ['default' => 'P1Y']]]);
		$entity = $this->entity();
		$entity->setCreated(new \DateTime('2026-01-02T00:00:00+00:00'));

		$retention = $this->render()->renderEntity(entity: $entity)->getArchivalRetention();
		$this->assertIsArray($retention);
		// The schema's default of one year runs from the creation date.
		$this->assertSame('P1Y', $retention['retentionPeriod']);
		$this->assertSame('2027-01-02T00:00:00+00:00', $retention['disposalDate']);
	}//end testTheArchivalDecisionLandsOnTheEntity()

	/**
	 * The lens is applied on every render and receives the rbac flag.
	 *
	 * @return void
	 */
	public function testTheLensReceivesTheRbacFlag(): void {
		$schema = $this->givenSchema();
		$lens   = $this->createMock(LensResolver::class);
		$seen = [];
		$lens->expects($this->once())
			->method('apply')
			->willReturnCallback(
				static function (?Schema $given, array $data, bool $rbac) use (&$seen): array {
					$seen = [$given, $rbac];
					return $data + ['lensed' => true];
				}
			);

		$rendered = $this->render(lens: $lens)->renderEntity(entity: $this->entity(), _rbac: false);

		$this->assertSame([$schema, false], $seen);
		$this->assertTrue($rendered->getObject()['lensed']);
	}//end testTheLensReceivesTheRbacFlag()

	/**
	 * The URN comes from UrnService, and a failure there is logged, not thrown.
	 *
	 * @return void
	 */
	public function testUrnIsSetAndAFailureIsOnlyLogged(): void {
		$this->givenSchema();
		$this->urnService->method('buildForObject')
			->willReturnOnConsecutiveCalls('urn:nl-or:x:r:s:'.self::UUID, $this->throwException(new RuntimeException('boom')));
		$messages = $this->collectDebugMessages();

		$render = $this->render();
		$this->assertSame('urn:nl-or:x:r:s:'.self::UUID, $render->renderEntity(entity: $this->entity())->getUrn());
		$this->assertNull($render->renderEntity(entity: $this->entity())->getUrn());
		$this->assertContains('[RenderObject] URN derivation failed for object '.self::UUID.': boom', $messages->getArrayCopy());
	}//end testUrnIsSetAndAFailureIsOnlyLogged()

	/**
	 * Translation completeness is attached when non-empty; a failure is only logged.
	 *
	 * @return void
	 */
	public function testTranslationCompletenessIsAttachedAndAFailureIsOnlyLogged(): void {
		$this->givenSchema();
		$this->translationStatus->method('completenessForObject')
			->willReturnOnConsecutiveCalls(['nl' => 1.0], [], $this->throwException(new RuntimeException('boom')));
		$messages = $this->collectDebugMessages();

		$render = $this->render();
		$this->assertSame(['nl' => 1.0], $render->renderEntity(entity: $this->entity())->getTranslationCompleteness());
		$this->assertNull($render->renderEntity(entity: $this->entity())->getTranslationCompleteness());
		$this->assertNull($render->renderEntity(entity: $this->entity())->getTranslationCompleteness());
		$this->assertContains(
			'[RenderObject] translation completeness lookup failed for '.self::UUID.': boom',
			$messages->getArrayCopy()
		);
	}//end testTranslationCompletenessIsAttachedAndAFailureIsOnlyLogged()

	/**
	 * Registry state is read only for an annotated schema; a failure is only logged.
	 *
	 * @return void
	 */
	public function testRegistryStateIsReadForAnAnnotatedSchema(): void {
		$this->givenSchema();
		$registry = $this->createMock(RegistrySubscriptionService::class);
		$registry->method('annotationFor')->willReturnOnConsecutiveCalls(['source' => 'x'], null, ['source' => 'x']);
		$registry->method('stateFor')
			->willReturnOnConsecutiveCalls(['status' => 'subscribed'], $this->throwException(new RuntimeException('boom')));
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with(RegistrySubscriptionService::class)->willReturn($registry);
		$messages = $this->collectDebugMessages();

		$render = $this->render(container: $container);
		$this->assertSame(['status' => 'subscribed'], $render->renderEntity(entity: $this->entity())->getRegistryState());
		$this->assertNull($render->renderEntity(entity: $this->entity())->getRegistryState());
		$this->assertNull($render->renderEntity(entity: $this->entity())->getRegistryState());
		$this->assertContains(
			'[RenderObject] registry subscription state lookup failed for '.self::UUID.': boom',
			$messages->getArrayCopy()
		);
	}//end testRegistryStateIsReadForAnAnnotatedSchema()

	/**
	 * A failing language-meta attach is logged and leaves the object rendered.
	 *
	 * @return void
	 */
	public function testALanguageMetaFailureIsOnlyLogged(): void {
		$this->givenSchema();
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static function (string $key, $default = null) {
				if ($key === '_translationMeta') {
					return 'true';
				}

				return $default;
			}
		);
		$this->translationHandler->method('getTranslatableProperties')->willThrowException(new RuntimeException('boom'));
		$messages = $this->collectDebugMessages();

		$rendered = $this->render(request: $request)->renderEntity(entity: $this->entity());

		$this->assertSame('Z-1', $rendered->getObject()['title']);
		$languageMeta = array_values(
			array_filter(
				$messages->getArrayCopy(),
				static fn (string $message): bool => str_contains($message, 'languageMeta')
			)
		);
		$this->assertCount(1, $languageMeta);
		$this->assertSame('[RenderObject] languageMeta attach failed for '.self::UUID.': boom', $languageMeta[0]);
	}//end testALanguageMetaFailureIsOnlyLogged()

	/**
	 * An unknown schema is looked up the same number of times on every render.
	 *
	 * getSchema() caches only hits, so each lookup of a missing schema is a query.
	 *
	 * @return void
	 */
	public function testAMissingSchemaIsLookedUpAFixedNumberOfTimes(): void {
		$this->schemaMapper->expects($this->exactly(8))
			->method('find')
			->willThrowException(new \Exception('not found'));

		$this->render()->renderEntity(entity: $this->entity(), _extend: ['title']);
	}//end testAMissingSchemaIsLookedUpAFixedNumberOfTimes()

	/**
	 * A filter mismatch empties the object before anything else renders.
	 *
	 * @return void
	 */
	public function testAFilterMismatchStopsTheRender(): void {
		$this->givenSchema();
		$this->urnService->expects($this->never())->method('buildForObject');

		$rendered = $this->render()->renderEntity(entity: $this->entity(), filter: ['title' => 'other']);

		$this->assertSame(['id' => self::UUID], $rendered->getObject());
	}//end testAFilterMismatchStopsTheRender()

	/**
	 * Requested fields keep `id` and drop everything else that is not named.
	 *
	 * @return void
	 */
	public function testFieldSelectionKeepsIdAndNamedFields(): void {
		$this->givenSchema();

		$rendered = $this->render()->renderEntity(
			entity: $this->entity(['id' => self::UUID, 'title' => 'Z-1', 'other' => 'x', 'empty' => null]),
			fields: ['title', 'empty']
		);

		$this->assertSame(['id' => self::UUID, 'title' => 'Z-1'], $rendered->getObject());
	}//end testFieldSelectionKeepsIdAndNamedFields()
}//end class
