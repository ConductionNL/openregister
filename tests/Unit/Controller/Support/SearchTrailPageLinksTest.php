<?php

/**
 * Unit test for SearchTrailPageLinks.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\Support\SearchTrailPageLinks;
use PHPUnit\Framework\TestCase;

/**
 * The next and previous links of a search-trail page.
 *
 * @covers \OCA\OpenRegister\Controller\Support\SearchTrailPageLinks
 */
class SearchTrailPageLinksTest extends TestCase {
	public function testAMiddlePageLinksBothWaysByRewritingThePageParameter(): void {
		$links = (new SearchTrailPageLinks('https://x/api/search-trails?_limit=20&_page=3'))->around(3, 5);

		$this->assertSame(
			['next' => 'https://x/api/search-trails?_limit=20&_page=4', 'prev' => 'https://x/api/search-trails?_limit=20&_page=2'],
			$links
		);
	}

	public function testTheFirstPageHasOnlyNextAndTheLastOnlyPrev(): void {
		$links = new SearchTrailPageLinks('https://x/t?_page=1');

		$this->assertSame(['next' => 'https://x/t?_page=2'], $links->around(1, 2));
		$this->assertSame(['prev' => 'https://x/t?_page=1'], $links->around(2, 2));
		$this->assertSame([], $links->around(1, 1));
	}

	public function testALegacyPageParameterIsRenamed(): void {
		$this->assertSame(
			['next' => 'https://x/t?q=a&_page=3', 'prev' => 'https://x/t?q=a&_page=1'],
			(new SearchTrailPageLinks('https://x/t?q=a&page=2'))->around(2, 3)
		);
	}

	public function testWithoutAPageParameterOneIsAppended(): void {
		$this->assertSame(
			['next' => 'https://x/t?q=a&_page=2'],
			(new SearchTrailPageLinks('https://x/t?q=a'))->around(1, 4)
		);
	}
}
