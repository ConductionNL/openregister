<?php

/**
 * OpenRegister Gdpr PrivacyOfficerRecipientResolver
 *
 * Notification recipient resolver (`kind: expression`, ADR-031 declared
 * recipients) that resolves the privacy-officer GROUP from the DSAR case's
 * active `dsarPolicyPack` (`privacyOfficerGroup`) and returns its members'
 * uids. Declared on the register (deadlineBreach + dpiaFlagged rules) so
 * breach and DPIA visibility reach the officer WITHOUT hard-coding a group
 * in the schema — the pack carries the group per jurisdiction (config as
 * data, ADR-047).
 *
 * Fail-safe: no resolvable pack, no `privacyOfficerGroup`, a placeholder
 * value, or an unknown group yields ZERO officer recipients — the
 * notification still reaches its other declared recipients (the handler).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Gdpr
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/dsar-escalation-and-dpia/specs/dsar-deadline-escalation/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Gdpr;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Gdpr\Policy\DsarPolicyPackResolver;
use OCA\OpenRegister\Notifications\Service\Notification\RecipientResolverInterface;
use OCP\IGroupManager;
use Psr\Log\LoggerInterface;

/**
 * Resolve the pack-declared privacy-officer group to notification uids.
 *
 * @spec openspec/changes/dsar-escalation-and-dpia/specs/dsar-deadline-escalation/spec.md
 *   (Requirement: Deadline breach is stamped on the case and visible to the privacy officer)
 */
class PrivacyOfficerRecipientResolver implements RecipientResolverInterface {

	/**
	 * The pack field naming the privacy-officer Nextcloud group.
	 *
	 * @var string
	 */
	public const PACK_FIELD = 'privacyOfficerGroup';

	/**
	 * Constructor.
	 *
	 * @param DsarPolicyPackResolver $packResolver Active-pack resolution for the case.
	 * @param IGroupManager $groupManager Group-member expansion.
	 * @param LoggerInterface $logger Logger for resolution diagnostics.
	 */
	public function __construct(
		private readonly DsarPolicyPackResolver $packResolver,
		private readonly IGroupManager $groupManager,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Resolve the officer recipients for a notification dispatch.
	 *
	 * @param ObjectEntity $object The DSAR case the event happened on.
	 * @param array<string, mixed> $context Trigger-specific extras (unused).
	 *
	 * @return array<int, string> Officer group member uids (empty fail-safe).
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $context is part of the resolver contract.
	 *
	 * @spec openspec/changes/dsar-escalation-and-dpia/specs/dsar-deadline-escalation/spec.md
	 *   (Scenario: Breach notifies handler and privacy officer)
	 */
	public function resolve(ObjectEntity $object, array $context): array {
		$case = ($object->getObject() ?? []);

		// Notification dispatch may run from a user write OR a system sweep;
		// resolve the pack system-scoped so both paths see the same pack.
		$pack = $this->packResolver->activePackForCase(case: $case, systemContext: true);
		if ($pack === null) {
			return [];
		}

		$groupId = $this->officerGroupOf(pack: $pack);
		if ($groupId === null) {
			return [];
		}

		return $this->membersOf(groupId: $groupId);

	}//end resolve()

	/**
	 * The officer group the pack names, or null when unset or a placeholder.
	 *
	 * @param array<string, mixed> $pack The active policy pack.
	 *
	 * @return string|null The group id, or null (fail-safe: no recipients).
	 */
	private function officerGroupOf(array $pack): ?string {
		$groupId = ($pack[self::PACK_FIELD] ?? null);
		if (is_string($groupId) === false || $groupId === '' || str_starts_with($groupId, '<') === true) {
			// Unset or placeholder (`<privacy-officer-group>`) — fail-safe.
			return null;
		}

		return $groupId;

	}//end officerGroupOf()

	/**
	 * The unique uids of a group's members; empty when unknown or unresolvable.
	 *
	 * @param string $groupId The Nextcloud group id the pack names.
	 *
	 * @return array<int, string>
	 */
	private function membersOf(string $groupId): array {
		try {
			return $this->expand(groupId: $groupId);
		} catch (\Throwable $e) {
			$this->logger->warning(
				message: '[PrivacyOfficerRecipientResolver] group resolution failed: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__, 'group' => $groupId]
			);
			return [];
		}

	}//end membersOf()

	/**
	 * Expand a group to its members' unique uids; empty for an unknown group.
	 *
	 * @param string $groupId The Nextcloud group id.
	 *
	 * @return array<int, string>
	 */
	private function expand(string $groupId): array {
		$group = $this->groupManager->get($groupId);
		if ($group === null) {
			$this->logger->debug(
				message: '[PrivacyOfficerRecipientResolver] pack names unknown group "' . $groupId . '" — no officer recipients',
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return [];
		}

		$uids = array_map(static fn ($user): string => $user->getUID(), $group->getUsers());

		return array_values(array_unique($uids));

	}//end expand()
}//end class
