<?php

/**
 * Unit test for ExampleSetPaths: reading, setting and dropping dotted paths in
 * a row, and turning JSON-text fields into text and back.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ExampleSet
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

use OCA\OpenRegister\Service\ExampleSet\ExampleSetPaths;
use PHPUnit\Framework\TestCase;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetPathValue;

/**
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetPaths
 */
class ExampleSetPathsBehaviourTest extends TestCase {

	/**
	 * A nested row.
	 *
	 * @var array<string, mixed>
	 */
	private const ROW = ['a' => ['b' => ['c' => 1], 'n' => null], 's' => 'text'];

	/**
	 * The first missing prefix of a path is named; a path whose parents are there is named whole.
	 *
	 * @return void
	 */
	public function testFirstMissingNamesTheShortestMissingPrefix(): void {
		$paths = new ExampleSetPaths();
		$this->assertSame('x', $paths->firstMissing(row: self::ROW, path: 'x.y.z'));
		$this->assertSame('a.q', $paths->firstMissing(row: self::ROW, path: 'a.q.z'));
		$this->assertSame('a.b.zz', $paths->firstMissing(row: self::ROW, path: 'a.b.zz'));
		$this->assertSame('s', $paths->firstMissing(row: self::ROW, path: 's'));
	}//end testFirstMissingNamesTheShortestMissingPrefix()

	/**
	 * A path is found with its value, a null value is still found, and a path through text is not.
	 *
	 * @return void
	 */
	public function testAtPathTellsFoundFromValue(): void {
		$paths = new ExampleSetPaths();
		$this->assertSame(['found' => true, 'value' => 1], $paths->atPath(row: self::ROW, path: 'a.b.c'));
		$this->assertSame(['found' => true, 'value' => null], $paths->atPath(row: self::ROW, path: 'a.n'));
		$this->assertSame(['found' => false, 'value' => null], $paths->atPath(row: self::ROW, path: 's.t'));
		$this->assertSame(['found' => false, 'value' => null], $paths->atPath(row: self::ROW, path: 'a.zz'));
	}//end testAtPathTellsFoundFromValue()

	/**
	 * Setting a deep path keeps its siblings and creates the parents it lacks.
	 *
	 * @return void
	 */
	public function testWithPathCreatesParentsAndKeepsSiblings(): void {
		$paths = new ExampleSetPaths();
		$this->assertSame(
			['a' => ['b' => ['c' => 2], 'n' => null], 's' => 'text'],
			$paths->withPath(row: self::ROW, set: new ExampleSetPathValue(path: 'a.b.c', value: 2))
		);
		$this->assertSame(
			self::ROW + ['x' => ['y' => 'v']],
			$paths->withPath(row: self::ROW, set: new ExampleSetPathValue(path: 'x.y', value: 'v'))
		);
	}//end testWithPathCreatesParentsAndKeepsSiblings()

	/**
	 * Dropping a path removes only its leaf, and a path through text or a missing parent changes nothing.
	 *
	 * @return void
	 */
	public function testWithoutPathDropsOnlyTheLeaf(): void {
		$paths = new ExampleSetPaths();
		$this->assertSame(['a' => ['b' => [], 'n' => null], 's' => 'text'], $paths->withoutPath(row: self::ROW, path: 'a.b.c'));
		$this->assertSame(['a' => self::ROW['a']], $paths->withoutPath(row: self::ROW, path: 's'));
		$this->assertSame(self::ROW, $paths->withoutPath(row: self::ROW, path: 's.t'));
		$this->assertSame(self::ROW, $paths->withoutPath(row: self::ROW, path: 'q.r'));
	}//end testWithoutPathDropsOnlyTheLeaf()

	/**
	 * Only named array fields become JSON text; other fields and non-string names are left alone.
	 *
	 * @return void
	 */
	public function testWithJsonTextEncodesOnlyNamedArrays(): void {
		$paths = new ExampleSetPaths();
		$this->assertSame(
			['a' => '{"b":{"c":1},"n":null}', 's' => 'text'],
			$paths->withJsonText(row: self::ROW, fields: ['a', 's', 0, 'missing'])
		);
	}//end testWithJsonTextEncodesOnlyNamedArrays()

	/**
	 * Only named text fields that hold a JSON array or object are decoded; plain text and scalars stay text.
	 *
	 * @return void
	 */
	public function testWithJsonDecodedDecodesOnlyStructures(): void {
		$paths = new ExampleSetPaths();
		$row   = ['j' => '{"x":[1,2]}', 'n' => '42', 't' => 'not json', 'a' => ['kept'], 'other' => '[1]'];
		$this->assertSame(
			['j' => ['x' => [1, 2]], 'n' => '42', 't' => 'not json', 'a' => ['kept'], 'other' => '[1]'],
			$paths->withJsonDecoded(row: $row, fields: ['j', 'n', 't', 'a', 7])
		);
	}//end testWithJsonDecodedDecodesOnlyStructures()
}//end class
