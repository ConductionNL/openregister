<?php

/**
 * Reads and writes baseline TIFF without a native codec.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\File
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/anonymisation-image-seam/specs/image-anonymisation/spec.md#requirement-decided-regions-are-burned-into-the-pixels-req-ais-003
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File;

/**
 * GD has no TIFF codec, and scans arrive as TIFF. This codec decodes the
 * baseline subset scanners write (strips, uncompressed, PackBits, LZW or
 * Deflate, 1 or 8 bits per sample, bilevel, grey, palette or RGB, with or
 * without an alpha sample) into 8-bit pixels, and encodes them back as
 * Deflate-compressed strips.
 *
 * The encoder writes only the tags it needs to describe the pixels plus the
 * resolution, so EXIF, GPS, XMP, IPTC, Photoshop resources, sub-IFDs and
 * every other private tag are gone from the output. Reduced-resolution
 * images (thumbnails, NewSubfileType bit 0) are dropped, not carried.
 *
 * Anything outside that subset (tiles, CCITT or JPEG compression, 16-bit or
 * planar samples, CMYK, BigTIFF) is refused rather than guessed at.
 *
 * A page is `{width, height, channels, pixels, resolution}`: `channels` is 1
 * (grey, 0 = black), 2 (grey + alpha), 3 (RGB) or 4 (RGB + alpha), and
 * `pixels` holds 8-bit samples, interleaved, row by row.
 *
 * @spec openspec/changes/anonymisation-image-seam/specs/image-anonymisation/spec.md#requirement-decided-regions-are-burned-into-the-pixels-req-ais-003
 */
class TiffCodec {

	/**
	 * The largest page decoded, in pixels, so a crafted header cannot exhaust memory.
	 */
	public const MAX_PIXELS = 100000000;

	/**
	 * The most pages read from one file.
	 */
	private const MAX_PAGES = 1000;

	/**
	 * Tag numbers.
	 */
	private const TAG_NEW_SUBFILE_TYPE = 254;
	private const TAG_SUBFILE_TYPE = 255;
	private const TAG_WIDTH = 256;
	private const TAG_HEIGHT = 257;
	private const TAG_BITS = 258;
	private const TAG_COMPRESSION = 259;
	private const TAG_PHOTOMETRIC = 262;
	private const TAG_FILL_ORDER = 266;
	private const TAG_STRIP_OFFSETS = 273;
	private const TAG_SAMPLES = 277;
	private const TAG_ROWS_PER_STRIP = 278;
	private const TAG_STRIP_BYTES = 279;
	private const TAG_X_RESOLUTION = 282;
	private const TAG_Y_RESOLUTION = 283;
	private const TAG_PLANAR = 284;
	private const TAG_RESOLUTION_UNIT = 296;
	private const TAG_PREDICTOR = 317;
	private const TAG_COLOR_MAP = 320;
	private const TAG_TILE_WIDTH = 322;
	private const TAG_EXTRA_SAMPLES = 338;

	/**
	 * Compression schemes.
	 */
	private const COMPRESSION_NONE = 1;
	private const COMPRESSION_LZW = 5;
	private const COMPRESSION_DEFLATE = 8;
	private const COMPRESSION_PACKBITS = 32773;
	private const COMPRESSION_DEFLATE_OLD = 32946;

	/**
	 * The photometric interpretation written for each channel count (grey or RGB).
	 */
	private const PHOTOMETRIC_BY_CHANNELS = [1 => 1, 2 => 1, 3 => 2, 4 => 2];

	/**
	 * The compression schemes this codec decodes.
	 */
	private const DECODABLE = [
		self::COMPRESSION_NONE,
		self::COMPRESSION_LZW,
		self::COMPRESSION_DEFLATE,
		self::COMPRESSION_DEFLATE_OLD,
		self::COMPRESSION_PACKBITS,
	];

