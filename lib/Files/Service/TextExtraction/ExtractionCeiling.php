<?php

/**
 * ExtractionCeiling: the largest file text extraction opens.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Files\Service\TextExtraction
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/large-file-handling/specs/file-actions/spec.md#requirement-text-extraction-has-a-stated-ceiling-and-a-file-above-it-stays-findable-req-lfh-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Service\TextExtraction;

/**
 * The extraction ceiling in bytes, read from the file settings.
 *
 * `textExtraction.maxBytes` wins when it holds a positive number. An install
 * that has not been migrated still states its ceiling in `maxFileSizeMB`,
 * which is read as mebibytes, so the 100 of today's default is the
 * 104857600 bytes the new key defaults to. Nothing usable reads as the default.
 *
 * @spec openspec/changes/large-file-handling/specs/file-actions/spec.md#requirement-text-extraction-has-a-stated-ceiling-and-a-file-above-it-stays-findable-req-lfh-001
 */
final class ExtractionCeiling {

	/**
	 * The extraction status of a file the ceiling kept closed.
	 *
	 * @var string
	 */
	public const SKIPPED_TOO_LARGE = 'skipped-too-large';

	/**
	 * The default ceiling: 100 MiB.
	 *
	 * @var int
	 */
	public const DEFAULT_BYTES = 104857600;

	/**
	 * Bytes per mebibyte, for the legacy `maxFileSizeMB`.
	 *
	 * @var int
	 */
	private const MEBIBYTE = 1048576;

	/**
	 * Constructor.
	 *
	 * @param int $maxBytes The largest size, in bytes, that is still opened.
	 */
	public function __construct(
		public readonly int $maxBytes,
	) {
	}//end __construct()

	/**
	 * The ceiling the file settings state.
	 *
	 * @param array<string, mixed> $fileSettings The file settings as read.
	 *
	 * @return self
	 *
	 * @spec openspec/changes/large-file-handling/specs/file-actions/spec.md#requirement-text-extraction-has-a-stated-ceiling-and-a-file-above-it-stays-findable-req-lfh-001
	 */
	public static function fromSettings(array $fileSettings): self {
		$maxBytes = self::positive(value: ($fileSettings['textExtraction']['maxBytes'] ?? null));
		if ($maxBytes !== null) {
			return new self(maxBytes: $maxBytes);
		}

		$megabytes = self::positive(value: ($fileSettings['maxFileSizeMB'] ?? null));
		if ($megabytes !== null) {
			return new self(maxBytes: $megabytes * self::MEBIBYTE);
		}

		return new self(maxBytes: self::DEFAULT_BYTES);
	}//end fromSettings()

	/**
	 * Whether a file of this size is opened: at or below the ceiling. A size
	 * that is not known is opened, as every file was before the ceiling.
	 *
	 * @param mixed $size The size the file row states.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/large-file-handling/specs/file-actions/spec.md#requirement-text-extraction-has-a-stated-ceiling-and-a-file-above-it-stays-findable-req-lfh-001
	 */
	public function admits(mixed $size): bool {
		if (is_int($size) === false && (is_string($size) === false || ctype_digit($size) === false)) {
			return true;
		}

		return (int) $size <= $this->maxBytes;
	}//end admits()

	/**
	 * A whole positive number, or null.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return int|null
	 */
	private static function positive(mixed $value): ?int {
		if (is_int($value) === true && $value > 0) {
			return $value;
		}

		if (is_string($value) === true && ctype_digit($value) === true && (int) $value > 0) {
			return (int) $value;
		}

		return null;
	}//end positive()
}//end class
