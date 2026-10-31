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

use OCA\OpenRegister\Db\FileMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
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
			$this->createMock(FileMapper::class),
			$this->createMock(MagicMapper::class),
			$this->createMock(RegisterMapper::class),
			$this->schemaMapper,
			$this->createMock(ISystemTagManager::class),
			$this->createMock(ISystemTagObjectMapper::class),
			$this->createMock(CacheHandler::class),
			$this->createMock(CacheHandler::class),
			$this->createMock(PropertyRbacHandler::class),
			$this->logger,
			$this->createMock(FileService::class),
			$this->createMock(ComputedFieldHandler::class),
			$this->translationHandler,
			$this->enricher,
			$this->evaluator,
			$this->urnService,
			$this->translationStatus,
			$this->createMock(TranslationMapper::class),
			$this->createMock(LanguageService::class),
			$request,
			null,
			null,
			$container,
			$lens
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
