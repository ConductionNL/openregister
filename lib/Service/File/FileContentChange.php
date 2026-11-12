<?php

/**
 * FileContentChange
 *
 * This file is part of the OpenRegister app for Nextcloud.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File;

/**
 * The new content and user tags for an existing file.
 *
 * @spec openspec/specs/file-actions/spec.md
 */
final class FileContentChange {

	/**
	 * Hold the change.
	 *
	 * @param mixed              $content The new content (byte string or readable stream), or null to keep it.
	 * @param array<int, string> $tags    The user tags that replace the current ones.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function __construct(
		public readonly mixed $content,
		public readonly array $tags,
	) {
	}//end __construct()
}//end class
