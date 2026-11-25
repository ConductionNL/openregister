<?php

/**
 * Every column that can hold a Nextcloud node id is mapped for the move.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/register-document-store/specs/file-actions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\File;

use OCA\OpenRegister\Service\File\DocumentReferenceMap;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Reads the migrations and the object property shapes against the map.
 */
#[CoversClass(DocumentReferenceMap::class)]
class DocumentReferenceMapTest extends TestCase {

	/**
	 * Column names that look like a node id.
	 */
	private const ID_SHAPED = '/(^|_)(file_id|fileid|folder|node_id)$/';

	/**
	 * Every "table.column" a migration adds whose name looks like a node id.
	 *
	 * Tables are resolved from literal names and from the migration's own
	 * `self::CONSTANT` table names.
	 *
	 * @return list<string>
	 */
	private static function idShapedColumns(): array {
		$found = [];
		foreach (glob(dirname(__DIR__, 4) . '/lib/Migration/*.php') as $file) {
			$source = (string)file_get_contents($file);
			$constants = [];
			preg_match_all('/const\s+(\w+)\s*=\s*\'([a-z_]+)\'/', $source, $matches, PREG_SET_ORDER);
			foreach ($matches as $match) {
				$constants[$match[1]] = $match[2];
			}

			$table = null;
			$pattern = '/(?:getTable|createTable)\(\s*(?:tableName:\s*)?(?:[\'"]([a-z_]+)[\'"]|self::(\w+))|addColumn\(\s*[\'"]([a-z_]+)[\'"]/';
			preg_match_all($pattern, $source, $tokens, PREG_SET_ORDER);
			foreach ($tokens as $token) {
				if (($token[3] ?? '') !== '') {
					if ($table !== null && preg_match(self::ID_SHAPED, $token[3]) === 1) {
						$found[$table . '.' . $token[3]] = true;
					}

					continue;
				}

				$table = $token[1] !== '' ? $token[1] : ($constants[$token[2]] ?? null);
			}
		}

		$columns = array_keys($found);
		sort($columns);
		return $columns;
	}//end idShapedColumns()

	public function testEveryFileIdColumnIsMapped(): void {
		$map = new DocumentReferenceMap();
		$unknown = [];
		foreach (self::idShapedColumns() as $column) {
			[$table, $name] = explode('.', $column);
			if ($map->isMapped(table: $table, column: $name) === false && isset($map->notFileIds()[$column]) === false) {
				$unknown[] = $column;
			}
		}

		$this->assertSame([], $unknown, "A column holds a file id the move would not rewrite. Map it, or list it as not a file id with the reason:\n" . implode("\n", $unknown));
	}//end testEveryFileIdColumnIsMapped()

	public function testTheScanFindsTheKnownColumns(): void {
		$columns = self::idShapedColumns();

		$this->assertContains('openregister_registers.folder', $columns);
		$this->assertContains('openregister_file_texts.file_id', $columns);
		$this->assertContains('openregister_tasks.node_id', $columns);
	}//end testTheScanFindsTheKnownColumns()

	public function testEveryExclusionNamesAColumnThatExists(): void {
		$columns = self::idShapedColumns();
		$stale = array_values(array_diff(array_keys((new DocumentReferenceMap())->notFileIds()), $columns));

		$this->assertSame([], $stale, 'An exclusion names a column no migration adds.');
	}//end testEveryExclusionNamesAColumnThatExists()

	public function testChunksAreMappedOnlyForFileSources(): void {
		$chunks = array_values(array_filter((new DocumentReferenceMap())->columns(), fn (array $entry) => $entry['table'] === 'openregister_chunks'));

		$this->assertSame([['table' => 'openregister_chunks', 'column' => 'source_id', 'kind' => 'file', 'where' => ['source_type' => 'file']]], $chunks);
	}//end testChunksAreMappedOnlyForFileSources()

	public function testFileIdsAreReadInEveryShapeAFilePropertyTakes(): void {
		$object = [
			'single' => 12,
			'text' => '13',
			'many' => [14, '15', ['id' => 16, 'path' => 'x']],
			'asObject' => ['id' => 17, 'title' => 'a.pdf'],
			'empty' => null,
			'notAFile' => 18,
		];

		$found = (new DocumentReferenceMap())->objectFileIds(object: $object, properties: ['single', 'text', 'many', 'asObject', 'empty', 'missing']);

		$this->assertSame(['single' => [12], 'text' => [13], 'many' => [14, 15, 16], 'asObject' => [17]], $found);
	}//end testFileIdsAreReadInEveryShapeAFilePropertyTakes()

	public function testRewritingKeepsEachValueItsShape(): void {
		$object = [
			'single' => 12,
			'text' => '13',
			'many' => [14, '15', ['id' => 16, 'path' => 'x']],
			'asObject' => ['id' => 17, 'title' => 'a.pdf'],
			'notMoved' => 99,
			'notAFile' => 12,
		];
		$moved = [12 => 112, 13 => 113, 14 => 114, 15 => 115, 16 => 116, 17 => 117];

		$rewritten = (new DocumentReferenceMap())->rewriteObject(object: $object, properties: ['single', 'text', 'many', 'asObject', 'notMoved'], moved: $moved);

		$this->assertSame([
			'single' => 112,
			'text' => '113',
			'many' => [114, '115', ['id' => 116, 'path' => 'x']],
			'asObject' => ['id' => 117, 'title' => 'a.pdf'],
			'notMoved' => 99,
			'notAFile' => 12,
		], $rewritten);
	}//end testRewritingKeepsEachValueItsShape()
}//end class
