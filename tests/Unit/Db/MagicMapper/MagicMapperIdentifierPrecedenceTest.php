<?php

/**
 * Which row an identifier means when it matches more than one (#4037).
 *
 * The identifier lookup is one OR(id, uuid, slug, uri) query. A row matched by
 * its id or uuid wins over rows matched by a slug that looks the same, so a
 * numeric slug does not make `GET .../2024` ambiguous with the object whose
 * id is 2024.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/object-slugs-configurable-and-unique/specs/object-lifecycle/spec.md#requirement-a-slug-lookup-that-matches-more-than-one-object-in-a-register-and-schema-must-be-refused-as-ambiguous
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use OCA\OpenRegister\Db\MagicMapper;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class MagicMapperIdentifierPrecedenceTest extends TestCase {

	/**
	 * Run the private row picker.
	 *
	 * @param list<array<string, mixed>> $rows       The matched rows.
	 * @param string|int                 $identifier The identifier.
	 *
	 * @return array<string, mixed>|null The picked row.
	 */
	private function pick(array $rows, string|int $identifier): ?array {
		$mapper = $this->getMockBuilder(MagicMapper::class)->disableOriginalConstructor()->getMock();
		$method = new ReflectionMethod(MagicMapper::class, 'rowForIdentifier');
		return $method->invoke($mapper, $rows, $identifier);
	}//end pick()

	private function row(int $id, string $uuid, ?string $slug): array {
		return ['_id' => $id, '_uuid' => $uuid, '_slug' => $slug];
	}//end row()

	public function testOneMatchIsTheAnswer(): void {
		$row = $this->row(5, 'aaaa', 'hello');
		$this->assertSame($row, $this->pick([$row], 'hello'));
	}

	public function testANumericSlugDoesNotMaskTheObjectWithThatId(): void {
		$bySlug = $this->row(5, 'aaaa', '2024');
		$byId = $this->row(2024, 'bbbb', 'other');

		$this->assertSame($byId, $this->pick([$bySlug, $byId], '2024'));
		$this->assertSame($byId, $this->pick([$byId, $bySlug], 2024));
	}

	public function testAUuidLookingSlugDoesNotMaskTheObjectWithThatUuid(): void {
		$uuid = '123e4567-e89b-12d3-a456-426614174000';
		$bySlug = $this->row(5, 'aaaa', $uuid);
		$byUuid = $this->row(6, strtoupper($uuid), 'other');

		$this->assertSame($byUuid, $this->pick([$bySlug, $byUuid], $uuid));
	}

	public function testOnlyACanonicalIntegerIsAnId(): void {
		// Review finding pp-f2: `007`, `7.0`, `1e1` and `7 ` are slugs, never the object with id 7 or 10.
		$byId = $this->row(7, 'bbbb', 'other');
		$bySlug = $this->row(3, 'aaaa', '007');
		foreach (['007', '7.0', '1e1', '7 '] as $identifier) {
			$this->assertNull($this->pick([$bySlug, $byId], $identifier), $identifier);
		}

		$this->assertSame($byId, $this->pick([$bySlug, $byId], '7'));
	}

	public function testTwoSlugHoldersAreAmbiguous(): void {
		$this->assertNull($this->pick([$this->row(1, 'aaaa', 'hello'), $this->row(2, 'bbbb', 'hello')], 'hello'));
	}

	public function testTwoRowsMatchedByKeyAreStillAmbiguous(): void {
		// Not reachable with unique ids and uuids; the picker must not guess.
		$this->assertNull($this->pick([$this->row(7, 'aaaa', null), $this->row(7, 'bbbb', null)], '7'));
	}
}//end class
