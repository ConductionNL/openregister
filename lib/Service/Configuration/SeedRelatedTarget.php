<?php

/**
 * The freshly seeded object a set of related items belongs to.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Configuration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Configuration;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * A seeded object, where it lives, and the `_relatedItems` to create for it.
 *
 * @spec openspec/specs/data-import-export/spec.md
 */
final class SeedRelatedTarget
{

	/**
	 * @var array<string, mixed> The `_relatedItems` payload: files, notes, tasks.
	 */
	public array $items = [];

	/**
	 * @var int The register the object lives in.
	 */
	public int $registerId = 0;

	/**
	 * @var int The schema of the object.
	 */
	public int $schemaId = 0;

	/**
	 * @var string The title used in task subjects.
	 */
	public string $title = '';

	/**
	 * @var bool Whether a user is logged in, which notes and tasks need.
	 */
	public bool $hasUserContext = false;

	/**
	 * Name the seeded object.
	 *
	 * @param ObjectEntity $object The freshly seeded object.
	 */
	public function __construct(public readonly ObjectEntity $object)
	{
	}//end __construct()
}//end class
