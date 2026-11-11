<?php

/**
 * What preparing a stored object for an update works on: the row, its schema, the payload and the actor.
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
use OCA\OpenRegister\Db\Schema;
use OCP\IUser;

/**
 * One update preparation: the stored object, its schema, what was sent and who sends it.
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */
final class UpdatePreparation {
	/**
	 * Constructor.
	 *
	 * @param ObjectEntity  $existingObject The stored object, prepared in place.
	 * @param Schema        $schema         Its schema.
	 * @param ObjectPayload $payload        The sent property data and `@self` block.
	 * @param IUser|null    $currentUser    The acting user, or null for the session user.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function __construct(
		public readonly ObjectEntity $existingObject,
		public readonly Schema $schema,
		public readonly ObjectPayload $payload,
		public readonly ?IUser $currentUser = null,
	) {
	}//end __construct()
}//end class
