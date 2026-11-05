<?php

/**
 * OpenRegister ReferenceCheck
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object\SaveObject
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

namespace OCA\OpenRegister\Service\Object\SaveObject;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;

/**
 * One reference SaveObject checks for existence, and what it resolved for it.
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */
final class ReferenceCheck {

	/**
	 * The property carrying the reference.
	 *
	 * @var string
	 */
	public string $propertyName = '';

	/**
	 * The referenced object's uuid.
	 *
	 * @var string
	 */
	public string $uuid = '';

	/**
	 * The schema the reference declares.
	 *
	 * @var string
	 */
	public string $schemaRef = '';

	/**
	 * The register to look in, or null for the object's own.
	 *
	 * @var string|null
	 */
	public ?string $register = null;

	/**
	 * The request-cache key of the (target schema, uuid) pair.
	 *
	 * @var string
	 */
	public string $cacheKey = '';

	/**
	 * The target schema's slug, or the raw reference when it has none.
	 *
	 * @var string
	 */
	public string $targetSchemaSlug = '';

	/**
	 * The target schema, when it resolves.
	 *
	 * @var Schema|null
	 */
	public ?Schema $targetSchema = null;

	/**
	 * The register entity to look in, when one is named.
	 *
	 * @var Register|null
	 */
	public ?Register $registerEntity = null;
}//end class
