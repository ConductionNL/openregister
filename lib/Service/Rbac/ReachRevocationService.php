<?php

/**
 * ReachRevocationService — everything one principal can reach, taken away in
 * one act, recorded naming every grant it removed.
 *
 * Uitdiensttreding, a compromised account, and "welke toegang had deze persoon"
 * are the same question asked at three moments, and the answer today is a
 * membership removed one grant at a time by somebody reading screens. This is
 * the act: the listing is resolved first, the administrator sees it, and then
 * one call removes what the authorization layer can remove.
 *
 * ADR-010 DECIDES WHAT IT MAY TOUCH. A revocation removes grants through the
 * authorization layer, never by editing a group. So a group-sourced reach is
 * REPORTED AS RETAINED with the group named, and is not silently counted as
 * removed. An act that quietly left access standing while reporting success is
 * the failure this service exists to prevent, and the retained list is how it
 * says so.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\Rbac
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use DateTime;
use DateTimeImmutable;
use OCA\OpenRegister\Db\DelegationGrant;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\AuthorizationAuditService;
use OCA\OpenRegister\Service\AuthorizationChange;
use OCP\IUserSession;
use Throwable;

/**
 * Revokes a principal's whole reach in one recorded act.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) One collaborator per source
 * the act can remove, plus the audit that records what it removed.
 *
 * @spec openspec/specs/authorization-rbac/spec.md
 */
class ReachRevocationService implements ReachRevocationServiceInterface {
	/**
	 * The rule a revocation runs under, carried on every audit line.
	 *
	 * @var string
	 */
	public const RULE = 'reach-revoked-in-one-act';

	/**
	 * Wire the act.
	 *
	 * @param PrincipalReachService     $reach       The listing the act runs from.
	 * @param ReachSources              $sources     Schema authorization blocks, derived grants and delegations held.
	 * @param ReachRevocationLog $log The authorization audit and the PSR log a revocation is written to.
	 * @param IUserSession              $userSession The acting administrator.
	 */
	public function __construct(
		private readonly PrincipalReachService $reach,
		private readonly ReachSources $sources,
		private readonly ReachRevocationLog $log,
		private readonly IUserSession $userSession,
	) {
	}//end __construct()

	/**
	 * Revoke everything this principal can reach, in one act.
	 *
	 * @param string $principal The account losing its reach.
	 * @param string $reason    Why, recorded with the act.
	 *
	 * @return array<string, mixed> One record naming every grant removed and every one retained.
	 *
	 * @spec openspec/specs/authorization-rbac/spec.md
	 */
	public function revokeAll(string $principal, string $reason = ''): array {
		$principal = trim($principal);
		$listing = $this->reach->listFor(principal: $principal);

		$record = [
			'principal' => $principal,
			'rule' => self::RULE,
			'reason' => $reason,
			'revokedBy' => ($this->userSession->getUser()?->getUID() ?? 'system'),
			'revokedAt' => (new DateTimeImmutable())->format(DATE_ATOM),
			'listedTotal' => $listing['total'],
			'removed' => [],
			'retained' => [],
			'failed' => [],
		];

		$this->removeAuthorizationEntries(principal: $principal, record: $record);
		$this->removeDerived(principal: $principal, record: $record);
		$this->removeDelegations(principal: $principal, record: $record);

		foreach ($listing['entries'] as $entry) {
			if ($entry['source'] === PrincipalReach::SOURCE_GROUP) {
				$record['retained'][] = [
					'source' => PrincipalReach::SOURCE_GROUP,
					'group' => ($entry['group'] ?? ''),
					'why' => 'A revocation does not edit groups (ADR-010). Remove the membership '
						. 'separately if the account should lose what the group carries.',
				];
			}
		}

		foreach (['removed', 'retained', 'failed'] as $bucket) {
			$record[$bucket . 'Count'] = count($record[$bucket]);
		}

		// ONE ENTRY NAMES ALL OF THEM. Per-grant lines would let a reader
		// believe a partial act was a whole one, and the question this answers
		// is "what did this person still have on the day they left".
		$this->log->logger->warning(
			message: '[ReachRevocation] A principal\'s whole reach was revoked in one act',
            context: array_merge(['event_type' => AuthorizationAuditService::EVENT_TYPE], $record)
		);

		return $record;
	}//end revokeAll()

	/**
	 * Drop every schema authorization entry that names this principal directly.
	 *
	 * Only entries naming the ACCOUNT are removed. An entry naming a group is
	 * left exactly as written, because rewriting it would change what every
	 * other member of that group may do.
	 *
	 * @param string               $principal The account.
	 * @param array<string, mixed> $record    The record (mutated by reference).
	 *
	 * @return void
	 */
	private function removeAuthorizationEntries(string $principal, array &$record): void {
		foreach ($this->schemas() as $schema) {
			$before = ($schema->getAuthorization() ?? []);
			if ($before === []) {
				continue;
			}

			$after = $this->withoutPrincipal(block: $before, principal: $principal);
			if ($after === $before) {
				continue;
			}

			try {
				$schema->setAuthorization($after);
				$this->sources->schemaMapper->update($schema);
				$this->log->audit->recordSchemaChange(
					change: new AuthorizationChange(
						entityId: (int)$schema->getId(),
						entityTitle: (string)$schema->getTitle(),
						before: $before,
						after: $after
					)
				);

				$record['removed'][] = [
					'source' => PrincipalReach::SOURCE_AUTHORIZATION,
					'schema' => $schema->getId(),
					'schemaTitle' => $schema->getTitle(),
				];
			} catch (Throwable $e) {
				$record['failed'][] = [
					'source' => PrincipalReach::SOURCE_AUTHORIZATION,
					'schema' => $schema->getId(),
					'error' => $e->getMessage(),
				];
			}//end try
		}//end foreach
	}//end removeAuthorizationEntries()

