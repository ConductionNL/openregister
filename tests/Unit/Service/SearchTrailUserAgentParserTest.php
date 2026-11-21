<?php

/**
 * The user-agent parser names the browser and version, or says unknown.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Service\SearchTrailUserAgentParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\SearchTrailUserAgentParser
 */
class SearchTrailUserAgentParserTest extends TestCase {

	/**
	 * User agents and the browser and version they name.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public static function agents(): array {
		return [
			'chrome' => ['Mozilla/5.0 (X11) Chrome/120.0.6099.109 Safari/537.36', 'Chrome', '120.0.6099.109'],
			'firefox' => ['Mozilla/5.0 (Windows) Gecko/20100101 Firefox/121.0', 'Firefox', '121.0'],
			'edge wins over chrome order' => ['Mozilla/5.0 Chrome/120.0 Edge/44.18', 'Chrome', '120.0'],
			'unknown' => ['curl/8.5.0', 'unknown', 'unknown'],
			'empty' => ['', 'unknown', 'unknown'],
		];
	}//end agents()

	#[DataProvider('agents')]
	public function testParseNamesTheBrowserAndVersion(string $userAgent, string $browser, string $version): void {
		$parsed = (new SearchTrailUserAgentParser())->parse($userAgent);

		$this->assertSame(['browser' => $browser, 'version' => $version, 'full_string' => $userAgent], $parsed);
	}//end testParseNamesTheBrowserAndVersion()

	public function testWithBrowserInfoEnrichesEachRowInPlace(): void {
		$stats = [
			['user_agent' => 'Mozilla/5.0 Firefox/121.0', 'count' => 3],
			['user_agent' => 'curl/8.5.0', 'count' => 1],
		];

		$enriched = (new SearchTrailUserAgentParser())->withBrowserInfo($stats);

		$this->assertSame('Firefox', $enriched[0]['browser_info']['browser']);
		$this->assertSame(3, $enriched[0]['count']);
		$this->assertSame('unknown', $enriched[1]['browser_info']['browser']);
	}//end testWithBrowserInfoEnrichesEachRowInPlace()
}//end class
