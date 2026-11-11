<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Who may read one restricted file, why, and who said so.
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
 * @spec openspec/specs/file-actions/spec.md#requirement-a-file-in-an-objects-folder-can-carry-a-read-restriction-req-pfrr-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Service\File;

/**
 * A read restriction as it is stored: the principals who may read the file, the reason and its author.
 *
 * Built by FileRestrictionService once the principals and reason are checked; written by FileRestrictionStore.
 *
 * @spec openspec/specs/file-actions/spec.md#requirement-a-file-in-an-objects-folder-can-carry-a-read-restriction-req-pfrr-001
 */
final class FileRestriction {

	/**
	 * Constructor.
	 *
	 * @param list<string> $users  The users who may read the file.
	 * @param list<string> $groups The groups whose members may read the file.
	 * @param string       $reason Why the file is restricted.
	 * @param string       $setBy  Who set the restriction.
	 */
	public function __construct(
		public readonly array $users,
		public readonly array $groups,
		public readonly string $reason,
		public readonly string $setBy,
	) {
	}//end __construct()
}//end class
