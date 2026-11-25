<?php

/**
 * Who a notification rule reaches and in which language.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Notifications\Service\Notification
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

namespace OCA\OpenRegister\Notifications\Service\Notification;

use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCP\IConfig;

/**
 * What the dispatcher reads to decide whom a rule reaches: the object's schema
 * (where the rules are declared) and register, the shared recipient resolver,
 * and the user config the recipient's language is read from; for a note, its
 * author and excerpt and the read check its recipients pass.
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */
final readonly class RuleAudience {
	/**
	 * Constructor.
	 *
	 * @param SchemaMapperInterface         $schemaMapper Resolves the object's schema.
	 * @param NotificationRecipientResolver $recipientResolver The shared recipient resolver.
	 * @param ?RegisterMapperInterface      $registerMapper Resolves the object's register (default originApp, languages).
	 * @param ?IConfig                      $config User config for the recipient's language.
	 * @param ?NoteTriggerContext           $notes The note's author and excerpt, and the author exclusion, of a `noteAdded` firing.
	 * @param ?ReadableRecipients           $readable The read check a `noteAdded` firing's recipients pass.
	 */
	public function __construct(
		public SchemaMapperInterface $schemaMapper,
		public NotificationRecipientResolver $recipientResolver,
		public ?RegisterMapperInterface $registerMapper = null,
		public ?IConfig $config = null,
		public ?NoteTriggerContext $notes = null,
		public ?ReadableRecipients $readable = null,
	) {
	}//end __construct()
}//end class
