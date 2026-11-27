<?php

/**
 * OdtFont — a house-style font checked for embedding in the ODT package.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * The office server that converts the ODT to PDF reads embedded fonts as
 * TrueType or OpenType files. A font is accepted here only as one of those, or
 * as WOFF 1.0, which is unwrapped to the font it carries (each table is either
 * stored or zlib-compressed). WOFF2 needs a Brotli decoder and a glyph-table
 * reconstruction this export does not carry, so a WOFF2 font is refused, as is
 * anything larger than {@see MAX_BYTES}, a font collection, or bytes that are not
 * a font at all. A refused font leaves that role on the export's default font.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Export\Odt
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/export-pdf-house-style/specs/export-pdf-format/spec.md#requirement-a-pdf-export-carries-the-organisations-house-style
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Export\Odt;

/**
 * An embeddable font: its role, family name, sfnt bytes and format.
 *
 * @spec openspec/changes/export-pdf-house-style/specs/export-pdf-format/spec.md#requirement-a-pdf-export-carries-the-organisations-house-style
 */
final class OdtFont {

	/**
	 * The largest font embedded, in bytes, before and after unwrapping.
	 *
	 * @var int
	 */
	public const MAX_BYTES = (5 * 1024 * 1024);

	/**
	 * The role a font is used for in the headings.
	 *
	 * @var string
	 */
	public const ROLE_HEADING = 'heading';

	/**
	 * The role a font is used for in the body text and tables.
	 *
	 * @var string
	 */
	public const ROLE_BODY = 'body';

	/**
	 * A family name is cut to this many characters.
	 *
	 * @var int
	 */
	public const MAX_FAMILY_LENGTH = 64;

	/**
	 * Constructor.
	 *
	 * @param string $role   `heading` or `body`.
	 * @param string $family The family name the styles refer to.
	 * @param string $bytes  The TrueType or OpenType bytes.
	 * @param string $format `truetype` or `opentype`.
	 */
	private function __construct(
		public readonly string $role,
		public readonly string $family,
		public readonly string $bytes,
		public readonly string $format,
	) {
	}//end __construct()

	/**
	 * A font from its bytes, or null when they cannot be embedded.
	 *
	 * The type is detected from the bytes; a declared type or file name is not
	 * trusted. Never throws.
	 *
	 * @param string $role   `heading` or `body`.
	 * @param mixed  $family The profile's family: a name or a CSS font stack, whose first family is used.
	 * @param string $bytes  The font file's bytes.
	 *
	 * @return self|null The font, or null.
	 *
	 * @spec openspec/changes/export-pdf-house-style/specs/export-pdf-format/spec.md#requirement-a-pdf-export-carries-the-organisations-house-style
	 */
	public static function fromBytes(string $role, mixed $family, string $bytes): ?self {
		if ($role !== self::ROLE_HEADING && $role !== self::ROLE_BODY) {
			return null;
		}

		$name = self::familyName(value: $family);
		if ($name === null || $bytes === '' || strlen($bytes) > self::MAX_BYTES) {
			return null;
		}

		if (strncmp($bytes, 'wOFF', 4) === 0) {
			$bytes = self::unwrapWoff(bytes: $bytes);
			if ($bytes === null) {
				return null;
			}
		}

		$format = self::sfntFormat(bytes: $bytes);
		if ($format === null) {
			return null;
		}

		return new self(role: $role, family: $name, bytes: $bytes, format: $format);
	}//end fromBytes()

	/**
	 * The path of the font inside the ODT package.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/export-pdf-house-style/specs/export-pdf-format/spec.md#requirement-a-pdf-export-carries-the-organisations-house-style
	 */
	public function packagePath(): string {
		$extension = 'ttf';
		if ($this->format === 'opentype') {
			$extension = 'otf';
		}

		return 'Fonts/' . $this->role . '.' . $extension;
	}//end packagePath()

	/**
	 * The media type the package manifest records for the font.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/export-pdf-house-style/specs/export-pdf-format/spec.md#requirement-a-pdf-export-carries-the-organisations-house-style
	 */
	public function mediaType(): string {
		if ($this->format === 'opentype') {
			return 'application/vnd.ms-opentype';
		}

		return 'application/x-font-ttf';
	}//end mediaType()

