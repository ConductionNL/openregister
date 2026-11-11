<?php

/**
 * DirectoryPageTest.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ObjectSource
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-3.1
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ObjectSource;

use OCA\OpenRegister\Service\ObjectSource\DirectoryPage;
use PHPUnit\Framework\TestCase;

/**
 * The search term is read from filters.search, then _search, then search; the page defaults to 200 from 0.
 */
class DirectoryPageTest extends TestCase {
	public function testTheSearchTermIsReadInItsOrderOfPrecedence(): void {
		$this->assertSame('a', (new DirectoryPage(['filters' => ['search' => 'a'], '_search' => 'b', 'search' => 'c']))->search);
		$this->assertSame('b', (new DirectoryPage(['_search' => 'b', 'search' => 'c']))->search);
		$this->assertSame('c', (new DirectoryPage(['search' => 'c']))->search);
		$this->assertSame('', (new DirectoryPage([]))->search);
	}

	public function testThePageDefaultsTo200FromTheStart(): void {
		$page = new DirectoryPage([]);

		$this->assertSame(200, $page->limit);
		$this->assertSame(0, $page->offset);
	}

	public function testThePageIsReadAsIntegers(): void {
		$page = new DirectoryPage(['limit' => '25', 'offset' => '50']);

		$this->assertSame(25, $page->limit);
		$this->assertSame(50, $page->offset);
	}
}
