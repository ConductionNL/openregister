<?php

/**
 * One notification rule together with the schema it belongs to.
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

/**
 * A named rule from `x-openregister-notifications` and its schema facts.
 */
final class NotificationRuleContext {

	/**
	 * Constructor.
	 *
	 * @param string                  $name   The rule key.
	 * @param array<string, mixed>    $spec   The rule declaration.
	 * @param NotificationSchemaFacts $schema The schema the rule is declared on.
	 */
	public function __construct(
		public readonly string $name,
		public readonly array $spec,
		public readonly NotificationSchemaFacts $schema,
	) {
	}//end __construct()
}//end class