	/**
	 * Byte sizes of the field types (BYTE, ASCII, SHORT, LONG, RATIONAL, ...).
	 */
	private const TYPE_SIZES = [1 => 1, 2 => 1, 3 => 2, 4 => 4, 5 => 8, 6 => 1, 7 => 1, 8 => 2, 9 => 4, 10 => 8, 11 => 4, 12 => 8];

	/**
	 * Whether the bytes start like a (classic) TIFF.
	 *
	 * @param string $bytes The file.
	 *
	 * @return bool True for a little- or big-endian TIFF header.
	 *
	 * @spec openspec/changes/anonymisation-image-seam/specs/image-anonymisation/spec.md#requirement-decided-regions-are-burned-into-the-pixels-req-ais-003
	 */
	public static function looksLikeTiff(string $bytes): bool {
		$head = substr($bytes, 0, 4);
		return $head === "II*\x00" || $head === "MM\x00*";
	}//end looksLikeTiff()

	/**
	 * Decode every full-resolution page.
	 *
	 * @param string $bytes    The TIFF.
	 * @param string $fileName The file, for messages.
	 *
	 * @return list<array{width: int, height: int, channels: int, pixels: string, resolution: array<int, int|array{0: int, 1: int}>}> The pages.
	 *
	 * @throws ImageRegionNotBurnableException When the file is corrupt or outside the baseline subset.
	 *
	 * @spec openspec/changes/anonymisation-image-seam/specs/image-anonymisation/spec.md#requirement-decided-regions-are-burned-into-the-pixels-req-ais-003
	 */
	public function decode(string $bytes, string $fileName): array {
		if (self::looksLikeTiff(bytes: $bytes) === false) {
			throw new ImageRegionNotBurnableException(
				fileName: $fileName,
				reason: 'the image is not a TIFF this instance can read (BigTIFF and corrupt headers are refused).'
			);
		}

		$little = ($bytes[0] === 'I');
		$offset = $this->unsigned(bytes: $bytes, at: 4, size: 4, little: $little, fileName: $fileName);
		$pages = [];
		$seen = [];
		while ($offset !== 0) {
			if (isset($seen[$offset]) === true || count($seen) >= self::MAX_PAGES) {
				throw new ImageRegionNotBurnableException(fileName: $fileName, reason: 'the TIFF directory chain loops or is too long.');
			}

			$seen[$offset] = true;
			$ifd = $this->readDirectory(bytes: $bytes, offset: $offset, little: $little, fileName: $fileName);
			$reduced = ((int)($ifd['tags'][self::TAG_NEW_SUBFILE_TYPE][0] ?? 0) & 1) === 1
				|| (int)($ifd['tags'][self::TAG_SUBFILE_TYPE][0] ?? 1) === 2;
			if ($reduced === false) {
				$pages[] = $this->decodePage(bytes: $bytes, tags: $ifd['tags'], fileName: $fileName);
			}

			$offset = $ifd['next'];
		}

		if ($pages === []) {
			throw new ImageRegionNotBurnableException(fileName: $fileName, reason: 'the TIFF holds no full-resolution page.');
		}

		return $pages;
	}//end decode()

