<?php

/**
 * OpenRegister WriteBackSettings
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
 * Where a write-back property writes the source back to: the inverse property, the target schema and register.
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */
final class WriteBackSettings {

	/**
	 * The property on each target that receives the source's UUID.
	 *
	 * @var mixed
	 */
	public mixed $inverseProperty = null;

	/**
	 * The schema reference of the targets.
	 *
	 * @var mixed
	 */
	public mixed $targetSchema = null;

	/**
	 * The register of the targets.
	 *
	 * @var mixed
	 */
	public mixed $targetRegister = null;

	/**
	 * Whether the property is removed from the source after the write-back.
	 *
	 * @var mixed
	 */
	public mixed $removeFromSource = false;
}//end class
