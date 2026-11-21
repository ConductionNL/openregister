<?php

/**
 * AcceptLanguageHeader: the language ranges of an Accept-Language header, best first.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

/**
 * Reads an Accept-Language header per RFC 9110: no state, no negotiation.
 *
 * A schema read (Core) takes its first range as the language it asks for; the
 * language negotiation (Configuration) matches the ranges against a register's
 * languages. Both read the header with this one parser.
 *
 * @spec openspec/specs/register-i18n/spec.md#the-api-must-support-language-negotiation-via-accept-language-header
 */
final class AcceptLanguageHeader {

	/**
	 * A static reader; not instantiated.
	 */
	private function __construct() {
	}//end __construct()

	/**
	 * Parse an Accept-Language header string per RFC 9110.
	 *
	 * Parses the header value into an ordered list of language codes
	 * sorted by quality factor (q-value).
	 *
	 * Example input: "en-US,en;q=0.9,nl;q=0.8"
	 * Example output: ["en-US", "en", "nl"]
	 *
	 * @param string $headerValue The Accept-Language header value
	 *
	 * @return string[] Ordered array of language codes (highest priority first)
	 *
	 * @spec openspec/specs/register-i18n/spec.md#the-api-must-support-language-negotiation-via-accept-language-header (parses the Accept-Language
	 *       header per RFC 9110, ordering language tags by descending q-value then appearance)
	 */
	public static function ranked(string $headerValue): array {
		if (trim($headerValue) === '' || $headerValue === '*') {
			return [];
		}

		$languages = self::parseLanguageRanges(headerValue: $headerValue);

		// Sort by quality descending; usort is stable, so ties keep their order of appearance.
		usort($languages, static fn (array $left, array $right): int => $right['quality'] <=> $left['quality']);

		return array_column($languages, 'language');
	}//end ranked()

	/**
	 * Parse every non-empty, non-wildcard range of an Accept-Language header, in order of appearance.
	 *
	 * @param string $headerValue The Accept-Language header value
	 *
	 * @return array<int,array{language: string, quality: float}>
	 */
	private static function parseLanguageRanges(string $headerValue): array {
		$languages = [];
		foreach (explode(',', $headerValue) as $part) {
			$range = self::parseLanguageRange(part: $part);
			if ($range !== null) {
				$languages[] = $range;
			}
		}

		return $languages;
	}//end parseLanguageRanges()

	/**
	 * Parse one comma-separated range of an Accept-Language header.
	 *
	 * @param string $part The range, e.g. "en;q=0.9"
	 *
	 * @return array{language: string, quality: float}|null Null for an empty or wildcard range
	 */
	private static function parseLanguageRange(string $part): ?array {
		// Split on semicolon to separate language from its parameters.
		$segments = explode(';', trim($part));
		$language = trim($segments[0]);
		if ($language === '' || $language === '*') {
			return null;
		}

		return [
			'language' => $language,
			'quality' => self::qualityOf(parameters: array_slice($segments, 1)),
		];
	}//end parseLanguageRange()

	/**
	 * The quality factor of a range: the first `q=` parameter, default 1.0.
	 *
	 * @param string[] $parameters The range's parameters after the language
	 *
	 * @return float
	 */
	private static function qualityOf(array $parameters): float {
		foreach ($parameters as $parameter) {
			$parameter = trim($parameter);
			if (strpos($parameter, 'q=') === 0) {
				return (float)substr($parameter, 2);
			}
		}

		return 1.0;
	}//end qualityOf()
}//end class
