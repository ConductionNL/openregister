<?php

/**
 * PaginationLinks: the next and previous page URLs of a list.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Service\Object\PaginationLinks;
use PHPUnit\Framework\TestCase;

/**
 * Which links apply around a page, and how the page parameter is rewritten or added.
 *
 * @covers \OCA\OpenRegister\Service\Object\PaginationLinks
 */
final class PaginationLinksTest extends TestCase {

	/**
	 * A middle page links both ways, rewriting the existing page parameter.
	 *
	 * @return void
	 */
	public function testAMiddlePageLinksBothWays(): void {
		$links = new PaginationLinks(uri: '/api/objects?page=2&limit=10');

		$this->assertSame(
			expected: ['next' => '/api/objects?page=3&limit=10', 'prev' => '/api/objects?page=1&limit=10'],
			actual: $links->around(page: 2, pages: 3)
		);
	}//end testAMiddlePageLinksBothWays()

	/**
	 * The first page has no prev link, the last page no next link, a single page neither.
	 *
	 * @return void
	 */
	public function testTheEdgesDropTheirLink(): void {
		$links = new PaginationLinks(uri: '/api/objects?page=1');

		$this->assertSame(expected: ['next' => '/api/objects?page=2'], actual: $links->around(page: 1, pages: 2));
		$this->assertSame(expected: ['prev' => '/api/objects?page=1'], actual: $links->around(page: 2, pages: 2));
		$this->assertSame(expected: [], actual: $links->around(page: 1, pages: 1));
		$this->assertSame(expected: [], actual: $links->around(page: 1, pages: 0));
	}//end testTheEdgesDropTheirLink()

	/**
	 * An underscored _page parameter is rewritten in place, keeping its underscore.
	 *
	 * @return void
	 */
	public function testAnUnderscoredPageIsRewritten(): void {
		$links = new PaginationLinks(uri: '/api/objects?_limit=5&_page=4');

		$this->assertSame(
			expected: ['next' => '/api/objects?_limit=5&_page=5', 'prev' => '/api/objects?_limit=5&_page=3'],
			actual: $links->around(page: 4, pages: 9)
		);
	}//end testAnUnderscoredPageIsRewritten()

	/**
	 * Without a page parameter one is appended, with ? when there is no query and & when there is.
	 *
	 * @return void
	 */
	public function testAMissingPageIsAppendedWithTheRightSeparator(): void {
		$this->assertSame(expected: ['next' => '/api/objects?page=2'], actual: (new PaginationLinks(uri: '/api/objects'))->around(page: 1, pages: 2));
		$this->assertSame(
			expected: ['next' => '/api/objects?limit=10&page=2'],
			actual: (new PaginationLinks(uri: '/api/objects?limit=10'))->around(page: 1, pages: 2)
		);
	}//end testAMissingPageIsAppendedWithTheRightSeparator()

	/**
	 * A parameter that merely ends in "page" is not taken for the page parameter.
	 *
	 * @return void
	 */
	public function testALookalikeParameterIsLeftAlone(): void {
		$links = new PaginationLinks(uri: '/api/objects?homepage=7');

		$this->assertSame(expected: ['next' => '/api/objects?homepage=7&page=2'], actual: $links->around(page: 1, pages: 2));
	}//end testALookalikeParameterIsLeftAlone()
}//end class
