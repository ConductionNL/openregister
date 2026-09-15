<?php

/**
 * OpenRegister DedupCreatePolicy
 *
 * Enforces the create-time half of `x-openregister-dedup`: `onCreate`
 * (`warn` by default, or `block`) and `overrideGroups`. The endpoint in
 * {@see \OCA\OpenRegister\Controller\DuplicateController::check()} answers the
 * question; this enforces the answer on the save path, so a client that never
 * asks is still stopped.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Quality
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Quality;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\DuplicateBlockedException;
use OCP\IGroupManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The declared create-time duplicate policy, enforced server-side.
 *
 * @spec openspec/changes/dedup-check-before-create/specs/duplicate-detection/spec.md#requirement-a-schema-declares-what-a-strong-match-does-at-create
 */
class DedupCreatePolicy {

	/**
	 * The payload key a caller sets to save through a blocking match.
	 *
	 * `SaveObject` strips it from the body before anything reads the body as
	 * data, so it never reaches schema validation, never lands in the stored
	 * object, and never has to be declared as a schema property. The constant
	 * lives here, beside the policy it belongs to, so the two spellings cannot
	 * drift apart.
	 *
	 * @var string
	 */
	public const OVERRIDE_KEY = '_dedupOverride';

	/**
	 * Audit action recorded when an override is exercised.
	 *
	 * @var string
	 */
	public const OVERRIDE_ACTION = 'dedup.overridden';

	/**
	 * The policy a schema gets when it declares none.
	 *
	 * @var string
	 */
	private const DEFAULT_ON_CREATE = 'warn';

	/**
	 * The policy that refuses.
	 *
	 * @var string
	 */
	private const BLOCK = 'block';

	/**
	 * Wire collaborators.
	 *
	 * @param DuplicateDetectionService $duplicates The one scorer, reused rather than reimplemented.
	 * @param IUserSession $userSession Current session, to name the caller.
	 * @param IGroupManager $groupManager Group membership, to evaluate `overrideGroups`.
	 * @param AuditTrailMapper $auditTrailMapper Audit writer for the override record.
	 * @param LoggerInterface $logger PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly DuplicateDetectionService $duplicates,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly AuditTrailMapper $auditTrailMapper,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Enforce `onCreate` for one create.
	 *
	 * Returns the matches an override was exercised over, so the caller can
	 * put them on the audit trail of the object it goes on to write, and an
	 * empty array in every other case. Throws when the create is refused.
	 *
	 * A match returned by {@see DuplicateDetectionService::checkCandidate()}
	 * is ALREADY at or above the schema's threshold — the scorer drops
	 * everything below it — so "there is a match" and "there is a strong
	 * match" are the same statement here, and re-testing the score against
	 * the threshold a second time would only invite the two to drift.
	 *
	 * @param int|string $register Register reference — the boundary the schema resolves inside.
	 * @param int|string $schema Schema reference.
	 * @param array<string, mixed> $data The candidate body, override flag already removed.
	 * @param bool $overrideRequested Whether the caller asked to save anyway.
	 *
	 * @return array<int, array<string, mixed>> Matches overridden, for the audit entry; empty otherwise.
	 *
	 * @throws DuplicateBlockedException When the schema blocks and the caller may not override.
	 *
	 * @spec openspec/changes/dedup-check-before-create/specs/duplicate-detection/spec.md#requirement-a-schema-declares-what-a-strong-match-does-at-create
	 */
	public function guardCreate($register, $schema, array $data, bool $overrideRequested): array {
		$annotation = $this->duplicates->dedupAnnotation(register: $register, schema: $schema);

		$onCreate = (string)($annotation['onCreate'] ?? self::DEFAULT_ON_CREATE);
		if ($onCreate !== self::BLOCK) {
			return [];
		}

		$matches = $this->duplicates->checkCandidate(register: $register, schema: $schema, candidate: $data);
		if (count($matches) === 0) {
			return [];
		}

		if ($overrideRequested === true && $this->mayOverride(annotation: $annotation) === true) {
			return $matches;
		}

		throw new DuplicateBlockedException(
			message: sprintf(
				'This object matches %d existing object(s) on the schema\'s duplicate rules and cannot be created.',
				count($matches)
			),
			matches: $matches
		);
	}//end guardCreate()

	/**
	 * Record on the created object that a blocking match was overridden.
	 *
	 * Best-effort: the object is already written, and losing the audit row
	 * must not undo a save that succeeded. It is logged at warning level
	 * instead, because an override that left no trace is the thing an
	 * administrator would want to know about.
	 *
	 * @param ObjectEntity $object The object that was created anyway.
	 * @param array<int, array<string, mixed>> $matches The matches it was created over.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dedup-check-before-create/specs/duplicate-detection/spec.md#requirement-a-schema-declares-what-a-strong-match-does-at-create
	 */
	public function recordOverride(ObjectEntity $object, array $matches): void {
		if (count($matches) === 0) {
			return;
		}

		$uuids = [];
		foreach ($matches as $match) {
			$uuid = (string)($match['uuid'] ?? '');
			if ($uuid !== '') {
				$uuids[] = $uuid;
			}
		}

		try {
			$this->auditTrailMapper->createAuditTrailEntry(
				object: $object,
				action: self::OVERRIDE_ACTION,
				context: [
					'matchedObjects' => $uuids,
					'matches' => $matches,
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'[DedupCreatePolicy] duplicate override could not be audited: ' . $e->getMessage(),
				['objectUuid' => (string)$object->getUuid(), 'matchedObjects' => $uuids]
			);
		}
	}//end recordOverride()

	/**
	 * Whether the calling user is in one of the declared override groups.
	 *
	 * There is deliberately NO implicit administrator bypass. ADR-023 wants
	 * the permission declared, and a schema that blocks without naming an
	 * override group has said that nobody overrides — which is a legitimate
	 * thing to declare, and would be silently untrue if admin passed anyway.
	 *
	 * @param array<string, mixed> $annotation The `x-openregister-dedup` block.
	 *
	 * @return bool True when the caller may save through a blocking match.
	 *
	 * @spec openspec/changes/dedup-check-before-create/specs/duplicate-detection/spec.md#requirement-a-schema-declares-what-a-strong-match-does-at-create
	 */
	private function mayOverride(array $annotation): bool {
		$groups = ($annotation['overrideGroups'] ?? null);
		if (is_array($groups) === false || count($groups) === 0) {
			return false;
		}

		$user = $this->userSession->getUser();
		if ($user === null) {
			return false;
		}

		foreach ($groups as $group) {
			if (is_string($group) === false || $group === '') {
				continue;
			}

			if ($this->groupManager->isInGroup($user->getUID(), $group) === true) {
				return true;
			}
		}

		return false;
	}//end mayOverride()
}//end class