	/**
	 * Encode the pages as a little-endian TIFF with Deflate strips.
	 *
	 * @param list<array{width: int, height: int, channels: int, pixels: string, resolution?: array<int, int|array{0: int, 1: int}>}> $pages The pages.
	 *
	 * @return string The TIFF.
	 *
	 * @spec openspec/changes/anonymisation-image-seam/specs/image-anonymisation/spec.md#requirement-decided-regions-are-burned-into-the-pixels-req-ais-003
	 */
	public function encode(array $pages): string {
		$out = "II*\x00" . pack('V', 0);
		$pointer = 4;
		foreach ($pages as $page) {
			$strip = (string)gzcompress($page['pixels'], 6);
			$stripOffset = strlen($out);
			$out .= $strip;
			if ((strlen($out) % 2) === 1) {
				$out .= "\x00";
			}

			$channels = $page['channels'];
			$entries = [
				self::TAG_WIDTH => [4, [$page['width']]],
				self::TAG_HEIGHT => [4, [$page['height']]],
				self::TAG_BITS => [3, array_fill(0, $channels, 8)],
				self::TAG_COMPRESSION => [3, [self::COMPRESSION_DEFLATE]],
				self::TAG_PHOTOMETRIC => [3, [self::PHOTOMETRIC_BY_CHANNELS[$channels]]],
				self::TAG_STRIP_OFFSETS => [4, [$stripOffset]],
				self::TAG_SAMPLES => [3, [$channels]],
				self::TAG_ROWS_PER_STRIP => [4, [$page['height']]],
				self::TAG_STRIP_BYTES => [4, [strlen($strip)]],
				self::TAG_PLANAR => [3, [1]],
			];
			foreach (($page['resolution'] ?? []) as $tag => $value) {
				if ($tag === self::TAG_RESOLUTION_UNIT) {
					$entries[$tag] = [3, [(int)$value]];
				} else if (is_array($value) === true) {
					$entries[$tag] = [5, [$value]];
				}
			}

			if ($channels === 2 || $channels === 4) {
				$entries[self::TAG_EXTRA_SAMPLES] = [3, [2]];
			}

			ksort($entries);
			$directoryOffset = strlen($out);
			$out = substr_replace($out, pack('V', $directoryOffset), $pointer, 4);
			$out .= $this->directory(entries: $entries, at: $directoryOffset);
			$pointer = ($directoryOffset + 2 + (12 * count($entries)));
		}//end foreach

		return $out;
	}//end encode()

	/**
	 * Serialise one directory, its out-of-line values after the next-IFD pointer.
	 *
	 * @param array<int, array{0: int, 1: list<int|array{0: int, 1: int}>}> $entries The tags, sorted.
	 * @param int                                                          $at      Where the directory starts.
	 *
	 * @return string The directory, ending with a zero next-IFD pointer, then its values.
	 */
	private function directory(array $entries, int $at): string {
		$head = pack('v', count($entries));
		$extra = '';
		$extraAt = ($at + 2 + (12 * count($entries)) + 4);
		foreach ($entries as $tag => [$type, $values]) {
			$data = '';
			foreach ($values as $value) {
				if ($type === 3) {
					$data .= pack('v', (int)$value);
				} else if ($type === 4) {
					$data .= pack('V', (int)$value);
				} else {
					$data .= pack('VV', $value[0], $value[1]);
				}
			}

			$head .= pack('vvV', $tag, $type, count($values));
			if (strlen($data) <= 4) {
				$head .= str_pad($data, 4, "\x00");
				continue;
			}

			$head .= pack('V', ($extraAt + strlen($extra)));
			$extra .= $data;
		}

		return $head . pack('V', 0) . $extra;
	}//end directory()

