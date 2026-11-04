<?php

/**
 * OpenRegister ReferencePropertyRun
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

/**
 * One SaveObject::validateReferences() pass over an object's data, and the property it is on.
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */
final class ReferencePropertyRun {

	/**
	 * The object data being validated.
	 *
	 * @var array
	 */
	public array $data = [];

	/**
	 * The previous object data on an update, for skipping unchanged values.
	 *
	 * @var array|null
	 */
	public ?array $oldData = null;

	/**
	 * The object's register id, the fallback target register.
	 *
	 * @var string|null
	 */
	public ?string $register = null;

	/**
	 * The property being validated.
	 *
	 * @var string|integer
	 */
	public string|int $propertyName = '';

	/**
	 * The definition of the property being validated.
	 *
	 * @var array
	 */
	public array $property = [];

	/**
	 * The configured strictness: error or warn.
	 *
	 * @var string|null
	 */
	public ?string $strictness = null;

	/**
	 * The schema the property references.
	 *
	 * @var string
	 */
	public string $ref = '';

	/**
	 * The register the referenced objects live in.
	 *
	 * @var string|null
	 */
	public ?string $targetRegister = null;
}//end class
