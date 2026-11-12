<?php

/**
 * Golden test for the filecache reads of FileMapper.
 *
 * Records every query-builder call and the returned rows of getFiles(),
 * getFile(), getFilesByIds(), findUntrackedFiles() and countUntrackedFiles()
 * over rows with and without a public share, publication time and storage
 * owner. Re-record with UPDATE_GOLDEN=1.
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

use OCA\OpenRegister\Files\Db\FileMapper;
use OCA\OpenRegister\Files\Service\File\ShareUrlBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Files\Db\FileMapper
 */
class FileMapperFilecacheReadGoldenTest extends TestCase {
	use RecordingQueryBuilderTrait;

	private const FIXTURE = __DIR__ . '/fixtures/file-mapper-filecache-read-golden.json';

	private static function rows(): array {
		return [
			['fileid' => 7, 'name' => 'plain.txt', 'share_token' => null, 'share_stime' => null, 'storage_id' => null],
			['fileid' => 8, 'name' => 'shared.pdf', 'share_token' => 'tok8', 'share_stime' => 1700000000, 'storage_id' => 'home::alice'],
			['fileid' => '9', 'name' => 'ext.doc', 'share_token' => '', 'share_stime' => 0, 'storage_id' => 'local::/srv/data'],
			['fileid' => 10, 'name' => 'empty.bin', 'share_token' => 'tok10', 'share_stime' => '', 'storage_id' => ''],
		];
	}//end rows()

	private static function cases(): array {
		$rows = self::rows();
		return [
			'getFiles no filter' => ['getFiles', [], [$rows]],
			'getFiles node' => ['getFiles', ['node' => 5], [$rows]],
			'getFiles ids' => ['getFiles', ['node' => 5, 'ids' => [1, 2]], [[$rows[1]]]],
			'getFiles empty ids' => ['getFiles', ['ids' => []], [[]]],
			'getFile found plain' => ['getFile', ['fileId' => 7], [[$rows[0]]]],
			'getFile found shared' => ['getFile', ['fileId' => 8], [[$rows[1]]]],
			'getFile found other storage' => ['getFile', ['fileId' => 9], [[$rows[2]]]],
			'getFile found empty storage' => ['getFile', ['fileId' => 10], [[$rows[3]]]],
			'getFile missing' => ['getFile', ['fileId' => 11], [[]]],
			'getFilesByIds mixed' => ['getFilesByIds', ['fileIds' => [8, '9', 'x', 8]], [[$rows[1], $rows[2]]]],
			'getFilesByIds none numeric' => ['getFilesByIds', ['fileIds' => ['a']], []],
			'findUntrackedFiles default' => ['findUntrackedFiles', [], [[['fileid' => 3, 'path' => 'files/a']]]],
			'findUntrackedFiles paged' => ['findUntrackedFiles', ['limit' => 5, 'offset' => 10], [[]]],
			'countUntrackedFiles' => ['countUntrackedFiles', [], [[['count' => 4]]]],
		];
	}//end cases()

	public function testFilecacheReadsMatchGolden(): void {
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
	}//end testFilecacheReadsMatchGolden()

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

		$outcome = [];
		try {
			$outcome['result'] = $mapper->$method(...$args);
		} catch (\Throwable $e) {
			$outcome['thrown'] = get_class($e) . ': ' . $e->getMessage();
		}

		$outcome['queries'] = $this->queryLog;
		return $outcome;
	}//end read()
}//end class
