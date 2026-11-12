<?php

/**
 * VocabularyImportTally: per-status counting of imported concepts and the report it yields.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Service\VocabularyImportTally;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\VocabularyImportTally
 */
class VocabularyImportTallyTest extends TestCase {

	public function testAnEmptyTallyReportsZeroes(): void {
		$this->assertSame(
			['scheme' => 'http://s', 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'deprecated' => 0],
			(new VocabularyImportTally())->report('http://s', 0)
		);
	}

	public function testEachStatusIsCountedAndAnUnknownStatusCountsAsUnchanged(): void {
		$tally = new VocabularyImportTally();
		$tally->record('u1', ['uuid' => 'a', 'status' => 'created']);
		$tally->record('u2', ['uuid' => 'b', 'status' => 'created']);
		$tally->record('u3', ['uuid' => 'c', 'status' => 'updated']);
		$tally->record('u4', ['uuid' => 'd', 'status' => 'unchanged']);
		$tally->record('u5', ['uuid' => 'e', 'status' => 'skipped']);

		$this->assertSame(
			['scheme' => 'http://s', 'created' => 2, 'updated' => 1, 'unchanged' => 2, 'deprecated' => 4],
			$tally->report('http://s', 4)
		);
		$this->assertSame(['u1', 'u2', 'u3', 'u4', 'u5'], $tally->seenUris);
		$this->assertSame(['u1' => 'a', 'u2' => 'b', 'u3' => 'c', 'u4' => 'd', 'u5' => 'e'], $tally->uuidByUri);
	}

	public function testARepeatedUriKeepsTheLatestUuid(): void {
		$tally = new VocabularyImportTally();
		$tally->record('u1', ['uuid' => 'first', 'status' => 'created']);
		$tally->record('u1', ['uuid' => 'second', 'status' => 'updated']);

		$this->assertSame(['u1' => 'second'], $tally->uuidByUri);
		$this->assertSame(['u1', 'u1'], $tally->seenUris);
	}
}
