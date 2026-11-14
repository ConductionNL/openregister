<?php

/**
 * OpenRegister CopyRun
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Support;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * One copy with links: the source, the create of the copy and the link kinds to take along.
 *
 * @spec openspec/changes/records-copy-with-links/specs/objects-crud/spec.md#requirement-an-object-can-be-copied-with-the-links-the-caller-chooses
 */
final class CopyRun {
	/**
	 * Hold the copy.
	 *
	 * @param ObjectEntity       $source  The object copied.
	 * @param ObjectCreate       $create  The create of the copy.
	 * @param array<int, string> $include The link kinds to take along.
	 */
	public function __construct(
		public readonly ObjectEntity $source,
		public readonly ObjectCreate $create,
		public readonly array $include,
	) {
	}//end __construct()
}//end class
