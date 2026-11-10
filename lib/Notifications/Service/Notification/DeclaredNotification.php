<?php

/**
 * One notification a schema declares.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Notification
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/notificatie-engine/spec.md#requirement-the-effective-preference-merges-schema-group-and-user-and-names-the-layer-req-nrg-002
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service\Notification;

/**
 * The owning schema's slug, the notification's key and its declaration block
 * from `x-openregister-notifications`.
 *
 * @spec openspec/specs/notificatie-engine/spec.md#requirement-the-effective-preference-merges-schema-group-and-user-and-names-the-layer-req-nrg-002
 */
final class DeclaredNotification {

	/**
	 * Constructor.
	 *
	 * @param string               $schemaSlug The owning schema's slug.
	 * @param string               $key        The notification annotation key.
	 * @param array<string, mixed> $spec       The declaration.
	 */
	public function __construct(
		public readonly string $schemaSlug,
		public readonly string $key,
		public readonly array $spec,
	) {
	}//end __construct()
}//end class