	/**
	 * Read one directory's tags and the offset of the next.
	 *
	 * @param string $bytes    The TIFF.
	 * @param int    $offset   The directory offset.
	 * @param bool   $little   Little-endian.
	 * @param string $fileName The file, for messages.
	 *
	 * @return array{tags: array<int, list<int|array{0: int, 1: int}>>, next: int} The tags and the next offset.
	 */
	private function readDirectory(string $bytes, int $offset, bool $little, string $fileName): array {
		$count = $this->unsigned(bytes: $bytes, at: $offset, size: 2, little: $little, fileName: $fileName);
		$tags = [];
		for ($i = 0; $i < $count; $i++) {
			$entry = ($offset + 2 + (12 * $i));
			$tag = $this->unsigned(bytes: $bytes, at: $entry, size: 2, little: $little, fileName: $fileName);
			$type = $this->unsigned(bytes: $bytes, at: ($entry + 2), size: 2, little: $little, fileName: $fileName);
			$number = $this->unsigned(bytes: $bytes, at: ($entry + 4), size: 4, little: $little, fileName: $fileName);
			$size = (self::TYPE_SIZES[$type] ?? 0);
			if ($size === 0 || in_array($type, [3, 4, 5], true) === false) {
				continue;
			}

			$at = ($entry + 8);
			if (($size * $number) > 4) {
				$at = $this->unsigned(bytes: $bytes, at: $at, size: 4, little: $little, fileName: $fileName);
			}

			if (($at + ($size * $number)) > strlen($bytes)) {
				throw new ImageRegionNotBurnableException(fileName: $fileName, reason: sprintf('TIFF tag %d points past the end of the file.', $tag));
			}

			$values = [];
			for ($n = 0; $n < $number; $n++) {
				if ($type === 5) {
					$values[] = [
						$this->unsigned(bytes: $bytes, at: ($at + (8 * $n)), size: 4, little: $little, fileName: $fileName),
						$this->unsigned(bytes: $bytes, at: ($at + (8 * $n) + 4), size: 4, little: $little, fileName: $fileName),
					];
					continue;
				}

				$values[] = $this->unsigned(bytes: $bytes, at: ($at + ($size * $n)), size: $size, little: $little, fileName: $fileName);
			}

			$tags[$tag] = $values;
		}//end for

		$next = $this->unsigned(bytes: $bytes, at: ($offset + 2 + (12 * $count)), size: 4, little: $little, fileName: $fileName);
		return ['tags' => $tags, 'next' => $next];
	}//end readDirectory()