	/**
	 * The first family of a name or CSS font stack, unquoted, or null when there is none.
	 *
	 * Only letters, digits, spaces, hyphens and underscores are kept, so the
	 * name can never break out of the attribute it is written into.
	 *
	 * @param mixed $value The profile value.
	 *
	 * @return string|null
	 */
	private static function familyName(mixed $value): ?string {
		if (is_string($value) === false) {
			return null;
		}

		$first = explode(',', $value)[0];
		$name = trim((string) preg_replace('/[^\p{L}\p{N} _-]+/u', '', $first));
		$name = trim((string) preg_replace('/\s+/u', ' ', $name));
		if ($name === '') {
			return null;
		}

		return mb_substr($name, 0, self::MAX_FAMILY_LENGTH);
	}//end familyName()

	/**
	 * `truetype` or `opentype` for a single sfnt font, or null for anything else.
	 *
	 * @param string $bytes The bytes.
	 *
	 * @return string|null
	 */
	private static function sfntFormat(string $bytes): ?string {
		if (strlen($bytes) < 12) {
			return null;
		}

		$tag = substr($bytes, 0, 4);
		if ($tag === "\x00\x01\x00\x00" || $tag === 'true') {
			$format = 'truetype';
		} else if ($tag === 'OTTO') {
			$format = 'opentype';
		} else {
			return null;
		}

		$tables = unpack('n', $bytes, 4)[1];
		if ($tables < 1 || strlen($bytes) < (12 + (16 * $tables))) {
			return null;
		}

		return $format;
	}//end sfntFormat()

	/**
	 * The sfnt font a WOFF 1.0 file carries, or null when the file is malformed.
	 *
	 * Rebuilds the offset table and table directory, and copies each table,
	 * inflating the compressed ones, padded to four bytes.
	 *
	 * @param string $bytes The WOFF bytes.
	 *
	 * @return string|null
	 */
	private static function unwrapWoff(string $bytes): ?string {
		$size = strlen($bytes);
		if ($size < 44) {
			return null;
		}

		$header = unpack('Nflavor/Nlength/nnumTables/nreserved/NtotalSfntSize', $bytes, 4);
		$count = $header['numTables'];
		if ($header['length'] !== $size || $count < 1 || $header['totalSfntSize'] > self::MAX_BYTES
			|| $size < (44 + (20 * $count))
		) {
			return null;
		}

		$entries = [];
		for ($index = 0; $index < $count; $index++) {
			$entry = unpack('a4tag/Noffset/NcompLength/NorigLength/NorigChecksum', $bytes, (44 + (20 * $index)));
			$data = self::woffTable(bytes: $bytes, entry: $entry);
			if ($data === null) {
				return null;
			}

			$entries[] = ['tag' => $entry['tag'], 'checksum' => $entry['origChecksum'], 'data' => $data];
		}

		$power = 1;
		$selector = 0;
		while (($power * 2) <= $count) {
			$power *= 2;
			$selector++;
		}

		$directory = pack('Nnnnn', $header['flavor'], $count, ($power * 16), $selector, (($count * 16) - ($power * 16)));
		$body = '';
		$offset = (12 + (16 * $count));
		foreach ($entries as $entry) {
			$length = strlen($entry['data']);
			$directory .= $entry['tag'] . pack('NNN', $entry['checksum'], $offset, $length);
			$padded = $entry['data'] . str_repeat("\x00", ((4 - ($length % 4)) % 4));
			$body .= $padded;
			$offset += strlen($padded);
		}

		$font = $directory . $body;
		if (strlen($font) > self::MAX_BYTES) {
			return null;
		}

		return $font;
	}//end unwrapWoff()

	/**
	 * One WOFF table's original bytes, or null when it is out of bounds or does not inflate to its stated length.
	 *
	 * @param string              $bytes The WOFF bytes.
	 * @param array<string, mixed> $entry The table directory entry.
	 *
	 * @return string|null
	 */
	private static function woffTable(string $bytes, array $entry): ?string {
		$offset = $entry['offset'];
		$compLength = $entry['compLength'];
		$origLength = $entry['origLength'];
		if ($compLength > $origLength || $origLength > self::MAX_BYTES || ($offset + $compLength) > strlen($bytes)) {
			return null;
		}

		$data = substr($bytes, $offset, $compLength);
		if ($compLength < $origLength) {
			$data = @zlib_decode($data, $origLength);
			if ($data === false) {
				return null;
			}
		}

		if (strlen($data) !== $origLength) {
			return null;
		}

		return $data;
	}//end woffTable()
}//end class
