<?php

/**
 * OpenRegister file action run
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

namespace OCA\OpenRegister\Service\Object;

use Closure;

/**
 * One file action (transfer or delete) applied to every file of a merge source, with its outcome so far.
 *
 * Built by MergeHandler for one action and never leaves MergeHandler.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class FileActionRun {

	/**
	 * The outcome so far: the files, how many were done, the errors.
	 *
	 * @var array{files: list<array<string, mixed>>, count: int, errors: list<string>}
	 */
	public array $result = ['files' => [], 'count' => 0, 'errors' => []];

	/**
	 * Constructor.
	 *
	 * @param array<string, string> $labels Report labels: FILE_TRANSFER or FILE_DELETE.
	 * @param Closure               $apply  Applies the action to one File and returns the file's name.
	 */
	public function __construct(
		public readonly array $labels,
		public readonly Closure $apply,
	) {
	}//end __construct()
}//end class
