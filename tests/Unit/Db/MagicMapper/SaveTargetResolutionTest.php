<?php

/**
 * One save resolves its identifier twice: in the caller's scope (the permission
 * check) and without it (the write). Every door must land the write on the row
 * the permission check ran on.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/specs/object-lifecycle/spec.md#requirement-a-slug-lookup-that-matches-more-than-one-object-in-a-register-and-schema-must-be-refused-as-ambiguous
 */

declare(strict_types=1);

namespace Unit\Db\MagicMapper;

use OCA\OpenRegister\Db\MagicMapper;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Feeds both lookups of one save the caller's rows and all rows, and compares
 * the permission row with the write row, per door.
 *
 * @covers \OCA\OpenRegister\Db\MagicMapper
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicColumnSql
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicPgTrgmProbe
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicTableCreator
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicTableIndexer
 */
class SaveTargetResolutionTest extends TestCase {

	private static bool $caseInsensitive = true;

	private function pick(array $rows, string $identifier): ?array {
		$mapper = (new \ReflectionClass(MagicMapper::class))->newInstanceWithoutConstructor();
		$asId = new ReflectionMethod(MagicMapper::class, 'identifierAsId');
		$asId->setAccessible(true);
		$idParam = $asId->invoke($mapper, $identifier);
		$eq = fn ($a, $b) => self::$caseInsensitive ? strcasecmp((string)$a, (string)$b) === 0 : (string)$a === (string)$b;
		$matched = array_values(array_filter($rows, fn ($r) =>
			($idParam !== null && (int)$r['_id'] === $idParam)
			|| $eq($r['_uuid'], $identifier)
			|| ($r['_slug'] !== null && $eq($r['_slug'], $identifier))
		));
		if ($matched === []) {
			return null;
		}
		$m = new ReflectionMethod(MagicMapper::class, 'rowForIdentifier');
		$m->setAccessible(true);
		$row = $m->invoke($mapper, $matched, $identifier);
		return $row ?? ['_ambiguous' => true];
	}

	private function r(int $id, string $uuid, ?string $slug, bool $visible): array {
		return ['_id' => $id, '_uuid' => $uuid, '_slug' => $slug, 'visible' => $visible];
	}

	/** @return array<string, array{0: list<array>, 1: string}> */
	public static function scenarios(): array {
		return [
			'S1 id of visible B = uuid of hidden A' => [[[1, 'bbbb-1', null, true], [9, '1', null, false]], '1'],
			'S2 slug of visible C = id of hidden B' => [[[5, 'cccc-5', '1', true], [1, 'bbbb-1', null, false]], '1'],
			'S3 slug of visible C = uuid of hidden A' => [[[5, 'cccc-5', 'aaaa-9', true], [9, 'aaaa-9', null, false]], 'aaaa-9'],
			'S4 hidden slug = uuid of visible B' => [[[1, 'bbbb-1', null, true], [9, 'aaaa-9', 'bbbb-1', false]], 'bbbb-1'],
			'S5 numeric uuid of visible B = id of hidden A' => [[[20, '5', null, true], [5, 'aaaa-5', null, false]], '5'],
			'S6 case variant uuids' => [[[1, 'abcd-1', null, true], [9, 'ABCD-1', null, false]], 'abcd-1'],
			'S7 visible B uuid = hidden slug AND hidden id' => [[[20, '7', null, true], [7, 'aaaa-7', null, false], [8, 'dddd-8', '7', false]], '7'],
			'S8 id-only of visible, hidden uuid case-variant numeric' => [[[3, 'x-3', null, true], [4, '3', 'y', false]], '3'],
		];
	}

	private function rows(array $spec): array {
		return array_map(fn ($s) => $this->r(...$s), $spec);
	}

	/**
	 * PUT / PATCH / POST-patch on the PR: read in caller scope, then write by the read row's uuid.
	 *
	 * @dataProvider scenarios
	 */
	public function testControllerDoorWritesTheRowItRead(array $spec, string $urlId): void {
		$all = $this->rows($spec);
		$caller = array_values(array_filter($all, fn ($r) => $r['visible']));
		$read = $this->pick($caller, $urlId);
		if ($read === null || isset($read['_ambiguous'])) {
			$this->assertTrue(true, 'refused at the read (404/409)');
			return;
		}
		$perm = $this->pick($caller, $read['_uuid']);
		$write = $this->pick($all, $read['_uuid']);
		if (isset($write['_ambiguous'])) {
			$this->assertTrue(true, 'write refused (fail closed)');
			return;
		}
		$this->assertSame($read['_id'], $perm['_id']);
		$this->assertSame($read['_id'], $write['_id'], 'write must land on the row the caller read');
		$this->assertTrue($write['visible']);
	}

	/**
	 * Doors that pass the caller's identifier straight to saveObject (POST create
	 * with a body id, MCP and GraphQL updates, credentials): the save continues
	 * with the uuid of the row its permission check found.
	 *
	 * @dataProvider scenarios
	 */
	public function testRawIdDoorWritesTheRowItChecked(array $spec, string $urlId): void {
		$all = $this->rows($spec);
		$caller = array_values(array_filter($all, fn ($r) => $r['visible']));
		$perm = $this->pick($caller, $urlId);
		if ($perm === null || isset($perm['_ambiguous'])) {
			$this->assertTrue(true, 'nothing visible answers: the save may only insert, or it is refused');
			return;
		}
		$write = $this->pick($all, $perm['_uuid']);
		if (isset($write['_ambiguous'])) {
			$this->assertTrue(true, 'write refused (fail closed)');
			return;
		}
		$this->assertSame($perm['_id'], $write['_id'], 'permission row and write row differ');
		$this->assertTrue($write['visible']);
	}
}
