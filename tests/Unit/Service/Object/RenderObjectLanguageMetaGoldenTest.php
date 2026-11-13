<?php

/**
 * Golden tests for the `_meta.languageMeta` envelope RenderObject attaches on request.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 * @author   Conduction <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Service\Calculation\CalculationEvaluatorInterface;
use OCA\OpenRegister\Files\Db\FileMapperInterface;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Db\Translation;
use OCA\OpenRegister\Db\TranslationMapper;
use OCA\OpenRegister\Db\TranslationSlot;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\LanguageService;
use OCA\OpenRegister\Service\Object\CacheHandlerInterface;
use OCA\OpenRegister\Service\Object\LinkedEntityEnricher;
use OCA\OpenRegister\Service\Object\RenderObject;
use OCA\OpenRegister\Service\Object\RenderContext;
use OCA\OpenRegister\Service\Object\RenderPath;
use OCA\OpenRegister\Service\Object\RenderPreloads;
use OCA\OpenRegister\Service\Object\RenderRun;
use OCA\OpenRegister\Kernel\Contracts\RenderSelection;
use OCA\OpenRegister\Service\Object\SaveObject\ComputedFieldHandler;
use OCA\OpenRegister\Service\Object\TranslationHandler;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use OCA\OpenRegister\Service\TranslationStatusService;
use OCA\OpenRegister\Service\UrnService;
use OCP\IRequest;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Pins the served language, source language, isSource flag and sidecar status per translatable property.
 *
 * @covers \OCA\OpenRegister\Service\Object\RenderObject
 * @uses \OCA\OpenRegister\Service\Object\RecordAnnotations
 * @uses \OCA\OpenRegister\Service\Object\RecurrenceOccurrences
 * @uses \OCA\OpenRegister\Service\Object\VirtualCalculations
 * @uses \OCA\OpenRegister\Service\Object\InverseBatch
 * @uses \OCA\OpenRegister\Service\Object\InverseLookup
 * @uses \OCA\OpenRegister\Service\Object\ExtendTargetReads
 * @uses \OCA\OpenRegister\Service\Object\InverseRelationCache
 * @uses \OCA\OpenRegister\Service\Object\InverseRelationPreload
 * @uses \OCA\OpenRegister\Service\Object\InverseRelationRender
 * @uses \OCA\OpenRegister\Service\Object\InverseRenderSupport
 * @uses \OCA\OpenRegister\Service\Object\InverseSchemaLookups
 * @uses \OCA\OpenRegister\Service\Object\InverseObjectHooks
 * @uses \OCA\OpenRegister\Service\Object\InverseTarget
 * @uses   \OCA\OpenRegister\Service\Object\PropertySourceLanguage
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Db\Translation
 * @uses   \OCA\OpenRegister\Service\Object\LanguageMetaSubject
 * @uses   \OCA\OpenRegister\Db\Register\RegisterLanguages
 * @uses   \OCA\OpenRegister\Db\SchemaCatalog
 * @uses   \OCA\OpenRegister\Db\TranslationSlot
 * @uses   \OCA\OpenRegister\Kernel\Contracts\RenderSelection
 * @uses   \OCA\OpenRegister\Service\Object\RenderContext
 * @uses   \OCA\OpenRegister\Service\Object\RenderDerivations
 * @uses   \OCA\OpenRegister\Service\Object\RenderEnrichers
 * @uses   \OCA\OpenRegister\Service\Object\RenderFileSources
 * @uses   \OCA\OpenRegister\Service\Object\RenderMappers
 * @uses   \OCA\OpenRegister\Service\Object\RenderPath
 * @uses   \OCA\OpenRegister\Service\Object\RenderPreloads
 * @uses   \OCA\OpenRegister\Service\Object\RenderReadExtensions
 * @uses   \OCA\OpenRegister\Service\Object\RenderRun
 * @uses   \OCA\OpenRegister\Service\Object\RenderRuntime
 * @uses   \OCA\OpenRegister\Service\Object\RenderStores
 * @uses   \OCA\OpenRegister\Service\Object\RenderTranslations
 * @uses   \OCA\OpenRegister\Service\Object\TranslationScope
 * @uses   \OCA\OpenRegister\Db\MagicMapper\StatisticsScope
 * @uses   \OCA\OpenRegister\Kernel\Contracts\ListQuery
 * @uses \OCA\OpenRegister\Db\Register\RegisterLanguages
 * @uses \OCA\OpenRegister\Db\SchemaCatalog
 * @uses \OCA\OpenRegister\Db\TranslationSlot
 * @uses \OCA\OpenRegister\Kernel\Contracts\RenderSelection
 * @uses \OCA\OpenRegister\Service\Object\RenderContext
 * @uses \OCA\OpenRegister\Service\Object\RenderDerivations
 * @uses \OCA\OpenRegister\Service\Object\RenderEnrichers
 * @uses \OCA\OpenRegister\Service\Object\RenderFileSources
 * @uses \OCA\OpenRegister\Service\Object\RenderMappers
 * @uses \OCA\OpenRegister\Service\Object\RenderPath
 * @uses \OCA\OpenRegister\Service\Object\RenderPreloads
 * @uses \OCA\OpenRegister\Service\Object\RenderReadExtensions
 * @uses \OCA\OpenRegister\Service\Object\RenderRun
 * @uses \OCA\OpenRegister\Service\Object\RenderRuntime
 * @uses \OCA\OpenRegister\Service\Object\RenderStores
 * @uses \OCA\OpenRegister\Service\Object\RenderTranslations
 * @uses \OCA\OpenRegister\Service\Object\TranslationScope
 */
