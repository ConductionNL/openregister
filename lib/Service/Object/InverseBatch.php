<?php

/**
 * OpenRegister InverseBatch
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
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */
namespace OCA\OpenRegister\Service\Object;

/**
 * One inverse property being loaded for a page of entities.
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */
final class InverseBatch {

	/**
	 * The inverse property name.
	 *
	 * @var string
	 */
	public string $propName = '';

	/**
	 * The UUIDs of the entities being rendered.
	 *
	 * @var array<string|null>
	 */
	public array $entityUuids = [];

	/**
	 * The register the referencing objects live in.
	 *
	 * @var integer
	 */
	public int $registerId = 0;

	/**
	 * The schema the referencing objects live in.
	 *
	 * @var string
	 */
	public string $targetSchemaId = '';

	/**
	 * The fields that may hold the reference back.
	 *
	 * @var string[]
	 */
	public array $inversedByFields = [];
}//end class
