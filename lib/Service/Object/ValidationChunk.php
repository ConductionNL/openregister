<?php

/**
 * OpenRegister ValidationChunk
 *
 * One slice of the objects a schema-wide validate-and-save run processes.
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
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */

namespace OCA\OpenRegister\Service\Object;

/**
 * The objects of one chunk and where the chunk starts.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class ValidationChunk {

	/**
	 * Constructor.
	 *
	 * @param array<int, mixed> $objects The chunk's objects.
	 * @param int               $offset  The position of its first object in the run.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		public readonly array $objects,
		public readonly int $offset,
	) {
	}//end __construct()
}//end class
