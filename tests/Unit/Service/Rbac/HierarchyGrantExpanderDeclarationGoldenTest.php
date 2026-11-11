<?php

/**
 * Golden outcomes of HierarchyGrantExpander::declarationFor() and the level walk's edges.
 *
 * Pins, on the code before declarationFor/absorbLevel/expandOne were split, the
 * declaration read for every authored shape (absent, malformed, empty parent,
 * both spellings, depth below one / above the ceiling, verb lists with blanks
 * and non-strings), and two level-walk edges the scenario golden does not
 * reach: a child reported under a parent outside the frontier (refused) and
 * integer-keyed children (string-cast and granted).
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Rbac\HierarchyLevel;
use OCA\OpenRegister\Service\Rbac\HierarchyDescender;
use OCA\OpenRegister\Service\Rbac\HierarchyGrantExpander;
use OCP\Constants;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\OpenRegister\Service\Rbac\HierarchyGrantExpander
 */
final class HierarchyGrantExpanderDeclarationGoldenTest extends TestCase {

	/**
	 * Authored configurations and the declaration read from each.
	 *
	 * @return array<string, array{0: array|null, 1: array|null}>
	 */
	public static function declarations(): array {
		$block = static fn (array $b): array => [HierarchyGrantExpander::ANNOTATION => $b];

		return [
			'no configuration' => [null, null],
			'no annotation' => [['other' => 1], null],
			'annotation not an array' => [[HierarchyGrantExpander::ANNOTATION => 'parent'], null],
			'no parent' => [$block([]), null],
			'blank parent' => [$block(['parent' => '   ']), null],
			'empty parent falls back to nothing' => [$block(['parent' => '', 'parentField' => 'up']), null],
			'parent' => [$block(['parent' => ' up ']), ['parent' => 'up', 'maxDepth' => 5, 'verbs' => []]],
			'parentField' => [$block(['parentField' => 'up']), ['parent' => 'up', 'maxDepth' => 5, 'verbs' => []]],
			'parent wins' => [$block(['parent' => 'a', 'parentField' => 'b']), ['parent' => 'a', 'maxDepth' => 5, 'verbs' => []]],
			'integer parent' => [$block(['parent' => 7]), ['parent' => '7', 'maxDepth' => 5, 'verbs' => []]],
			'depth zero is default' => [$block(['parent' => 'up', 'maxDepth' => 0]), ['parent' => 'up', 'maxDepth' => 5, 'verbs' => []]],
			'negative depth is default' => [$block(['parent' => 'up', 'maxDepth' => -3]), ['parent' => 'up', 'maxDepth' => 5, 'verbs' => []]],
			'string depth' => [$block(['parent' => 'up', 'maxDepth' => '3']), ['parent' => 'up', 'maxDepth' => 3, 'verbs' => []]],
			'depth one' => [$block(['parent' => 'up', 'maxDepth' => 1]), ['parent' => 'up', 'maxDepth' => 1, 'verbs' => []]],
			'depth at ceiling' => [$block(['parent' => 'up', 'maxDepth' => 20]), ['parent' => 'up', 'maxDepth' => 20, 'verbs' => []]],
			'depth above ceiling' => [$block(['parent' => 'up', 'maxDepth' => 99]), ['parent' => 'up', 'maxDepth' => 20, 'verbs' => []]],
			'verbs not a list' => [$block(['parent' => 'up', 'inheritedVerbs' => 'read']), ['parent' => 'up', 'maxDepth' => 5, 'verbs' => []]],
			'verbs trimmed, blanks dropped' => [
				$block(['parent' => 'up', 'inheritedVerbs' => [' read ', '', '  ', 'update', 5, null]]),
				['parent' => 'up', 'maxDepth' => 5, 'verbs' => ['read', 'update', '5']],
			],
		];
	}//end declarations()

	/**
	 * The declaration read from each authored shape.
	 *
	 * @param array|null $configuration The schema configuration.
	 * @param array|null $expected      The declaration, or null when none.
	 *
	 * @return void
	 */
	#[DataProvider('declarations')]
	public function testTheDeclarationIsPinned(?array $configuration, ?array $expected): void {
		$schema = new Schema();
		$schema->setConfiguration($configuration);
		$expander = new HierarchyGrantExpander($this->createMock(HierarchyDescender::class), new NullLogger());

		$this->assertSame($expected, $expander->declarationFor($schema));
	}//end testTheDeclarationIsPinned()

	/**
	 * A child reported under a parent outside the frontier is refused; integer keys are cast.
	 *
	 * @return void
	 */
	public function testLevelWalkEdges(): void {
		$read       = Constants::PERMISSION_READ;
		$descender  = $this->createMock(HierarchyDescender::class);
		$descender->method('hierarchicalTables')->willReturn(
			[['table' => 't', 'parentColumn' => 'p', 'maxDepth' => 3, 'verbs' => [], 'schemaId' => 9]]
		);
		$descender->method('childrenOf')->willReturnCallback(
			static function (HierarchyLevel $level, array $parents): array {
				if ($parents === ['root']) {
					// 'stray' names a parent nobody was granted; 42 is an int key.
					return ['stray' => 'elsewhere', 42 => 'root', 'kid' => 'root'];
				}

				if ($parents === [42, 'kid']) {
					return ['deep' => 42];
				}

				return [];
			}
		);

		$expander = new HierarchyGrantExpander($descender, new NullLogger());
		$this->assertSame(
			[
				'granted' => ['root' => $read, '42' => $read, 'kid' => $read, 'deep' => $read],
				'sources' => ['42' => 'root', 'kid' => 'root', 'deep' => 'root'],
			],
			$expander->expand(['root' => $read])
		);
	}//end testLevelWalkEdges()
}//end class
