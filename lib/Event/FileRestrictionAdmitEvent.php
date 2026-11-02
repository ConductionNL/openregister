<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Asked when a caller is not listed on a restricted file, so an app can admit them by its own rule.
 *
 * @category Event
 * @package  OCA\OpenRegister\Event
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

namespace OCA\OpenRegister\Event;

use OCP\EventDispatcher\Event;

/**
 * A listener may only admit; nothing it does refuses a caller the restriction lets in.
 *
 * @spec openspec/specs/file-actions/spec.md#requirement-a-restricted-file-is-readable-only-by-its-listed-principals-administrators-and-admitted-callers-req-pfrr-002
 */
class FileRestrictionAdmitEvent extends Event {

	/**
	 * Whether a listener admitted the caller.
	 *
	 * @var boolean
	 */
	private bool $admitted = false;

	/**
	 * Constructor.
	 *
	 * @param int                  $fileId      The restricted file.
	 * @param string               $userId      The caller.
	 * @param array<string, mixed> $restriction The stored restriction.
	 */
	public function __construct(
		private readonly int $fileId,
		private readonly string $userId,
		private readonly array $restriction,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * The restricted file.
	 *
	 * @return int The Nextcloud file id.
	 *
	 * @spec openspec/specs/file-actions/spec.md#requirement-a-restricted-file-is-readable-only-by-its-listed-principals-administrators-and-admitted-callers-req-pfrr-002
	 */
	public function getFileId(): int {
		return $this->fileId;
	}//end getFileId()

	/**
	 * The caller asking.
	 *
	 * @return string The user id.
	 *
	 * @spec openspec/specs/file-actions/spec.md#requirement-a-restricted-file-is-readable-only-by-its-listed-principals-administrators-and-admitted-callers-req-pfrr-002
	 */
	public function getUserId(): string {
		return $this->userId;
	}//end getUserId()

	/**
	 * The stored restriction.
	 *
	 * @return array<string, mixed> The row.
	 *
	 * @spec openspec/specs/file-actions/spec.md#requirement-a-restricted-file-is-readable-only-by-its-listed-principals-administrators-and-admitted-callers-req-pfrr-002
	 */
	public function getRestriction(): array {
		return $this->restriction;
	}//end getRestriction()

	/**
	 * Admit the caller.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/file-actions/spec.md#requirement-a-restricted-file-is-readable-only-by-its-listed-principals-administrators-and-admitted-callers-req-pfrr-002
	 */
	public function admit(): void {
		$this->admitted = true;
		$this->stopPropagation();
	}//end admit()

	/**
	 * Whether a listener admitted the caller.
	 *
	 * @return bool True when admitted.
	 *
	 * @spec openspec/specs/file-actions/spec.md#requirement-a-restricted-file-is-readable-only-by-its-listed-principals-administrators-and-admitted-callers-req-pfrr-002
	 */
	public function isAdmitted(): bool {
		return $this->admitted;
	}//end isAdmitted()
}//end class
