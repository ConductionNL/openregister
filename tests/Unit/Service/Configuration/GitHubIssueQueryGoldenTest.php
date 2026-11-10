<?php

/**
 * Golden: the request options and merged pages of a GitHub issue query,
 * pinned on exact arrays.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Configuration
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

namespace OCA\OpenRegister\Tests\Unit\Service\Configuration;

use OCA\OpenRegister\Service\Configuration\GitHubIssueQuery;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Configuration\GitHubIssueQuery
 */
class GitHubIssueQueryGoldenTest extends TestCase {
	/**
	 * The defaults: open, by thumbs-up, 30 a page, anonymous, no label.
	 *
	 * @return void
	 */
	public function testDefaultOptions(): void {
		$query = new GitHubIssueQuery('Conduction NL', 'open/register');
		$this->assertSame('https://api.example/repos/Conduction%20NL/open%2Fregister/issues', $query->url('https://api.example'));
		$this->assertSame([], $query->labels());
		$expected = [
			'query'   => ['state' => 'open', 'sort' => 'reactions-+1', 'direction' => 'desc', 'per_page' => 30],
			'headers' => ['Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2022-11-28'],
		];
		$this->assertSame($expected, $query->requestOptions(null));
		$this->assertSame($expected, $query->requestOptions(''));
	}//end testDefaultOptions()

	/**
	 * Every wither shows in the options, and leaves the original as it was.
	 *
	 * @return void
	 */
	public function testWithers(): void {
		$base  = new GitHubIssueQuery('o', 'r');
		$query = $base->filteredBy('closed', 'created')->pagedBy(5)->withLabels(['bug', '', 'help wanted'])->withToken('t0k');
		$this->assertSame(['bug', 'help wanted'], $query->labels());
		$this->assertSame(
			[
				'query'   => ['state' => 'closed', 'sort' => 'created', 'direction' => 'desc', 'per_page' => 5, 'labels' => 'bug'],
				'headers' => ['Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2022-11-28', 'Authorization' => 'Bearer t0k'],
			],
			$query->requestOptions('bug')
		);
		$this->assertSame([], $query->withLabels(null)->labels());
		$this->assertSame(['state' => 'open', 'sort' => 'reactions-+1', 'direction' => 'desc', 'per_page' => 30], $base->requestOptions(null)['query']);
		$this->assertArrayNotHasKey('Authorization', $query->withToken('')->requestOptions(null)['headers']);
	}//end testWithers()

	/**
	 * Pages merge on first occurrence of each positive number, most reactions first, ties in merge order, cut to the page size.
	 *
	 * @return void
	 */
	public function testMerge(): void {
		$query = (new GitHubIssueQuery('o', 'r'))->pagedBy(4);
		$pages = [
			[
				['number' => 3, 'title' => 'a', 'reactions' => ['total_count' => 2]],
				['number' => 0, 'title' => 'zero', 'reactions' => ['total_count' => 99]],
				['title' => 'no number', 'reactions' => ['total_count' => 98]],
				['number' => 7, 'title' => 'b'],
			],
			[
				['number' => 3, 'title' => 'a again', 'reactions' => ['total_count' => 50]],
				['number' => '9', 'title' => 'c', 'reactions' => ['total_count' => 2]],
				['number' => 4, 'title' => 'd', 'reactions' => ['total_count' => 10]],
				['number' => -1, 'title' => 'negative', 'reactions' => ['total_count' => 97]],
				['number' => 5, 'title' => 'e', 'reactions' => ['total_count' => 1]],
			],
		];
		$this->assertSame(
			[
				['number' => 4, 'title' => 'd', 'reactions' => ['total_count' => 10]],
				['number' => 3, 'title' => 'a', 'reactions' => ['total_count' => 2]],
				['number' => '9', 'title' => 'c', 'reactions' => ['total_count' => 2]],
				['number' => 5, 'title' => 'e', 'reactions' => ['total_count' => 1]],
			],
			$query->merge($pages)
		);
		$this->assertSame([], $query->merge([]));
		$this->assertSame([['number' => 7, 'title' => 'b']], $query->merge([[['number' => 7, 'title' => 'b']]]));
	}//end testMerge()
}//end class
