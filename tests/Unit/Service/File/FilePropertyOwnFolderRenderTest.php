<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\File
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/file-actions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\File;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\TranslationMapper;
use OCA\OpenRegister\Files\Db\FileMapper;
use OCA\OpenRegister\Service\Calculation\CalculationEvaluator;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\LanguageService;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\Object\LinkedEntityEnricher;
use OCA\OpenRegister\Service\Object\RenderObject;
use OCA\OpenRegister\Service\Object\SaveObject\ComputedFieldHandler;
use OCA\OpenRegister\Service\Object\TranslationHandler;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use OCA\OpenRegister\Service\TranslationStatusService;
use OCA\OpenRegister\Service\UrnService;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;

/**
 * A file property renders only a file that lives in the object's own folder.
 *
 * @spec openspec/specs/file-actions/spec.md
 */
class FilePropertyOwnFolderRenderTest extends TestCase {

	private const OWN = 43;

	private const FOREIGN = 12345;

	private const UUID = 'c0ffee00-0000-4000-8000-000000000002';

	/**
	 * Every file id read through the instance-wide paths.
	 *
	 * @var list<int>
	 */
	private array $lookups = [];

	/**
	 * A FileMapper record.
	 *
	 * @param int $fileId The file id.
	 *
	 * @return array<string, mixed>
	 */
	private function record(int $fileId): array {
		return [
			'fileid' => (string)$fileId,
			'path' => '/files/' . $fileId . '.pdf',
			'name' => $fileId . '.pdf',
			'mimetype' => 'application/pdf',
			'size' => 4,
			'etag' => 'e',
			'mtime' => 1,
			'accessUrl' => 'https://example.com/s/token-' . $fileId,
			'downloadUrl' => 'https://example.com/s/token-' . $fileId . '/download',
			'published' => null,
		];
	}//end record()

	/**
	 * A renderer whose object folder holds only the OWN file.
	 *
	 * @param array<string, mixed> $properties The schema properties.
	 *
	 * @return RenderObject
	 */
	private function renderer(array $properties): RenderObject {
		$fileMapper = $this->createMock(FileMapper::class);
		$fileMapper->method('getFile')->willReturnCallback(
			function (int $id): array {
				$this->lookups[] = $id;
				return $this->record($id);
			}
		);
		$fileMapper->method('getFileIdsForObjects')->willReturnCallback(
			static fn (array $uuids): array => array_fill_keys($uuids, [self::OWN])
		);

		$file = $this->createMock(\OCP\Files\File::class);
		$file->method('getSize')->willReturn(4);
		$file->method('getContent')->willReturn('body');
		$file->method('getMimeType')->willReturn('application/pdf');
		$fileService = $this->createMock(FileService::class);
		$fileService->method('getFileById')->willReturnCallback(
			function (int $id) use ($file): \OCP\Files\File {
				$this->lookups[] = $id;
				return $file;
			}
		);

		$schema = new Schema();
		$schema->setId(7);
		$schema->setProperties($properties);
		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('find')->willReturn($schema);

		return new RenderObject(
			stores: new \OCA\OpenRegister\Service\Object\RenderStores(
				mappers: new \OCA\OpenRegister\Service\Object\RenderMappers(
					catalog: new \OCA\OpenRegister\Db\SchemaCatalog(registers: $this->createMock(RegisterMapper::class), schemas: $schemas, tables: $this->createMock(MagicMapper::class)),
					objectCacheService: $this->createMock(CacheHandler::class)
				),
				files: new \OCA\OpenRegister\Service\Object\RenderFileSources(
					fileMapper: $fileMapper,
					fileService: $fileService,
					systemTagManager: $this->createMock(ISystemTagManager::class),
					systemTagMapper: $this->createMock(ISystemTagObjectMapper::class)
				)
			),
			enrichers: new \OCA\OpenRegister\Service\Object\RenderEnrichers(
				translations: new \OCA\OpenRegister\Service\Object\RenderTranslations(
					translationHandler: $this->createMock(TranslationHandler::class),
					translationStatusService: $this->createMock(TranslationStatusService::class),
					translationMapper: $this->createMock(TranslationMapper::class),
					languageService: $this->createMock(LanguageService::class)
				),
				derivations: new \OCA\OpenRegister\Service\Object\RenderDerivations(
					computedFieldHandler: $this->createMock(ComputedFieldHandler::class),
					calculationEvaluator: $this->createMock(CalculationEvaluator::class),
					linkedEntityEnricher: $this->createMock(LinkedEntityEnricher::class),
					urnService: $this->createMock(UrnService::class)
				)
			),
			runtime: new \OCA\OpenRegister\Service\Object\RenderRuntime(
				logger: new NullLogger(),
				extensions: new \OCA\OpenRegister\Service\Object\RenderReadExtensions(),
				container: null
			),
			propertyRbacHandler: $this->createMock(PropertyRbacHandler::class)
		);
	}//end renderer()