	/**
	 * Decode one page into 8-bit interleaved samples.
	 *
	 * @param string                                          $bytes    The TIFF.
	 * @param array<int, list<int|array{0: int, 1: int}>>     $tags     Its directory.
	 * @param string                                          $fileName The file, for messages.
	 *
	 * @return array{width: int, height: int, channels: int, pixels: string, resolution: array<int, int|array{0: int, 1: int}>} The page.
	 */
	private function decodePage(string $bytes, array $tags, string $fileName): array {
		$width = (int)($tags[self::TAG_WIDTH][0] ?? 0);
		$height = (int)($tags[self::TAG_HEIGHT][0] ?? 0);
		$samples = (int)($tags[self::TAG_SAMPLES][0] ?? 1);
		$bits = (int)($tags[self::TAG_BITS][0] ?? 1);
		$compression = (int)($tags[self::TAG_COMPRESSION][0] ?? self::COMPRESSION_NONE);
		$photometric = (int)($tags[self::TAG_PHOTOMETRIC][0] ?? -1);
		$predictor = (int)($tags[self::TAG_PREDICTOR][0] ?? 1);
		$refuse = static fn (string $why): ImageRegionNotBurnableException => new ImageRegionNotBurnableException(fileName: $fileName, reason: $why);

		if ($width < 1 || $height < 1 || ($width * $height) > self::MAX_PIXELS) {
			throw $refuse(sprintf('the TIFF page is %dx%d pixels, outside what can be burned.', $width, $height));
		}

		if (isset($tags[self::TAG_TILE_WIDTH]) === true) {
			throw $refuse('tiled TIFF images cannot be burned on this instance.');
		}

		if ((int)($tags[self::TAG_PLANAR][0] ?? 1) !== 1 || (int)($tags[self::TAG_FILL_ORDER][0] ?? 1) !== 1) {
			throw $refuse('planar or bit-reversed TIFF images cannot be burned on this instance.');
		}

		foreach (($tags[self::TAG_BITS] ?? [1]) as $sampleBits) {
			if ($sampleBits !== $bits) {
				throw $refuse('TIFF samples of mixed bit depth cannot be burned on this instance.');
			}
		}

		$colour = $this->colourModel(photometric: $photometric, samples: $samples, bits: $bits, tags: $tags);
		if ($colour === null) {
			throw $refuse(sprintf('TIFF photometric %d with %d samples of %d bits cannot be burned on this instance.', $photometric, $samples, $bits));
		}

		if (in_array($compression, self::DECODABLE, true) === false) {
			throw $refuse(sprintf('TIFF compression %d cannot be burned on this instance.', $compression));
		}

		if ($predictor !== 1 && ($predictor !== 2 || $bits !== 8)) {
			throw $refuse(sprintf('TIFF predictor %d cannot be burned on this instance.', $predictor));
		}

		$rowBytes = intdiv((($width * $samples * $bits) + 7), 8);
		$rowsPerStrip = min($height, max(1, (int)($tags[self::TAG_ROWS_PER_STRIP][0] ?? $height)));
		$offsets = ($tags[self::TAG_STRIP_OFFSETS] ?? []);
		$counts = ($tags[self::TAG_STRIP_BYTES] ?? []);
		if ($offsets === [] || count($offsets) !== count($counts) || count($offsets) < (int)ceil($height / $rowsPerStrip)) {
			throw $refuse('the TIFF strips are missing or do not cover the page.');
		}

		$raw = '';
		foreach ($offsets as $i => $stripOffset) {
			$stripRows = min($rowsPerStrip, ($height - ($i * $rowsPerStrip)));
			if ($stripRows <= 0) {
				break;
			}

			$data = substr($bytes, (int)$stripOffset, (int)$counts[$i]);
			if (strlen($data) !== (int)$counts[$i]) {
				throw $refuse('a TIFF strip runs past the end of the file.');
			}

			$expected = ($stripRows * $rowBytes);
			$strip = $this->decompress(data: $data, compression: $compression, expected: $expected);
			if ($strip === null || strlen($strip) < $expected) {
				throw $refuse('a TIFF strip is corrupt.');
			}

			$strip = substr($strip, 0, $expected);
			if ($predictor === 2) {
				$strip = $this->undoPredictor(data: $strip, rowBytes: $rowBytes, samples: $samples);
			}

			$raw .= $strip;
		}//end foreach

		$resolution = [];
		foreach ([self::TAG_X_RESOLUTION, self::TAG_Y_RESOLUTION] as $tag) {
			$value = ($tags[$tag][0] ?? null);
			if (is_array($value) === true && $value[1] !== 0) {
				$resolution[$tag] = $value;
			}
		}

		if (isset($tags[self::TAG_RESOLUTION_UNIT][0]) === true) {
			$resolution[self::TAG_RESOLUTION_UNIT] = (int)$tags[self::TAG_RESOLUTION_UNIT][0];
		}

		return [
			'width' => $width,
			'height' => $height,
			'channels' => $colour['channels'],
			'pixels' => $this->normalise(raw: $raw, width: $width, height: $height, rowBytes: $rowBytes, colour: $colour),
			'resolution' => $resolution,
		];
	}//end decodePage()

	/**
	 * How the stored samples map to the decoded channels, or null when unsupported.
	 *
	 * @param int                                         $photometric The photometric interpretation.
	 * @param int                                         $samples     Samples per pixel.
	 * @param int                                         $bits        Bits per sample.
	 * @param array<int, list<int|array{0: int, 1: int}>> $tags        The directory, for the colour map and extra samples.
	 *
	 * @return array{kind: string, samples: int, bits: int, channels: int, palette?: list<int>}|null The model.
	 */
	private function colourModel(int $photometric, int $samples, int $bits, array $tags): ?array {
		$alpha = isset($tags[self::TAG_EXTRA_SAMPLES]) === true && in_array((int)$tags[self::TAG_EXTRA_SAMPLES][0], [1, 2], true);
		if (($photometric === 0 || $photometric === 1) && ($bits === 1 || $bits === 8)
			&& ($samples === 1 || ($samples === 2 && $bits === 8 && $alpha === true))
		) {
			$kind = 'grey';
			if ($photometric === 0) {
				$kind = 'white-is-zero';
			}

			return ['kind' => $kind, 'samples' => $samples, 'bits' => $bits, 'channels' => $samples];
		}

		if ($photometric === 2 && $bits === 8 && ($samples === 3 || ($samples === 4 && $alpha === true))) {
			return ['kind' => 'rgb', 'samples' => $samples, 'bits' => 8, 'channels' => $samples];
		}

		$map = ($tags[self::TAG_COLOR_MAP] ?? []);
		if ($photometric === 3 && $samples === 1 && $bits === 8 && count($map) === 768) {
			return ['kind' => 'palette', 'samples' => 1, 'bits' => 8, 'channels' => 3, 'palette' => array_map('intval', $map)];
		}

		return null;
	}//end colourModel()

