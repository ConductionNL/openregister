<?php

/**
 * Golden record of FlowStream::commonPrefix() over the path sets a join folds.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\FlowStream;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\FlowStream
 *
 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-the-run-log-must-be-ordered-by-branch-never-by-completion
 */
class FlowStreamCommonPrefixGoldenTest extends TestCase {
	/**
	 * Input paths and the prefix they fold onto.
	 *
	 * @return array<string, array{0: array<int|string, mixed>, 1: string}>
	 */
	public static function cases(): array {
		return [
			'no paths' => [[], '0001'],
			'only empty and non-string' => [['', null, 5, ['0001']], '0001'],
			'single path' => [['0001.0002.0003'], '0001.0002.0003'],
			'siblings' => [['0001.0001', '0001.0002'], '0001'],
			'nested siblings' => [['0001.0002.0001', '0001.0002.0002', '0001.0002.0003'], '0001.0002'],
			'one is ancestor of the other' => [['0001.0002', '0001.0002.0005'], '0001.0002'],
			'different splits share the root' => [['0001.0001.0001', '0001.0002.0001'], '0001'],
			'nothing shared' => [['0002.0001', '0003.0001'], '0001'],
			'identical paths' => [['0001.0004', '0001.0004'], '0001.0004'],
			'string keys and junk are ignored' => [['a' => '0001.0003.0001', 'b' => '', 'c' => '0001.0003.0002', 'd' => false], '0001.0003'],
			'divergence after a shared segment beyond the root' => [['0005.0001', '0005.0002'], '0005'],
			'zero padding is compared as text' => [['0001.01', '0001.1'], '0001'],
			'shallowest path bounds the depth' => [['0001', '0001.0001', '0001.0002.0003'], '0001'],
			'non-root single segment' => [['0007'], '0007'],
			'trailing empty segment' => [['0001.', '0001.'], '0001.'],
		];
	}//end cases()

	#[DataProvider('cases')]
	public function testCommonPrefixMatchesGolden(array $paths, string $expected): void {
		$this->assertSame($expected, FlowStream::commonPrefix($paths));
	}//end testCommonPrefixMatchesGolden()
}//end class
