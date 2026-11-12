<?php

/**
 * Golden test for the object-file lookups of FileMapper.
 *
 * Records every query-builder call and the returned rows of getFilesForObject()
 * and getFileIdsForObjects(): an explicit folder, no uuid, no, one and several
 * folders named after the uuid, invalid uuids, duplicate folder names and
 * folders without children. Re-record with UPDATE_GOLDEN=1.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Files\Db\FileMapper;
use OCA\OpenRegister\Files\Service\File\ShareUrlBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Files\Db\FileMapper
 */
class FileMapperObjectFilesGoldenTest extends TestCase {
	use RecordingQueryBuilderTrait;

	private const FIXTURE = __DIR__ . '/fixtures/file-mapper-object-files-golden.json';

	private static function child(int $fileId): array {
		return ['fileid' => $fileId, 'name' => 'f' . $fileId . '.txt', 'share_token' => null, 'share_stime' => null, 'storage_id' => null];
	}//end child()

	private static function cases(): array {
		$children = [self::child(70), self::child(71)];
		return [
			'object with folder' => ['getFilesForObject', ['folder' => '12', 'uuid' => 'u-1'], [$children]],
			'object without folder or uuid' => ['getFilesForObject', ['folder' => null, 'uuid' => null], []],
			'object uuid matches no folder' => ['getFilesForObject', ['folder' => null, 'uuid' => 'u-1'], [[]]],
			'object uuid matches one folder' => ['getFilesForObject', ['folder' => null, 'uuid' => 'u-1'], [[['fileid' => '30']], $children]],
			'object uuid matches several folders' => [
				'getFilesForObject',
				['folder' => null, 'uuid' => 'u-1'],
				[[['fileid' => '33'], ['fileid' => 31], ['fileid' => '32']], $children],
			],
			'ids for no uuids' => ['getFileIdsForObjects', ['uuids' => []], []],
			'ids for invalid uuids only' => ['getFileIdsForObjects', ['uuids' => ['', 0, null]], []],
			'ids where no folder exists' => ['getFileIdsForObjects', ['uuids' => ['u-1', '', 'u-2']], [[]]],
			'ids with duplicate folders and childless folder' => [
				'getFileIdsForObjects',
				['uuids' => ['u-1', 'u-2', 'u-3', 'u-1', 7]],
				[
					[
						['fileid' => '40', 'name' => 'u-1'],
						['fileid' => 41, 'name' => 'u-2'],
						['fileid' => 42, 'name' => 'u-1'],
					],
					[
						['fileid' => '50', 'parent' => '40'],
						['fileid' => 51, 'parent' => 40],
						['fileid' => 52, 'parent' => 42],
					],
				],
			],
			'ids where folders have no children' => ['getFileIdsForObjects', ['uuids' => ['u-1']], [[['fileid' => 40, 'name' => 'u-1']], []]],
		];
	}//end cases()

	public function testObjectFileLookupsMatchGolden(): void {
		$actual = [];
		foreach (self::cases() as $name => [$method, $args, $rows]) {
			$actual[$name] = $this->read(method: $method, args: $args, rows: $rows);
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
	}//end testObjectFileLookupsMatchGolden()

	private function read(string $method, array $args, array $rows): array {
		$this->queryLog = [];
		$this->builderCount = 0;
		$this->parameterCount = 0;
		$this->queuedRows = $rows;

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->recordingBuilder());

		$urls = $this->createMock(ShareUrlBuilder::class);
		$urls->method('shareLink')->willReturnCallback(fn (string $token): string => 'share:' . $token);
		$urls->method('absolute')->willReturnCallback(fn (string $path): string => 'abs:' . $path);

		$mapper = new FileMapper(db: $db, shareUrls: $urls);

		if ($method === 'getFilesForObject') {
			$object = new ObjectEntity();
			$object->setFolder($args['folder']);
			$object->setUuid($args['uuid']);
			$args = ['object' => $object];
		}

		$outcome = [];
		try {
			$outcome['result'] = $mapper->$method(...$args);
		} catch (\Throwable $e) {
			$outcome['thrown'] = get_class($e) . ': ' . $e->getMessage();
		}

		$outcome['queries'] = $this->queryLog;
		$outcome['unservedRowSets'] = count($this->queuedRows);
		return $outcome;
	}//end read()
}//end class