	/**
	 * The object data after its file properties are rendered.
	 *
	 * @param RenderObject         $renderer The renderer.
	 * @param array<string, mixed> $data     The stored object data.
	 *
	 * @return array<string, mixed>
	 */
	private function rendered(RenderObject $renderer, array $data): array {
		$entity = new ObjectEntity();
		$entity->setUuid(self::UUID);
		$entity->setSchema('7');
		$entity->setObject($data);

		$method = new ReflectionMethod($renderer, 'hydrateFilesOf');
		$method->setAccessible(true);
		$method->invoke($renderer, $entity);

		return $entity->getObject();
	}//end rendered()

	public function testASingleFilePropertyRendersTheObjectsOwnFile(): void {
		$data = $this->rendered($this->renderer(['doc' => ['type' => 'file']]), ['doc' => self::OWN]);

		$this->assertSame((string)self::OWN, $data['doc']['id']);
	}//end testASingleFilePropertyRendersTheObjectsOwnFile()

	public function testASingleFilePropertyDoesNotDescribeAFileOutsideTheObjectFolder(): void {
		$data = $this->rendered($this->renderer(['doc' => ['type' => 'file']]), ['doc' => self::FOREIGN]);

		$this->assertNull($data['doc']);
		$this->assertNotContains(self::FOREIGN, $this->lookups);
	}//end testASingleFilePropertyDoesNotDescribeAFileOutsideTheObjectFolder()

	public function testANumericStringOutsideTheObjectFolderIsNotDescribedEither(): void {
		$data = $this->rendered($this->renderer(['doc' => ['type' => 'file']]), ['doc' => (string)self::FOREIGN]);

		$this->assertNull($data['doc']);
		$this->assertNotContains(self::FOREIGN, $this->lookups);
	}//end testANumericStringOutsideTheObjectFolderIsNotDescribedEither()

	public function testABase64PropertyDoesNotReadAFileOutsideTheObjectFolder(): void {
		$renderer = $this->renderer(['doc' => ['type' => 'file', 'format' => 'base64']]);

		$this->assertNull($this->rendered($renderer, ['doc' => self::FOREIGN])['doc']);
		$this->assertSame(
			'data:application/pdf;base64,' . base64_encode('body'),
			$this->rendered($renderer, ['doc' => self::OWN])['doc']
		);
		$this->assertSame([self::OWN], $this->lookups);
	}//end testABase64PropertyDoesNotReadAFileOutsideTheObjectFolder()

	public function testAnArrayPropertyKeepsOnlyTheObjectsOwnFiles(): void {
		$renderer = $this->renderer(['docs' => ['type' => 'array', 'items' => ['type' => 'file']]]);

		$data = $this->rendered($renderer, ['docs' => [self::FOREIGN, self::OWN]]);

		$this->assertSame([(string)self::OWN], array_column($data['docs'], 'id'));
		$this->assertNotContains(self::FOREIGN, $this->lookups);
	}//end testAnArrayPropertyKeepsOnlyTheObjectsOwnFiles()

	public function testAnObjectWithoutAUuidRendersNoFile(): void {
		$renderer = $this->renderer(['doc' => ['type' => 'file']]);
		$entity = new ObjectEntity();
		$entity->setSchema('7');
		$entity->setObject(['doc' => self::OWN]);

		$method = new ReflectionMethod($renderer, 'hydrateFilesOf');
		$method->setAccessible(true);
		$method->invoke($renderer, $entity);

		$this->assertNull($entity->getObject()['doc']);
	}//end testAnObjectWithoutAUuidRendersNoFile()
}//end class
