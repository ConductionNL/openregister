<?php

/**
 * QueryText: a trimmed, non-empty text query parameter or null.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Notifications\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/unit-test-coverage/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Notifications\Controller\Support;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Notifications\Controller\Support\QueryText;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Notifications\Controller\Support\QueryText
 */
class QueryTextTest extends TestCase {

	public function testATextValueIsTrimmed(): void {
		$this->assertSame('urgent', (new QueryText(['q' => "  urgent\n"]))->value('q'));
	}

	public function testAbsentBlankAndNonStringValuesAreNull(): void {
		$query = new QueryText(['blank' => " \t ", 'empty' => '', 'number' => 5, 'list' => ['a'], 'nothing' => null]);

		foreach (['missing', 'blank', 'empty', 'number', 'list', 'nothing'] as $key) {
			$this->assertNull($query->value($key), $key);
		}
	}

	public function testAZeroStringIsKept(): void {
		$this->assertSame('0', (new QueryText(['page' => '0']))->value('page'));
	}
}
