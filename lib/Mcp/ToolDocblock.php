<?php

/**
 * Docblock reading for attributed MCP tools
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Mcp
 * @package  OCA\OpenRegister\Mcp
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/ai-mcp/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Mcp;

/**
 * Reads the summary and `@param` descriptions of an attributed method's
 * docblock (REQ-ATTR-001).
 */
final class ToolDocblock {

	/**
	 * Split a docblock into trimmed, marker-stripped lines.
	 *
	 * @param string|false $docComment The raw `ReflectionMethod::getDocComment()` value.
	 *
	 * @return list<string> Trimmed lines, in order, trailing blank lines removed.
	 */
	public static function lines(string|false $docComment): array {
		if ($docComment === false || trim($docComment) === '') {
			return [];
		}

		$rawLines = preg_split('/\r\n|\r|\n/', $docComment);
		if ($rawLines === false) {
			return [];
		}

		return self::withoutTrailingBlanks(lines: array_map([self::class, 'cleanLine'], $rawLines));
	}//end lines()

	/**
	 * The docblock summary — the first non-empty, non-tag line.
	 *
	 * @param list<string> $docLines Lines from {@see self::lines()}.
	 *
	 * @return string|null The summary line, or null when absent.
	 */
	public static function summary(array $docLines): ?string {
		foreach ($docLines as $line) {
			if ($line === '') {
				continue;
			}

			if (str_starts_with($line, '@') === true) {
				return null;
			}

			return $line;
		}

		return null;
	}//end summary()

	/**
	 * Every `@param $name description` tag, keyed by parameter name.
	 *
	 * @param list<string> $docLines Lines from {@see self::lines()}.
	 *
	 * @return array<string, string> Parameter name → description (may be empty string).
	 */
	public static function params(array $docLines): array {
		$params = [];

		foreach ($docLines as $line) {
			if (preg_match('/^@param\s+\S+\s+\$(\w+)(?:\s+(.*))?$/', $line, $matches) === 1) {
				$params[$matches[1]] = trim($matches[2] ?? '');
			}
		}

		return $params;
	}//end params()

	/**
	 * One docblock line without its comment markers.
	 *
	 * @param string $rawLine The raw line.
	 *
	 * @return string The cleaned line.
	 */
	public static function cleanLine(string $rawLine): string {
		$line = trim($rawLine);
		$line = preg_replace('#^/\*\*#', '', $line) ?? $line;
		$line = preg_replace('#\*/$#', '', $line) ?? $line;
		$line = ltrim($line, '* ');

		return rtrim($line);
	}//end cleanLine()

	/**
	 * The lines with trailing blank lines removed.
	 *
	 * @param list<string> $lines The lines.
	 *
	 * @return list<string> The lines.
	 */
	private static function withoutTrailingBlanks(array $lines): array {
		while ($lines !== [] && end($lines) === '') {
			array_pop($lines);
		}

		return $lines;
	}//end withoutTrailingBlanks()
}//end class
