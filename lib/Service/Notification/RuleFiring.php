<?php

/**
 * One notification rule firing for one object event.
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
use OCA\OpenRegister\Db\Schema;

/**
 * The rule, the event it fired on, and what the dispatcher resolved for it.
 *
 * The constructor holds the event; the public fields below it are filled in
 * by the dispatcher as the firing passes its gates, in that order.
 *
 * @SuppressWarnings(PHPMD.TooManyFields) One field per value the dispatch steps share.
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */
final class RuleFiring {

	/**
	 * The object's stored data.
	 *
	 * @var array<string, mixed>
	 */
	public readonly array $data;

	/**
	 * The schema slug, or its id when it has none.
	 *
	 * @var string
	 */
	public readonly string $schemaSlug;

	/**
	 * Identifier shared by every transport this firing runs.
	 *
	 * @var string
	 */
	public string $eventId = '';

	/**
	 * Candidate preference scopes, narrowest first.
	 *
	 * @var array<int, string>
	 */
	public array $scopes = [];

	/**
	 * Resolved recipient uids.
	 *
	 * @var array<int, mixed>
	 */
	public array $recipients = [];

	/**
	 * The rule's channels.
	 *
	 * @var array<int, string>
	 */
	public array $channels = [];

	/**
	 * Subject in the default locale, for channels without a recipient locale.
	 *
	 * @var string
	 */
	public string $broadcastSubject = '';

	/**
	 * Body in the default locale, for channels without a recipient locale.
	 *
	 * @var string
	 */
	public string $broadcastMessage = '';

	/**
	 * Body of a mail to a party or a referenced address.
	 *
	 * @var string
	 */
	public string $addressBody = '';

	/**
	 * Message category of a mail to a party or a referenced address.
	 *
	 * @var string
	 */
	public string $category = '';

	/**
	 * Per-rule rate limit block.
	 *
	 * @var array<string, mixed>|null
	 */
	public ?array $rateLimit = null;

	/**
	 * Per-rule coalesce block.
	 *
	 * @var array<string, mixed>|null
	 */
	public ?array $coalesce = null;

	/**
	 * The app the notification is attributed to.
	 *
	 * @var string
	 */
	public string $originApp = '';

	/**
	 * Resolved action buttons.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $actions = [];

	/**
	 * Constructor.
	 *
	 * @param string               $ruleId     The rule's annotation key.
	 * @param array<string, mixed> $spec       The rule declaration.
	 * @param ObjectEntity         $object     The object the event happened on.
	 * @param Schema               $schema     The object's schema.
	 * @param array<string, mixed> $context    Trigger-specific extras.
	 * @param string               $subjectKey Canonical notification subject for the trigger.
	 */
	public function __construct(
		public readonly string $ruleId,
		public readonly array $spec,
		public readonly ObjectEntity $object,
		public readonly Schema $schema,
		public readonly array $context,
		public readonly string $subjectKey,
	) {
		$this->data       = ($object->getObject() ?? []);
		$this->schemaSlug = (string)($schema->getSlug() ?? $schema->getId());
	}//end __construct()

	/**
	 * The rule's subject template; the rule name when it declares none.
	 *
	 * @return mixed
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	public function subjectTemplate(): mixed {
		return ($this->spec['subject'] ?? $this->ruleId);
	}//end subjectTemplate()

	/**
	 * The rule's message template, or null.
	 *
	 * @return mixed
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	public function messageTemplate(): mixed {
		return ($this->spec['message'] ?? null);
	}//end messageTemplate()

	/**
	 * The rule's declared recipients.
	 *
	 * @return array<int|string, mixed>
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	public function recipientsSpec(): array {
		return (array)($this->spec['recipients'] ?? []);
	}//end recipientsSpec()

	/**
	 * Whether the rule runs the given channel.
	 *
	 * @param string $channel The channel name.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	public function hasChannel(string $channel): bool {
		return in_array($channel, $this->channels, true);
	}//end hasChannel()
}//end class
