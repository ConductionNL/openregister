<?php

/**
 * Golden master for FilePublishingHandler::createObjectFilesZip().
 *
 * Every case zips an object's files and records the result (the temp path and
 * a generated name normalised), the archive's entries, the warnings and
 * errors logged, and any exception. The recording lives in
 * `fixtures/file-publishing-zip-golden.json`; set `OR_GOLDEN_WRITE=1` to
 * rewrite it.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\File
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/file-actions/spec.md
 */

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\File;

use Exception;
use OCA\OpenRegister\Files\Db\FileMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\File\FilePublishingHandler;
use OCA\OpenRegister\Files\Service\File\FileRestrictionGuard;
use OCA\OpenRegister\Service\FileService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\IServerContainer;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use ZipArchive;

/**
 * @covers \OCA\OpenRegister\Service\File\FilePublishingHandler
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 */
class FilePublishingHandlerZipGoldenTest extends TestCase {
	use PassThroughDigestsTrait;

	private const FIXTURE = __DIR__ . '/fixtures/file-publishing-zip-golden.json';

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function cases(): array {
		$out = [];
		foreach (array_keys(self::scenarios()) as $name) {
			$out[$name] = [$name];
		}

		return $out;
	}//end cases()

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private static function scenarios(): array {
		$mixed = ['a.txt' => 'alpha', 'folder' => null, 'owner.txt' => 'throw-owner', 'content.txt' => 'throw-content', 'b.txt' => 'beta'];
		return [
			'entity-named' => ['files' => ['a.txt' => 'alpha', 'b.txt' => 'beta'], 'zipName' => 'golden-named-b4b.zip'],
			'entity-name-without-extension' => ['files' => ['a.txt' => 'alpha'], 'zipName' => 'golden-noext-b4b'],
			'entity-name-other-extension' => ['files' => ['a.txt' => 'alpha'], 'zipName' => 'golden-other-b4b.tar'],
			'entity-generated-name' => ['files' => ['a.txt' => 'alpha']],
			'entity-generated-name-no-uuid' => ['files' => ['a.txt' => 'alpha'], 'uuid' => null],
			'string-id' => ['files' => ['a.txt' => 'alpha'], 'byId' => true, 'zipName' => 'golden-byid-b4b.zip'],
			'string-id-missing' => ['files' => ['a.txt' => 'alpha'], 'byId' => 'missing'],
			'no-files' => ['files' => []],
			'mixed-nodes' => ['files' => $mixed, 'zipName' => 'golden-mixed-b4b.zip'],
			'only-skipped' => ['files' => ['folder' => null, 'owner.txt' => 'throw-owner'], 'zipName' => 'golden-skipped-b4b.zip'],
		];
	}//end scenarios()

	/**
	 * @dataProvider cases
	 */
	public function testZipMatchesGolden(string $name): void {
		$recorded = $this->record(scenario: self::scenarios()[$name]);

		$all = [];
		if (file_exists(self::FIXTURE) === true) {
			$all = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		if (getenv('OR_GOLDEN_WRITE') === '1') {
			$all[$name] = $recorded;
			ksort($all);
			file_put_contents(self::FIXTURE, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertArrayHasKey($name, $all);
		$this->assertSame($all[$name], $recorded);
	}//end testZipMatchesGolden()

	/**
	 * @param array<string, mixed> $scenario
	 *
	 * @return array<string, mixed>
	 */
	private function record(array $scenario): array {
		$trace = [];
		$logger = new class($trace) extends AbstractLogger {
			/**
			 * @param array<int, mixed> $trace
			 */
			public function __construct(private array &$trace) {
			}

			public function log($level, string|\Stringable $message, array $context = []): void {
				$this->trace[] = $level . ': ' . $message;
			}
		};

		$object = new ObjectEntity();
		$reflection = new \ReflectionProperty($object, 'id');
		$reflection->setValue($object, 17);
		$object->setUuid(array_key_exists('uuid', $scenario) === true ? $scenario['uuid'] : 'obj-uuid');

		$mapper = $this->createMock(MagicMapper::class);
		if (($scenario['byId'] ?? false) === 'missing') {
			$mapper->method('find')->willThrowException(new Exception('no such row'));
		} else {
			$mapper->method('find')->willReturnCallback(
				function (string $id) use (&$trace, $object): ObjectEntity {
					$trace[] = 'find: ' . $id;
					return $object;
				}
			);
		}

		$nodes = [];
		foreach ($scenario['files'] as $fileName => $content) {
			$nodes[] = $this->node(name: $fileName, content: $content);
		}

		$fileService = $this->createMock(FileService::class);
		$fileService->method('getFiles')->willReturn($nodes);
		$fileService->method('checkOwnership')->willReturnCallback(
			static function ($file): bool {
				if ($file->getName() === 'owner.txt') {
					throw new Exception('not the owner');
				}

				return true;
			}
		);

		$restrictions = $this->createMock(FileRestrictionGuard::class);
		$restrictions->method('readableNodes')->willReturnArgument(0);
		$container = $this->createMock(IServerContainer::class);
		$container->method('get')->willReturn($restrictions);

		$handler = new FilePublishingHandler(
			objectEntityMapper: $mapper,
			fileMapper: $this->createMock(FileMapper::class),
			logger: $logger,
			container: $container,
			publishedDigests: $this->passThroughDigests()
		);
		$handler->setFileService($fileService);

		$target = $object;
		if (isset($scenario['byId']) === true) {
			$target = 'obj-uuid';
		}

		try {
			$result = $handler->createObjectFilesZip($target, $scenario['zipName'] ?? null);
		} catch (Exception $e) {
			return ['exception' => $e->getMessage(), 'trace' => $trace];
		}

		$entries = [];
		$zip = new ZipArchive();
		$zip->open($result['path']);
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$entries[$zip->getNameIndex($i)] = $zip->getFromIndex($i);
		}

		$zip->close();
		unlink($result['path']);

		$this->assertSame(sys_get_temp_dir() . DIRECTORY_SEPARATOR . $result['filename'], $result['path']);
		$this->assertGreaterThan(0, $result['size']);
		$result['path'] = '~';
		$result['size'] = '~';
		$result['filename'] = (string)preg_replace('/\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}/', 'DATE', $result['filename']);
		$trace = preg_replace('/\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}/', 'DATE', $trace);

		return ['result' => $result, 'entries' => $entries, 'trace' => $trace];
	}//end record()

	private function node(string $name, ?string $content): File|Folder {
		if ($content === null) {
			$folder = $this->createMock(Folder::class);
			$folder->method('getName')->willReturn($name);
			return $folder;
		}

		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn($name);
		if ($content === 'throw-content') {
			$file->method('getContent')->willThrowException(new Exception('storage gone'));
		} else {
			$file->method('getContent')->willReturn($content);
		}

		return $file;
	}//end node()
}//end class
