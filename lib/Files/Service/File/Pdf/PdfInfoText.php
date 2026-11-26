<?php

/**
 * OpenRegister PDF /Info text codec
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Files\Service\File\Pdf
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/changes/redaction-release-safeguards/specs/redaction-output-guarantee/spec.md#requirement-a-value-inside-a-metadata-field-is-masked-and-unmaskable-fields-are-named-req-rrs-004
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Service\File\Pdf;

use ddn\sapp\pdfvalue\PDFValueHexString;
use ddn\sapp\pdfvalue\PDFValueString;

/**
 * Reads a PDF text string (literal or hex, PDFDocEncoding or UTF-16BE with
 * its byte order mark) as UTF-8, and writes UTF-8 back as a PDF string, so
 * an /Info field can be masked as text rather than as escaped bytes.
 *
 * @spec openspec/changes/redaction-release-safeguards/specs/redaction-output-guarantee/spec.md#requirement-a-value-inside-a-metadata-field-is-masked-and-unmaskable-fields-are-named-req-rrs-004
 */
final class PdfInfoText {

	/**
	 * The UTF-16BE byte order mark that opens a Unicode PDF text string.
	 */
	private const BOM = "\xFE\xFF";

	/**
	 * The single-character escapes of a literal string.
	 */
	private const ESCAPES = [
		'n' => "\n",
		'r' => "\r",
		't' => "\t",
		'b' => "\x08",
		'f' => "\x0C",
		'(' => '(',
		')' => ')',
		'\\' => '\\',
	];

	/**
	 * The text of a PDF string value, as UTF-8; null for any other value.
	 *
	 * @param mixed $value The /Info entry.
	 *
	 * @return string|null The text, or null when the entry is not a string.
	 *
	 * @spec openspec/changes/redaction-release-safeguards/specs/redaction-output-guarantee/spec.md#requirement-a-value-inside-a-metadata-field-is-masked-and-unmaskable-fields-are-named-req-rrs-004
	 */
	public static function read(mixed $value): ?string {
		if ($value instanceof PDFValueHexString) {
			$hex = preg_replace('/[^0-9A-Fa-f]/', '', (string) $value->val());
			if (strlen($hex) % 2 === 1) {
				$hex .= '0';
			}

			return self::decodeBytes(bytes: (string) hex2bin($hex));
		}

		if ($value instanceof PDFValueString) {
			return self::decodeBytes(bytes: self::unescape(literal: (string) $value->val()));
		}

		return null;
	}//end read()

	/**
	 * A PDF string value holding the UTF-8 text: a literal for ASCII text,
	 * else a hex string in UTF-16BE with its byte order mark.
	 *
	 * @param string $text The UTF-8 text.
	 *
	 * @return PDFValueString The value to store.
	 *
	 * @spec openspec/changes/redaction-release-safeguards/specs/redaction-output-guarantee/spec.md#requirement-a-value-inside-a-metadata-field-is-masked-and-unmaskable-fields-are-named-req-rrs-004
	 */
	public static function write(string $text): PDFValueString {
		if (preg_match('/^[\x20-\x7E]*$/', $text) === 1) {
			return new PDFValueString(strtr($text, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']));
		}

		return new PDFValueHexString(strtoupper(bin2hex(self::BOM . mb_convert_encoding($text, 'UTF-16BE', 'UTF-8'))));
	}//end write()

	/**
	 * The bytes of a literal string body, its escapes resolved.
	 *
	 * @param string $literal The body between the parentheses.
	 *
	 * @return string The bytes.
	 */
	private static function unescape(string $literal): string {
		$bytes  = '';
		$length = strlen($literal);
		for ($i = 0; $i < $length; $i++) {
			$char = $literal[$i];
			if ($char !== '\\' || $i + 1 >= $length) {
				$bytes .= $char;
				continue;
			}

			$next = $literal[++$i];
			if (isset(self::ESCAPES[$next]) === true) {
				$bytes .= self::ESCAPES[$next];
				continue;
			}

			if ($next >= '0' && $next <= '7') {
				$octal = $next;
				while (strlen($octal) < 3 && $i + 1 < $length && $literal[$i + 1] >= '0' && $literal[$i + 1] <= '7') {
					$octal .= $literal[++$i];
				}

				$bytes .= chr(octdec($octal) & 0xFF);
				continue;
			}

			// A backslash before a line end continues the line; before any
			// other character it is ignored.
			if ($next === "\r" && $i + 1 < $length && $literal[$i + 1] === "\n") {
				$i++;
				continue;
			}

			if ($next !== "\n" && $next !== "\r") {
				$bytes .= $next;
			}
		}//end for

		return $bytes;
	}//end unescape()

	/**
	 * UTF-8 text from string bytes: UTF-16BE after a byte order mark, else
	 * PDFDocEncoding read as Latin-1 (identical for printable ASCII).
	 *
	 * @param string $bytes The string's bytes.
	 *
	 * @return string The UTF-8 text.
	 */
	private static function decodeBytes(string $bytes): string {
		if (str_starts_with($bytes, self::BOM) === true) {
			return (string) mb_convert_encoding(substr($bytes, 2), 'UTF-8', 'UTF-16BE');
		}

		return (string) mb_convert_encoding($bytes, 'UTF-8', 'ISO-8859-1');
	}//end decodeBytes()
}//end class