class RenderObjectLanguageMetaGoldenTest extends TestCase {

	private TranslationHandler&MockObject $translations;

	private TranslationMapper&MockObject $mapper;

	private RegisterMapperInterface&MockObject $registers;

	private LoggerInterface&MockObject $logger;

	private RenderObject $render;

	protected function setUp(): void {
		parent::setUp();
		$this->translations = $this->createMock(TranslationHandler::class);
		$this->translations->method('resolveTranslationsForRender')
			->willReturnCallback(static fn (array $objectData): array => $objectData + ['resolved' => true]);
		$this->mapper = $this->createMock(TranslationMapper::class);
		$this->registers = $this->createMock(RegisterMapperInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$languages = $this->createMock(LanguageService::class);
		$languages->method('getPreferredLanguage')->willReturn('en');
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnMap([['_translationMeta', null, 'true']]);

		$this->render = new RenderObject(
			stores: new \OCA\OpenRegister\Service\Object\RenderStores(
				mappers: new \OCA\OpenRegister\Service\Object\RenderMappers(
					catalog: new \OCA\OpenRegister\Db\SchemaCatalog(registers: $this->registers, schemas: $this->createMock(SchemaMapperInterface::class), tables: $this->createMock(MagicMapperInterface::class)),
					objectCacheService: $this->createMock(CacheHandlerInterface::class)
				),
				files: new \OCA\OpenRegister\Service\Object\RenderFileSources(
					fileMapper: $this->createMock(FileMapperInterface::class),
					fileService: $this->createMock(FileService::class),
					systemTagManager: $this->createMock(ISystemTagManager::class),
					systemTagMapper: $this->createMock(ISystemTagObjectMapper::class)
				)
			),
			enrichers: new \OCA\OpenRegister\Service\Object\RenderEnrichers(
				translations: new \OCA\OpenRegister\Service\Object\RenderTranslations(
					translationHandler: $this->translations,
					translationStatusService: $this->createMock(TranslationStatusService::class),
					translationMapper: $this->mapper,
					languageService: $languages
				),
				derivations: new \OCA\OpenRegister\Service\Object\RenderDerivations(
					computedFieldHandler: $this->createMock(ComputedFieldHandler::class),
					calculationEvaluator: $this->createMock(CalculationEvaluatorInterface::class),
					linkedEntityEnricher: $this->createMock(LinkedEntityEnricher::class),
					urnService: $this->createMock(UrnService::class)
				)
			),
			runtime: new \OCA\OpenRegister\Service\Object\RenderRuntime(
				logger: $this->logger,
				extensions: new \OCA\OpenRegister\Service\Object\RenderReadExtensions(),
				request: $request
			),
			propertyRbacHandler: $this->createMock(PropertyRbacHandler::class)
		);
	}//end setUp()

	/**
	 * Render translations of an entity through the private render step.
	 *
	 * @param ObjectEntity $entity     The entity.
	 * @param Schema       $schema     The schema.
	 * @param array        $objectData The object data.
	 *
	 * @return array The rendered data.
	 */
	private function renderTranslations(ObjectEntity $entity, Schema $schema, array $objectData): array {
		$method = new ReflectionMethod(RenderObject::class, 'resolveRenderTranslations');
		$run = new RenderRun($entity, new RenderContext(new RenderSelection([], [], [], []), new RenderPreloads(null, null, null), new RenderPath(0, []), true));
		$run->schema = $schema;
		$run->data = $objectData;
		return $method->invoke($this->render, $run);
	}//end renderTranslations()

	/**
	 * An entity in register 7 with the given uuid.
	 *
	 * @param string|null $uuid The uuid.
	 *
	 * @return ObjectEntity The entity.
	 */
	private function entity(?string $uuid): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setRegister('7');
		return $entity;
	}//end entity()

