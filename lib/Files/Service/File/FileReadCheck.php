<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * One read check of several files for one caller: the verdicts so far, the
 * files still to decide, their stored restrictions and the caller's groups.
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
 * @spec openspec/specs/file-actions/spec.md#requirement-a-restricted-file-is-readable-only-by-its-listed-principals-administrators-and-admitted-callers-req-pfrr-002
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Service\File;

/**
 * The caller, verdicts, undecided files, restrictions and groups of one FileRestrictionGuard::readable() call.
 *
 * @spec openspec/specs/file-actions/spec.md#requirement-a-restricted-file-is-readable-only-by-its-listed-principals-administrators-and-admitted-callers-req-pfrr-002
 */
final class FileReadCheck {

	/**
	 * The verdict per file id decided so far.
	 *
	 * @var array<int, bool>
	 */
	public array $verdicts = [];

	/**
	 * The file ids the memo does not answer, in asked order.
	 *
	 * @var list<int>
	 */
	public array $unknown = [];

	/**
	 * The stored restriction per restricted unknown file id.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $restrictions = [];

	/**
	 * The caller's groups, read once when a restricted file needs them.
	 *
	 * @var list<string>|null
	 */
	public ?array $groups = null;

	/**
	 * Constructor.
	 *
	 * @param string|null $userId The caller's uid; null when anonymous.
	 *
	 * @spec openspec/specs/file-actions/spec.md#requirement-a-restricted-file-is-readable-only-by-its-listed-principals-administrators-and-admitted-callers-req-pfrr-002
	 */
	public function __construct(
		public readonly ?string $userId,
	) {
	}//end __construct()

	/**
	 * The memo key of a verdict: the caller and the file.
	 *
	 * @param int $fileId The Nextcloud file id.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/file-actions/spec.md#requirement-a-restricted-file-is-readable-only-by-its-listed-principals-administrators-and-admitted-callers-req-pfrr-002
	 */
	public function memoKey(int $fileId): string {
		return ($this->userId ?? '') . ':' . $fileId;
	}//end memoKey()

	/**
	 * Whether the caller is named, so a restriction can admit them.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/file-actions/spec.md#requirement-a-restricted-file-is-readable-only-by-its-listed-principals-administrators-and-admitted-callers-req-pfrr-002
	 */
	public function callerIsNamed(): bool {
		return ($this->userId !== null && $this->userId !== '');
	}//end callerIsNamed()
}//end class
