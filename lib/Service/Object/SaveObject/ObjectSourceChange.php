<?php

/**
 * What a write delegated to an external object source changed.
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
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object\SaveObject;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * One external write as the audit trail records it: the action, and the
 * object before and after.
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */
final class ObjectSourceChange {
	/**
	 * The change.
	 *
	 * @param string            $action The action (`create`|`update`).
	 * @param ObjectEntity|null $old    The pre-write entity (null on create, or when the source no longer had it).
	 * @param ObjectEntity      $new    The post-write entity.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function __construct(
		public readonly string $action,
		public readonly ?ObjectEntity $old,
		public readonly ObjectEntity $new,
	) {
	}//end __construct()
}//end class
