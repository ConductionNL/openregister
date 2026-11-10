<?php

/**
 * OpenRegister File Attach Run
 *
 * One attach of a declared object's files, as the example-set file writer runs it.
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
 * The fragment's folder the files are read from and the written object they are attached to.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-an-object-of-a-set-may-carry-files-from-its-fragment-req-exs-013
 */
final class FileAttachRun {

	/**
	 * The fragment's folder.
	 *
	 * @var string
	 */
	public readonly string $dir;

	/**
	 * The written object's id.
	 *
	 * @var string
	 */
	public readonly string $objectId;

	/**
	 * Constructor.
	 *
	 * @param string $dir      The fragment's folder.
	 * @param string $objectId The written object's id.
	 */
	public function __construct(string $dir, string $objectId) {
		$this->dir      = $dir;
		$this->objectId = $objectId;
	}//end __construct()
}//end class
