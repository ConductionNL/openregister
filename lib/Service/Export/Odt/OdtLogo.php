<?php

/**
 * OdtLogo — a house-style logo checked and sized for the ODT page header.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * The bytes come from thematiq's document asset store. They are checked here
 * before they reach the package: at most 1 MB, PNG, JPEG or SVG by their
 * content (not by the declared type), and an SVG only when it carries no
 * script, no event handler, no entity declaration and no reference outside
 * itself. Anything else is refused, and the export keeps the plain header.
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

use DOMDocument;

/**
 * An embeddable logo: its bytes, type, package file name and frame size.
 *
 * @spec openspec/changes/export-pdf-house-style/specs/export-pdf-format/spec.md#requirement-a-pdf-export-carries-the-organisations-house-style
 */
final class OdtLogo {

	/**
	 * The largest logo embedded, in bytes.
	 *
	 * @var int
	 */
	public const MAX_BYTES = (1024 * 1024);

	/**
	 * The frame height in the page header, in centimetres.
	 *
	 * @var float
	 */
	public const HEIGHT_CM = 1.5;

	/**
	 * The widest frame in the page header, in centimetres.
	 *
	 * @var float
	 */
	public const MAX_WIDTH_CM = 6.0;

	/**
	 * Package file extension per accepted media type.
	 *
	 * @var array<string, string>
	 */
	private const EXTENSIONS = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/svg+xml' => 'svg'];

	/**
	 * Constructor.
	 *
	 * @param string $bytes    The image bytes.
	 * @param string $mime     The media type: image/png, image/jpeg or image/svg+xml.
	 * @param float  $widthCm  The frame width in centimetres.
	 * @param float  $heightCm The frame height in centimetres.
	 */
	private function __construct(
		public readonly string $bytes,
		public readonly string $mime,
		public readonly float $widthCm,
		public readonly float $heightCm,
	) {
	}//end __construct()

	/**
	 * A logo from image bytes, or null when they are too large, of another type, or unsafe.
	 *
	 * The type is detected from the bytes; the declared type is not trusted.
	 * Never throws.
	 *
	 * @param string $bytes The image bytes.
	 *
	 * @return self|null The logo, or null.
	 *
	 * @spec openspec/changes/export-pdf-house-style/specs/export-pdf-format/spec.md#requirement-a-pdf-export-carries-the-organisations-house-style
	 */
	public static function fromBytes(string $bytes): ?self {
		if ($bytes === '' || strlen($bytes) > self::MAX_BYTES) {
			return null;
		}

		$mime = self::detect(bytes: $bytes);
		if ($mime === null) {
			return null;
		}

		$size = self::pixelSize(bytes: $bytes, mime: $mime);
		if ($size === null) {
			return null;
		}

		[$width, $height] = $size;
		$heightCm = self::HEIGHT_CM;
		$widthCm = ($heightCm * $width / $height);
		if ($widthCm > self::MAX_WIDTH_CM) {
			$heightCm = ($heightCm * self::MAX_WIDTH_CM / $widthCm);
			$widthCm = self::MAX_WIDTH_CM;
		}

		return new self(bytes: $bytes, mime: $mime, widthCm: round($widthCm, 3), heightCm: round($heightCm, 3));
	}//end fromBytes()

	/**
	 * The path of the logo inside the ODT package.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/export-pdf-house-style/specs/export-pdf-format/spec.md#requirement-a-pdf-export-carries-the-organisations-house-style
	 */
	public function packagePath(): string {
		return 'Pictures/logo.' . self::EXTENSIONS[$this->mime];
	}//end packagePath()

	/**
	 * The media type of the bytes, or null when they are not a PNG, JPEG or safe SVG.
	 *
	 * @param string $bytes The bytes.
	 *
	 * @return string|null
	 */
	private static function detect(string $bytes): ?string {
		if (str_starts_with($bytes, "\x89PNG\r\n\x1a\n") === true) {
			return 'image/png';
		}

		if (str_starts_with($bytes, "\xFF\xD8\xFF") === true) {
			return 'image/jpeg';
		}

		if (self::isSafeSvg(bytes: $bytes) === true) {
			return 'image/svg+xml';
		}

		return null;
	}//end detect()

	/**
	 * Whether the bytes are a well-formed SVG with no active content and no outside reference.
	 *
	 * @param string $bytes The bytes.
	 *
	 * @return bool
	 */
	private static function isSafeSvg(string $bytes): bool {
		if (preg_match('/<!DOCTYPE|<!ENTITY|<script|<foreignObject|javascript:|\son[a-z]+\s*=/i', $bytes) === 1) {
			return false;
		}

		$previous = libxml_use_internal_errors(true);
		$dom = new DOMDocument();
		$loaded = $dom->loadXML($bytes, LIBXML_NONET);
		libxml_clear_errors();
		libxml_use_internal_errors($previous);
		if ($loaded === false || $dom->documentElement === null || $dom->documentElement->localName !== 'svg') {
			return false;
		}

		foreach ($dom->getElementsByTagName('*') as $element) {
			foreach ($element->attributes as $attribute) {
				if ($attribute->localName !== 'href') {
					continue;
				}

				$target = trim($attribute->value);
				if (str_starts_with($target, '#') === false && str_starts_with($target, 'data:image/') === false) {
					return false;
				}
			}
		}

		return true;
	}//end isSafeSvg()

	/**
	 * The intrinsic width and height of the image, or null when they cannot be read.
	 *
	 * @param string $bytes The bytes.
	 * @param string $mime  The detected type.
	 *
	 * @return array{0: float, 1: float}|null
	 */
	private static function pixelSize(string $bytes, string $mime): ?array {
		if ($mime !== 'image/svg+xml') {
			$info = @getimagesizefromstring($bytes);
			if ($info === false || $info[0] < 1 || $info[1] < 1) {
				return null;
			}

			return [(float) $info[0], (float) $info[1]];
		}

		$dom = new DOMDocument();
		$dom->loadXML($bytes, LIBXML_NONET);
		$root = $dom->documentElement;
		$width = self::length(value: $root->getAttribute('width'));
		$height = self::length(value: $root->getAttribute('height'));
		if ($width !== null && $height !== null) {
			return [$width, $height];
		}

		$box = preg_split('/[\s,]+/', trim($root->getAttribute('viewBox')));
		if (is_array($box) === true && count($box) === 4 && (float) $box[2] > 0 && (float) $box[3] > 0) {
			return [(float) $box[2], (float) $box[3]];
		}

		// An SVG without a size draws in a square frame.
		return [1.0, 1.0];
	}//end pixelSize()

	/**
	 * A positive SVG length in user units or pixels, or null for any other unit or value.
	 *
	 * @param string $value The attribute value.
	 *
	 * @return float|null
	 */
	private static function length(string $value): ?float {
		if (preg_match('/^\s*(\d+(?:\.\d+)?)\s*(px)?\s*$/', $value, $matches) !== 1 || (float) $matches[1] <= 0) {
			return null;
		}

		return (float) $matches[1];
	}//end length()
}//end class
