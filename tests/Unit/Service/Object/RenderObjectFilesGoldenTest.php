<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Files\Db\FileMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Object\RenderObject;
use OCP\SystemTag\ISystemTag;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Pins the files RenderObject attaches to an object (renderFiles, batch tag
 * load) and the shape of one file (formatFileRecord, per-file tag load), and the
 * tag queries both run, against fixtures/render-object-files-golden.json.
 * Set UPDATE_GOLDEN=1 to rewrite the fixture.
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
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 */
class RenderObjectFilesGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/render-object-files-golden.json';

	private const FILES = [
		['fileid' => 11, 'path' => '/a/report.pdf', 'name' => 'report.pdf', 'mimetype' => 'application/pdf', 'size' => '2048', 'etag' => 'e1', 'published' => '2026-01-01', 'mtime' => 1700000000, 'accessUrl' => 'https://x/a', 'downloadUrl' => 'https://x/d'],
		['fileid' => '12', 'path' => '/a/photo', 'name' => 'photo', 'size' => 7],
		['fileid' => 13, 'path' => '/a/notes.tar.gz', 'name' => 'notes.tar.gz', 'size' => 0, 'etag' => null],
		['fileid' => 14, 'path' => '/a/x', 'name' => 'x', 'size' => 1],
	];

	/**
	 * Tag ids per file id, as ISystemTagObjectMapper returns them (int keys).
	 */
	private const TAG_IDS = [11 => ['1', '2', '9'], 12 => ['3', '1'], 13 => [], 14 => ['2', '3']];

	private const TAG_NAMES = ['1' => 'Public', '2' => 'object:abc', '3' => 'Draft'];

	public function testFilesMatchGolden(): void {
		$actual = [];
		foreach (['none' => [], 'all' => self::FILES, 'untagged' => [self::FILES[2]]] as $name => $files) {
			$calls = [];
			$render = $this->render(files: $files, calls: $calls);
			$object = new ObjectEntity();
			$method = new ReflectionMethod($render, 'renderFiles');
			$method->setAccessible(true);
			$method->invoke($render, $object);
			$actual['renderFiles'][$name] = ['files' => $object->getFiles(), 'calls' => $calls];
		}

		foreach (self::FILES as $file) {
			$calls = [];
			$render = $this->render(files: [], calls: $calls);
			$method = new ReflectionMethod($render, 'formatFileRecord');
			$method->setAccessible(true);
			$actual['formatFileRecord'][(string)$file['fileid']] = ['file' => $method->invoke($render, $file), 'calls' => $calls];
		}

		$calls = [];
		$render = $this->render(files: [], calls: $calls);
		$method = new ReflectionMethod($render, 'cacheFileTags');
		$method->setAccessible(true);
		$method->invoke($render, [11, '12', 13, 99]);
		$actual['cacheFileTags'] = ['cache' => (new ReflectionProperty(RenderObject::class, 'fileTagsCache'))->getValue($render), 'calls' => $calls];

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$this->assertSame(json_decode((string)file_get_contents(self::FIXTURE), true), json_decode((string)json_encode($actual), true));
	}//end testFilesMatchGolden()

	private function render(array $files, array &$calls): RenderObject {
		$fileMapper = $this->createMock(FileMapperInterface::class);
		$fileMapper->method('getFilesForObject')->willReturn($files);

		$tagMapper = $this->createMock(ISystemTagObjectMapper::class);
		$tagMapper->method('getTagIdsForObjects')->willReturnCallback(function ($objIds, $objectType) use (&$calls) {
			$calls[] = ['getTagIdsForObjects', $objIds, $objectType];
			$out = [];
			foreach ((array)$objIds as $id) {
				$out[$id] = self::TAG_IDS[(int)$id] ?? [];
			}

			return $out;
		});

		$tagManager = $this->createMock(ISystemTagManager::class);
		$tagManager->method('getTagsByIds')->willReturnCallback(function ($tagIds) use (&$calls) {
			$calls[] = ['getTagsByIds', array_values((array)$tagIds)];
			$tags = [];
			foreach ((array)$tagIds as $id) {
				if (isset(self::TAG_NAMES[$id]) === false) {
					continue;
				}

				$tag = $this->createMock(ISystemTag::class);
				$tag->method('getId')->willReturn((string)$id);
				$tag->method('getName')->willReturn(self::TAG_NAMES[$id]);
				$tags[$id] = $tag;
			}

			return $tags;
		});

		$render = (new ReflectionClass(RenderObject::class))->newInstanceWithoutConstructor();
		$stores = new \OCA\OpenRegister\Service\Object\RenderStores(
			mappers: new \OCA\OpenRegister\Service\Object\RenderMappers(
					catalog: new \OCA\OpenRegister\Db\SchemaCatalog(registers: $this->createMock(\OCA\OpenRegister\Db\RegisterMapperInterface::class), schemas: $this->createMock(\OCA\OpenRegister\Db\SchemaMapperInterface::class), tables: $this->createMock(\OCA\OpenRegister\Db\MagicMapperInterface::class)),
					objectCacheService: $this->createMock(\OCA\OpenRegister\Service\Object\CacheHandlerInterface::class)
			),
			files: new \OCA\OpenRegister\Service\Object\RenderFileSources(
				fileMapper: $fileMapper,
				fileService: $this->createMock(\OCA\OpenRegister\Service\FileReaderInterface::class),
				systemTagManager: $tagManager,
				systemTagMapper: $tagMapper
			)
		);
		$runtime = new \OCA\OpenRegister\Service\Object\RenderRuntime(logger: $this->createMock(\Psr\Log\LoggerInterface::class), extensions: new \OCA\OpenRegister\Service\Object\RenderReadExtensions(), container: null);
		foreach (['stores' => $stores, 'runtime' => $runtime, 'fileTagsCache' => []] as $property => $value) {
			(new ReflectionProperty(RenderObject::class, $property))->setValue($render, $value);
		}

		return $render;
	}//end render()
}//end class
