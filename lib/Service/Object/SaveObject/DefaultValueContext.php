<?php

/**
 * OpenRegister DefaultValueContext
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
 * What templated default values of one save render against: the merged object data and the schema properties.
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */
final class DefaultValueContext {

	/**
	 * The stored object data with the incoming data on top.
	 *
	 * @var array
	 */
	public array $twigContext = [];

	/**
	 * The schema's property definitions.
	 *
	 * @var array
	 */
	public array $schemaProperties = [];
}//end class
