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

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * What templated default values render against: the data, and the schema properties.
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */
final class DefaultValueContext {

	/**
	 * @param array $data             The incoming data.
	 * @param array $twigContext      What templates see.
	 * @param array $schemaProperties The schema's property definitions.
	 */
	public function __construct(
		public readonly array $data,
		public array $twigContext,
		public array $schemaProperties = [],
	) {
	}//end __construct()

	/**
	 * The context of a save: templates see the stored object with the incoming data on top.
	 *
	 * @param ObjectEntity $objectEntity The object being saved.
	 * @param array        $data         The incoming data.
	 *
	 * @return self The context.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public static function ofSave(ObjectEntity $objectEntity, array $data): self {
		return new self(data: $data, twigContext: array_merge($objectEntity->getObjectArray(), $data));
	}//end ofSave()
}//end class
