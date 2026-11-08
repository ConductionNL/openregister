<?php

/**
 * OpenRegister SearchTrailUserAgentParser
 *
 * Extracts the browser and its version from the user agent strings in search trail statistics.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/zoeken-filteren/spec.md
 */

// No strict_types on purpose: keeps the scalar coercion SearchTrailService applied
// to the user agent values it got from the mapper.

namespace OCA\OpenRegister\Service;

/**
 * Simple user agent parsing (could be enhanced with a proper library).
 *
 * @spec openspec/specs/zoeken-filteren/spec.md
 */
final class SearchTrailUserAgentParser {

	/**
	 * Browser => version pattern, tried in this order; the first match wins.
	 * Every pattern has exactly one capturing group.
	 */
	private const BROWSER_PATTERNS = [
		'Chrome' => '/Chrome\/([0-9.]+)/',
		'Firefox' => '/Firefox\/([0-9.]+)/',
		'Safari' => '/Safari\/([0-9.]+)/',
		'Edge' => '/Edge\/([0-9.]+)/',
		'Opera' => '/Opera\/([0-9.]+)/',
	];

	/**
	 * Add a `browser_info` entry to each user agent statistic.
	 *
	 * @param array $stats User agent statistics, each with a `user_agent`
	 *
	 * @return array The statistics with `browser_info` appended
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	public function withBrowserInfo(array $stats): array {
		return array_map(
			function ($stat) {
				$stat['browser_info'] = $this->parse(userAgent: $stat['user_agent']);
				return $stat;
			},
			$stats
		);
	}//end withBrowserInfo()

	/**
	 * Parse user agent string to extract browser information
	 *
	 * @param string $userAgent User agent string
	 *
	 * @return string[]
	 *
	 * @psalm-return array{browser: string, version: string, full_string: string}
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	public function parse(string $userAgent): array {
		foreach (self::BROWSER_PATTERNS as $browser => $pattern) {
			if (preg_match($pattern, $userAgent, $matches) === 1) {
				// Group 1 is set: preg_match returned 1 and every pattern has one group.
				return $this->browserInfo(browser: $browser, version: $matches[1], userAgent: $userAgent);
			}
		}

		return $this->browserInfo(browser: 'unknown', version: 'unknown', userAgent: $userAgent);
	}//end parse()

	/**
	 * Build the browser information record.
	 *
	 * @param string $browser   Browser name
	 * @param string $version   Browser version
	 * @param string $userAgent The full user agent string
	 *
	 * @return string[]
	 *
	 * @psalm-return array{browser: string, version: string, full_string: string}
	 */
	private function browserInfo(string $browser, string $version, string $userAgent): array {
		return [
			'browser' => $browser,
			'version' => $version,
			'full_string' => $userAgent,
		];
	}//end browserInfo()
}//end class
