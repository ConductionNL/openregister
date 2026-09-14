<?php

/**
 * OpenRegister RuleAuditService
 *
 * Records who switched a rule off or on, and what it was before.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rules
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rules;

use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * The audit entry for a configuration act on a rule.
 *
 * A rule lives in a schema annotation, not in an object, so its switch is a
 * configuration change and is audited the way this app already audits one:
 * through the structured audit log, beside
 * {@see \OCA\OpenRegister\Service\AuthorizationAuditService}, which records an
 * authorization change on the same surface. The object audit trail is for acts
 * on an object and takes an ObjectEntity; handing it a synthetic object
 * standing in for a schema would put a row in the object's history that no
 * object ever had.
 *
 * The schema's own version is bumped by the save that carries the switch, so
 * the change is also diffable against the previous schema version. This entry
 * is what names the ACTOR and the intent.
 */
final class RuleAuditService {

	/**
	 * The structured log event type these entries carry.
	 *
	 * @var string
	 */
	public const EVENT_TYPE = 'openregister_rule_switch';

	/**
	 * Constructor.
	 *
	 * @param IUserSession $userSession Names the actor.
	 * @param LoggerInterface $logger Writes the structured entry.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Record that a rule was switched.
	 *
	 * @param RuleDescriptor $rule The rule as it stood before the switch.
	 * @param bool $enabled What it was switched to.
	 *
	 * @return array{actor: string, actorName: string, ruleId: string, from: bool, to: bool} The entry, as the response echoes it.
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	public function switched(RuleDescriptor $rule, bool $enabled): array {
		$user = $this->userSession->getUser();
		$entry = [
			'actor' => ($user?->getUID() ?? 'system'),
			'actorName' => ($user?->getDisplayName() ?? 'System'),
			'ruleId' => $rule->getId(),
			'from' => $rule->isEnabled(),
			'to' => $enabled,
		];

		$this->logger->info(
			sprintf(
				'[%s] %s switched rule "%s" on schema "%s" from %s to %s.',
				self::EVENT_TYPE,
				$entry['actorName'],
				$rule->getId(),
				$rule->getSchemaSlug(),
				($entry['from'] === true ? 'enabled' : 'disabled'),
				($entry['to'] === true ? 'enabled' : 'disabled')
			),
			[
				'eventType' => self::EVENT_TYPE,
				'file' => __FILE__,
				'line' => __LINE__,
				'rule' => $entry,
				'schema' => $rule->getSchemaSlug(),
				'kind' => $rule->getKind(),
			]
		);

		return $entry;
	}//end switched()
}//end class