	/**
	 * Convert stored samples to 8-bit grey (0 = black) or RGB, alpha kept.
	 *
	 * @param string                                                                          $raw      The decompressed rows.
	 * @param int                                                                             $width    Page width.
	 * @param int                                                                             $height   Page height.
	 * @param int                                                                             $rowBytes Bytes per stored row.
	 * @param array{kind: string, samples: int, bits: int, channels: int, palette?: list<int>} $colour   The model.
	 *
	 * @return string The normalised pixels.
	 */
	private function normalise(string $raw, int $width, int $height, int $rowBytes, array $colour): string {
		if ($colour['bits'] === 1) {
			$out = '';
			$white = 1;
			if ($colour['kind'] === 'white-is-zero') {
				$white = 0;
			}

			$shades = [0 => "\x00", 1 => "\x00"];
			$shades[$white] = "\xFF";
			for ($y = 0; $y < $height; $y++) {
				for ($x = 0; $x < $width; $x++) {
					$bit = ((ord($raw[($y * $rowBytes) + intdiv($x, 8)]) >> (7 - ($x % 8))) & 1);
					$out .= $shades[$bit];
				}
			}

			return $out;
		}

		if ($colour['kind'] === 'white-is-zero') {
			$out = '';
			$length = strlen($raw);
			for ($i = 0; $i < $length; $i++) {
				if ($colour['samples'] === 2 && ($i % 2) === 1) {
					$out .= $raw[$i];
					continue;
				}

				$out .= chr(255 - ord($raw[$i]));
			}

			return $out;
		}

		if ($colour['kind'] === 'palette') {
			$palette = ($colour['palette'] ?? []);
			$lookup = [];
			for ($i = 0; $i < 256; $i++) {
				$lookup[$i] = chr($palette[$i] >> 8) . chr($palette[(256 + $i)] >> 8) . chr($palette[(512 + $i)] >> 8);
			}

			return strtr($raw, array_combine(array_map('chr', range(0, 255)), $lookup));
		}

		return $raw;
	}//end normalise()

	/**
	 * Undo horizontal differencing on 8-bit rows.
	 *
	 * @param string $data     The rows.
	 * @param int    $rowBytes Bytes per row.
	 * @param int    $samples  Samples per pixel.
	 *
	 * @return string The rows.
	 */
	private function undoPredictor(string $data, int $rowBytes, int $samples): string {
		$length = strlen($data);
		for ($row = 0; $row < $length; $row += $rowBytes) {
			for ($i = $samples; $i < $rowBytes; $i++) {
				$data[($row + $i)] = chr((ord($data[($row + $i)]) + ord($data[($row + $i - $samples)])) & 0xFF);
			}
		}

		return $data;
	}//end undoPredictor()

	/**
	 * Decompress one strip.
	 *
	 * @param string $data        The stored strip.
	 * @param int    $compression The scheme.
	 * @param int    $expected    Bytes the strip decodes to.
	 *
	 * @return string|null The decoded bytes, or null when corrupt.
	 */
	private function decompress(string $data, int $compression, int $expected): ?string {
		if ($compression === self::COMPRESSION_NONE) {
			return $data;
		}

		if ($compression === self::COMPRESSION_PACKBITS) {
			return $this->unpackBits(data: $data, expected: $expected);
		}

		if ($compression === self::COMPRESSION_LZW) {
			return $this->unLzw(data: $data, expected: $expected);
		}

		$out = @gzuncompress($data);
		if ($out === false) {
			$out = @gzinflate($data);
		}

		if ($out === false) {
			return null;
		}

		return $out;
	}//end decompress()

