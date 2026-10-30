<?php

/**
 * What a notification's recipients are resolved against.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Notification
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Notification;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * The object, its data, the trigger context and the schema's role assignment.
 */
final class RecipientSource {

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed>              $data       The object's stored data (or a flow item's json).
	 * @param ObjectEntity|null                 $object     The object, for `watchers`, `object-acl` and `expression` kinds.
	 * @param array<string, mixed>              $context    Trigger-specific extras handed to expression resolvers.
	 * @param array<string, array<int, string>> $roleGroups Role name to assigned group ids, for the `role` kind.
	 */
	public function __construct(
		public readonly array $data,
		public readonly ?ObjectEntity $object,
		public readonly array $context,
		public readonly array $roleGroups,
	) {
	}//end __construct()
}//end class
