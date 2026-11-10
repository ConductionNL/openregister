<?php

/**
 * OpenRegister Local File
 *
 * A file read from a fragment's folder, as the example-set file writer attaches it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ExampleSet
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-an-object-of-a-set-may-carry-files-from-its-fragment-req-exs-013
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

/**
 * The real path of a file inside the fragment's folder and whether it is shared publicly.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-an-object-of-a-set-may-carry-files-from-its-fragment-req-exs-013
 */
final class LocalFile {

	/**
	 * The file's real path.
	 *
	 * @var string
	 */
	public readonly string $path;

	/**
	 * Whether the file is shared publicly.
	 *
	 * @var boolean
	 */
	public readonly bool $share;

	/**
	 * Constructor.
	 *
	 * @param string  $path  The file's real path.
	 * @param boolean $share Whether the file is shared publicly.
	 */
	public function __construct(string $path, bool $share) {
		$this->path  = $path;
		$this->share = $share;
	}//end __construct()
}//end class
