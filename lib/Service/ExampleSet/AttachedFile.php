<?php

/**
 * OpenRegister Attached File
 *
 * A file an example set attached, as its install record holds it.
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
 * The id and name of a file the set attached, as the install record holds them.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-an-object-of-a-set-may-carry-files-from-its-fragment-req-exs-013
 */
final class AttachedFile {

	/**
	 * The file id.
	 *
	 * @var string
	 */
	public readonly string $id;

	/**
	 * The file name.
	 *
	 * @var string
	 */
	public readonly string $name;

	/**
	 * Constructor.
	 *
	 * @param string $id   The file id.
	 * @param string $name The file name.
	 */
	public function __construct(string $id, string $name) {
		$this->id   = $id;
		$this->name = $name;
	}//end __construct()
}//end class