	/**
	 * Decode PackBits.
	 *
	 * @param string $data     The stored strip.
	 * @param int    $expected Bytes the strip decodes to.
	 *
	 * @return string|null The decoded bytes, or null when corrupt.
	 */
	private function unpackBits(string $data, int $expected): ?string {
		$out = '';
		$length = strlen($data);
		$i = 0;
		while ($i < $length && strlen($out) < $expected) {
			$n = ord($data[$i++]);
			if ($n < 128) {
				if (($i + $n + 1) > $length) {
					return null;
				}

				$out .= substr($data, $i, ($n + 1));
				$i += ($n + 1);
			} else if ($n > 128) {
				if ($i >= $length) {
					return null;
				}

				$out .= str_repeat($data[$i++], (257 - $n));
			}
		}

		return $out;
	}//end unpackBits()

	/**
	 * Decode TIFF LZW (MSB-first codes, early change).
	 *
	 * @param string $data     The stored strip.
	 * @param int    $expected Bytes the strip decodes to.
	 *
	 * @return string|null The decoded bytes, or null when corrupt.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) One LZW state machine reads best in one place.
	 */
	private function unLzw(string $data, int $expected): ?string {
		$table = [];
		$resetTable = static function () use (&$table): void {
			$table = array_map('chr', range(0, 255));
			$table[256] = '';
			$table[257] = '';
		};
		$resetTable();
		$width = 9;
		$buffer = 0;
		$held = 0;
		$previous = null;
		$out = '';
		$length = strlen($data);
		for ($i = 0; $i < $length && strlen($out) < $expected; $i++) {
			$buffer = ((($buffer << 8) | ord($data[$i])) & 0xFFFFFF);
			$held += 8;
			while ($held >= $width) {
				$held -= $width;
				$code = (($buffer >> $held) & ((1 << $width) - 1));
				if ($code === 257) {
					return $out;
				}

				if ($code === 256) {
					$resetTable();
					$width = 9;
					$previous = null;
					continue;
				}

				if (isset($table[$code]) === true) {
					$entry = $table[$code];
				} else if ($previous !== null && $code === count($table)) {
					$entry = $previous . $previous[0];
				} else {
					return null;
				}

				$out .= $entry;
				if ($previous !== null && count($table) < 4096) {
					$table[] = $previous . $entry[0];
				}

				$previous = $entry;
				$next = (count($table) + 1);
				if ($next >= 2048) {
					$width = 12;
				} else if ($next >= 1024) {
					$width = 11;
				} else if ($next >= 512) {
					$width = 10;
				}
			}//end while
		}//end for

		return $out;
	}//end unLzw()

	/**
	 * Read an unsigned integer.
	 *
	 * @param string $bytes    The TIFF.
	 * @param int    $at       The offset.
	 * @param int    $size     1, 2 or 4 bytes.
	 * @param bool   $little   Little-endian.
	 * @param string $fileName The file, for messages.
	 *
	 * @return int The value.
	 *
	 * @throws ImageRegionNotBurnableException When the offset is past the end.
	 */
	private function unsigned(string $bytes, int $at, int $size, bool $little, string $fileName): int {
		if ($at < 0 || ($at + $size) > strlen($bytes)) {
			throw new ImageRegionNotBurnableException(fileName: $fileName, reason: 'the TIFF is truncated.');
		}

		$chunk = substr($bytes, $at, $size);
		if ($size === 1) {
			return ord($chunk);
		}

		$formats = ['2' => ['v', 'n'], '4' => ['V', 'N']];
		$format = $formats[(string)$size][(int)($little === false)];
		return (int)unpack($format, $chunk)[1];
	}//end unsigned()
}//end class