	/**
	 * A schema whose `body` property declares German as source language.
	 *
	 * @return Schema The schema.
	 */
	private function schema(): Schema {
		$schema = new Schema();
		$schema->setProperties(['body' => ['type' => 'string', 'sourceLanguage' => 'de'], 'title' => ['type' => 'string']]);
		return $schema;
	}//end schema()

	public function testNoTranslatablePropertiesLeavesTheResolvedDataAlone(): void {
		$this->translations->method('getTranslatableProperties')->willReturn([]);
		$this->registers->method('find')->willThrowException(new \Exception('gone'));
		$this->mapper->expects($this->never())->method('findOne');

		$out = $this->renderTranslations($this->entity('u-1'), $this->schema(), ['title' => 'x']);

		$this->assertSame(['title' => 'x', 'resolved' => true], $out);
	}//end testNoTranslatablePropertiesLeavesTheResolvedDataAlone()

	public function testEnvelopePerPropertyWithSidecarStatus(): void {
		$register = new Register();
		$register->setLanguages(['fr', 'en']);
		$this->registers->method('find')->willReturn($register);
		$this->translations->method('getTranslatableProperties')->willReturn(['title', 'body', 'summary', 'note', 'lead', 'own']);
		$approved = new Translation();
		$approved->setStatus('approved');
		$this->mapper->method('findOne')->willReturnCallback(
			static function (TranslationSlot $slot) use ($approved): ?Translation {
				[$uuid, $property, $language] = [$slot->objectUuid, $slot->property, $slot->language];
				TestCase::assertSame(['u-1', 'en'], [$uuid, $language]);
				return match ($property) {
					'body' => $approved,
					'summary' => null,
					'note' => throw new \RuntimeException('db down'),
					'title', 'lead' => new Translation(),
				};
			}
		);
		$data = [
			'_meta' => ['retention' => 90],
			'_translationMeta' => ['own' => ['sourceLanguage' => 'en'], 'lead' => ['sourceLanguage' => '']],
		];

		$out = $this->renderTranslations($this->entity('u-1'), $this->schema(), $data);

		$this->assertSame(90, $out['_meta']['retention']);
		$this->assertSame(
			[
				'title' => ['served' => 'en', 'sourceLanguage' => 'fr', 'isSource' => false, 'status' => 'draft'],
				'body' => ['served' => 'en', 'sourceLanguage' => 'de', 'isSource' => false, 'status' => 'approved'],
				'summary' => ['served' => 'en', 'sourceLanguage' => 'fr', 'isSource' => false, 'status' => 'missing'],
				'note' => ['served' => 'en', 'sourceLanguage' => 'fr', 'isSource' => false, 'status' => 'missing'],
				'lead' => ['served' => 'en', 'sourceLanguage' => 'fr', 'isSource' => false, 'status' => 'draft'],
				'own' => ['served' => 'en', 'sourceLanguage' => 'en', 'isSource' => true, 'status' => 'source'],
			],
			$out['_meta']['languageMeta']
		);
	}//end testEnvelopePerPropertyWithSidecarStatus()

	public function testWithoutRegisterOrUuidTheSourceIsDutchAndNoSidecarIsRead(): void {
		$this->registers->method('find')->willThrowException(new \Exception('gone'));
		$this->translations->method('getTranslatableProperties')->willReturn(['title']);
		$this->mapper->expects($this->never())->method('findOne');

		$out = $this->renderTranslations($this->entity(null), $this->schema(), ['_meta' => 'not-an-array']);

		$this->assertSame(
			['languageMeta' => ['title' => ['served' => 'en', 'sourceLanguage' => 'nl', 'isSource' => false, 'status' => 'source']]],
			$out['_meta']
		);
	}//end testWithoutRegisterOrUuidTheSourceIsDutchAndNoSidecarIsRead()

	public function testAFailureWhileAttachingIsLoggedAndTheResolvedDataReturned(): void {
		$this->registers->method('find')->willThrowException(new \Exception('gone'));
		$this->translations->method('getTranslatableProperties')->willThrowException(new \RuntimeException('boom'));
		$this->logger->expects($this->once())->method('debug')
			->with($this->stringContains('languageMeta attach failed for u-1: boom'));

		$out = $this->renderTranslations($this->entity('u-1'), $this->schema(), ['title' => 'x']);

		$this->assertSame(['title' => 'x', 'resolved' => true], $out);
	}//end testAFailureWhileAttachingIsLoggedAndTheResolvedDataReturned()
}//end class
