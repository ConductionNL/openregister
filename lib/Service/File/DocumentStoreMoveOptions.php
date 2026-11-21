<?php

/**
 * What one run of the document store move is asked to do.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\File
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/register-document-store/specs/file-actions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File;

/**
 * The target root and the switches of `occ openregister:document-store:move`.
 *
 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The switches are the command's own flags, carried as values.
 *
 * @spec openspec/changes/register-document-store/specs/file-actions/spec.md
 */
final class DocumentStoreMoveOptions {

	/**
	 * Constructor.
	 *
	 * @param string   $target          The target root, a folder path in the openregister account's home.
	 * @param int|null $registerId      Only this register, or every register when null.
	 * @param bool     $dryRun          Report what would happen and change nothing.
	 * @param bool     $withoutVersions Move registers with earlier versions to a target that keeps none.
	 *
	 * @spec openspec/changes/register-document-store/specs/file-actions/spec.md
	 */
	public function __construct(
		public readonly string $target,
		public readonly ?int $registerId = null,
		public readonly bool $dryRun = false,
		public readonly bool $withoutVersions = false,
	) {
	}//end __construct()

	/**
	 * The target path without leading or trailing slashes.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/register-document-store/specs/file-actions/spec.md
	 */
	public function targetPath(): string {
		return trim($this->target, " \t/");
	}//end targetPath()
}//end class
