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
use OCA\OpenRegister\Db\DelegationGrantMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\AuthorizationAuditService;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
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
 * @spec openspec/changes/data-subject-rights-across-the-instance/specs/authorization-rbac/spec.md
 */
class ReachRevocationService {
	/**
	 * The rule a revocation runs under, carried on every audit line.
	 *
	 * @var string
	 */
	public const RULE = 'reach-revoked-in-one-act';

	/**
	 * Wire the act.
	 *
	 * @param PrincipalReachService     $reach        The listing the act runs from.
	 * @param SchemaMapper              $schemaMapper Schema authorization blocks.
	 * @param DerivedGrantStore         $derived      Grants derived from sign-in claims.
	 * @param DelegationGrantMapper     $delegations  Delegations held.
	 * @param AuthorizationAuditService $audit        The record of what was removed.
	 * @param IUserSession              $userSession  The acting administrator.
	 * @param LoggerInterface           $logger       PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly PrincipalReachService $reach,
		private readonly SchemaMapper $schemaMapper,
		private readonly DerivedGrantStore $derived,
		private readonly DelegationGrantMapper $delegations,
		private readonly AuthorizationAuditService $audit,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
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
	 * @spec openspec/changes/data-subject-rights-across-the-instance/specs/authorization-rbac/spec.md
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
		$this->logger->warning(
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
				$this->schemaMapper->update($schema);
				$this->audit->logSchemaAuthorizationChange(
					schemaId: (int)$schema->getId(),
					schemaTitle: (string)$schema->getTitle(),
					oldAuthorization: $before,
					newAuthorization: $after
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

			$out[$key] = array_values($kept);
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
			return ($entry === $principal || $entry === ('user:' . $principal));
		}

		if (is_array($entry) === false) {
			return false;
		}

		foreach (['name', 'principal', 'id', 'user'] as $key) {
			$value = ($entry[$key] ?? null);
			if (is_string($value) === true
				&& ($value === $principal || $value === ('user:' . $principal))
			) {
				return true;
			}
		}

		return false;
	}//end namesPrincipal()

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
			$held = $this->derived->grantsFor(userId: $principal);
		} catch (Throwable $e) {
			$record['failed'][] = ['source' => PrincipalReach::SOURCE_DERIVED, 'error' => $e->getMessage()];
			return;
		}

		if ($held === []) {
			return;
		}

		try {
			$this->derived->forget(userId: $principal);
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
			$held = $this->delegations->findHeldBy(principal: $principal);
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
				$this->delegations->update($grant);

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
			return $this->schemaMapper->findAll();
		} catch (Throwable $e) {
			$this->logger->error(
				message: '[ReachRevocation] Could not list the schemas, so nothing was revoked from them',
				context: ['error' => $e->getMessage()]
			);
			return [];
		}
	}//end schemas()
}//end class