	/**
	 * One authorization block with every entry naming this principal removed.
	 *
	 * @param array<mixed> $block     The block as written.
	 * @param string       $principal The account.
	 *
	 * @return array<mixed> The block without that account.
	 */
	private function withoutPrincipal(array $block, string $principal): array {
		$out = [];
		foreach ($block as $key => $value) {
			if (is_array($value) === false) {
				$out[$key] = $value;
				continue;
			}

			$kept = [];
			foreach ($value as $entry) {
				if ($this->namesPrincipal(entry: $entry, principal: $principal) === false) {
					$kept[] = $entry;
				}
			}

			// `$kept` is only ever appended to, so it is already a list.
			// array_values() here would be a no-op that reads as a reindex
			// somebody needed.
			$out[$key] = $kept;
		}

		return $out;
	}//end withoutPrincipal()

	/**
	 * Whether one authorization entry names this account.
	 *
	 * Handles both shapes the grammar allows: a bare string, and the
	 * constrained form carrying `until` / `scopedTo` beside the name.
	 *
	 * @param mixed  $entry     The entry as written.
	 * @param string $principal The account.
	 *
	 * @return bool True when the entry names the account.
	 */
	private function namesPrincipal(mixed $entry, string $principal): bool {
		if (is_string($entry) === true) {
			return self::isPrincipalName(value: $entry, principal: $principal);
		}

		if (is_array($entry) === false) {
			return false;
		}

		foreach (['name', 'principal', 'id', 'user'] as $key) {
			if (self::isPrincipalName(value: ($entry[$key] ?? null), principal: $principal) === true) {
				return true;
			}
		}

		return false;
	}//end namesPrincipal()

	/**
	 * Whether a value is the account's name, bare or in its `user:` form.
	 *
	 * @param mixed  $value     The value as written.
	 * @param string $principal The account.
	 *
	 * @return bool True when the value names the account.
	 */
	private static function isPrincipalName(mixed $value, string $principal): bool {
		return (is_string($value) === true && ($value === $principal || $value === ('user:' . $principal)));
	}//end isPrincipalName()

	/**
	 * Forget the grants derived from this account's sign-in claims.
	 *
	 * @param string               $principal The account.
	 * @param array<string, mixed> $record    The record (mutated by reference).
	 *
	 * @return void
	 */
	private function removeDerived(string $principal, array &$record): void {
		try {
			$held = $this->sources->derived->grantsFor(userId: $principal);
		} catch (Throwable $e) {
			$record['failed'][] = ['source' => PrincipalReach::SOURCE_DERIVED, 'error' => $e->getMessage()];
			return;
		}

		if ($held === []) {
			return;
		}

		try {
			$this->sources->derived->forget(userId: $principal);
			$record['removed'][] = [
				'source' => PrincipalReach::SOURCE_DERIVED,
				'grants' => $held,
			];
		} catch (Throwable $e) {
			$record['failed'][] = ['source' => PrincipalReach::SOURCE_DERIVED, 'error' => $e->getMessage()];
		}
	}//end removeDerived()

	/**
	 * Revoke every delegation this principal still holds.
	 *
	 * @param string               $principal The account.
	 * @param array<string, mixed> $record    The record (mutated by reference).
	 *
	 * @return void
	 */
	private function removeDelegations(string $principal, array &$record): void {
		$now = new DateTime();

		try {
			$held = $this->sources->delegations->findHeldBy(principal: $principal);
		} catch (Throwable $e) {
			$record['failed'][] = ['source' => PrincipalReach::SOURCE_DELEGATION, 'error' => $e->getMessage()];
			return;
		}

		foreach ($held as $grant) {
			if ($grant->isLiveAt($now) === false) {
				continue;
			}

			try {
				$grant->setStatus(DelegationGrant::STATUS_REVOKED);
				$grant->setRevokedAt(new DateTime());
				$this->sources->delegations->update($grant);

				$record['removed'][] = [
					'source' => PrincipalReach::SOURCE_DELEGATION,
					'uuid' => $grant->getUuid(),
					'actingAs' => $grant->getActingAs(),
				];
			} catch (Throwable $e) {
				$record['failed'][] = [
					'source' => PrincipalReach::SOURCE_DELEGATION,
					'uuid' => $grant->getUuid(),
					'error' => $e->getMessage(),
				];
			}//end try
		}//end foreach
	}//end removeDelegations()

	/**
	 * Every schema whose authorization block may name this principal.
	 *
	 * @return array<int, Schema> The schemas.
	 */
	private function schemas(): array {
		try {
			return $this->sources->schemaMapper->findAll();
		} catch (Throwable $e) {
			$this->log->logger->error(
				message: '[ReachRevocation] Could not list the schemas, so nothing was revoked from them',
				context: ['error' => $e->getMessage()]
			);
			return [];
		}
	}//end schemas()
}//end class
