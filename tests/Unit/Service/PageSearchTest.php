<?php

/**
 * Unit tests for PageSearch — which page titles a picker search keeps.
 *
 * An empty or absent search keeps every title; a search keeps titles containing it, case-insensitively, and matching() leaves the original search untouched.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Service\PageSearch;
use PHPUnit\Framework\TestCase;
use stdClass;

final class PageSearchTest extends TestCase {

	public function testWithoutASearchEveryTitleMatches(): void {
		$search = new PageSearch(new stdClass(), 'anna');

		$this->assertTrue($search->matches('Anything'));
		$this->assertTrue($search->matching(null)->matches('Anything'));
		$this->assertTrue($search->matching('')->matches('Anything'));
	}

	public function testASearchKeepsTitlesContainingItCaseInsensitively(): void {
		$search = (new PageSearch(new stdClass(), 'anna'))->matching('ÉTÉ');

		$this->assertTrue($search->matches('Notes van het été'));
		$this->assertTrue($search->matches('ÉTÉ'));
		$this->assertFalse($search->matches('Winter'));
	}

	public function testMatchingReturnsACopyAndAnEmptySearchClearsAnEarlierOne(): void {
		$original = new PageSearch(new stdClass(), 'anna');
		$narrowed = $original->matching('zomer');
		$cleared = $narrowed->matching('');

		$this->assertNotSame($original, $narrowed);
		$this->assertTrue($original->matches('Winter'), 'The original keeps matching everything.');
		$this->assertFalse($narrowed->matches('Winter'));
		$this->assertTrue($cleared->matches('Winter'));
		$this->assertSame('anna', $narrowed->uid);
	}
}
