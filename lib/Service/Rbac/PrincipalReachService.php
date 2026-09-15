<?php

/**
 * PrincipalReachService — everything one principal can reach, read from the
 * resolver rather than walked over screens.
 *
 * D-5 IS THE WHOLE DESIGN. "Welke toegang had deze persoon" is the reverse of
 * the query the permission cascade already compiles, and
 * {@see \OCA\OpenRegister\Service\Object\PermissionHandler} is the one place
 * that answers it: `permittedActionsFor()` says which verbs a principal holds
 * on a schema and `provenanceFor()` says which rule granted each one. Reading
 * those gives one answer that stays true as the rules change. Walking the
 * surfaces gives an answer that is stale the moment a rule moves, and an
 * uitdiensttreding carried out from a stale list leaves access behind.
 *
 * Two sources sit outside the cascade and are read from their own stores: the
 * grants derived from sign-in claims, and the delegations somebody granted.
 * Group membership is listed too, because a rule that names a group is why the
 * principal reaches anything at all, and an administrator about to revoke
 * needs to see the part the revocation will NOT take (ADR-010).
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
use OCA\OpenRegister\Db\DelegationGrantMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCP\IGroupManager;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Lists everything a principal can reach, with where each grant comes from.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The listing exists to ask
 * every authority that can grant reach rather than re-derive any of them, so it
 * holds one collaborator per source.
 *
 * @spec openspec/changes/data-subject-rights-across-the-instance/specs/authorization-rbac/spec.md
 */
class PrincipalReachService {
	/**
	 * Wire the authorities the listing reads.
	 *
	 * @param PermissionHandler     $permissions  The canonical RBAC verdict and its provenance.
	 * @param SchemaMapper          $schemaMapper The schemas the cascade is asked about.
	 * @param DerivedGrantStore     $derived      Grants derived from sign-in claims.
	 * @param DelegationGrantMapper $delegations  Delegations held by this principal.
	 * @param IGroupManager         $groupManager Group membership.
	 * @param IUserManager          $userManager  Account lookup.
	 * @param LoggerInterface       $logger       PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly PermissionHandler $permissions,
		private readonly SchemaMapper $schemaMapper,
		private readonly DerivedGrantStore $derived,
		private readonly DelegationGrantMapper $delegations,
		private readonly IGroupManager $groupManager,
		private readonly IUserManager $userManager,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Everything this principal can reach, and where each grant comes from.
	 *
	 * Reads only. Nothing here removes anything, because D-5 says the list is
	 * shown before anything is taken away.
	 *
	 * @param string $principal The account to report on.
	 *
	 * @return array<string, mixed> The reach listing.
	 *
	 * @spec openspec/changes/data-subject-rights-across-the-instance/specs/authorization-rbac/spec.md
	 */
	public function listFor(string $principal): array {
		$principal = trim($principal);

		$entries = array_merge(
			$this->fromAuthorization(principal: $principal),
			$this->fromGroups(principal: $principal),
			$this->fromDerived(principal: $principal),
			$this->fromDelegations(principal: $principal)
		);

		$bySource = [];
		foreach (PrincipalReach::SOURCES as $source) {
			$bySource[$source] = 0;
		}

		$revocable = 0;
		foreach ($entries as $entry) {
			$bySource[$entry['source']]++;
			if ($entry['revocable'] === true) {
				$revocable++;
			}
		}

		return [
			'principal' => $principal,
			'exists' => $this->userManager->userExists($principal),
			'generatedAt' => (new DateTimeImmutable())->format(DATE_ATOM),
			'total' => count($entries),
			'revocable' => $revocable,
			// RETAINED IS NOT A ROUNDING ERROR. It is the group-sourced reach a
			// revocation will leave standing, and an administrator who cannot
			// see it will believe the act took everything.
			'retained' => (count($entries) - $revocable),
			'bySource' => $bySource,
			'entries' => $entries,
		];
	}//end listFor()

	/**
	 * The reach the authorization cascade grants, with the rule that granted it.
	 *
	 * @param string $principal The account.
	 *
	 * @return array<int, array<string, mixed>> The entries.
	 */
	private function fromAuthorization(string $principal): array {
		$entries = [];

		foreach ($this->schemas() as $schema) {
			$actions = [];
			try {
				$actions = $this->permissions->permittedActionsFor(schema: $schema, object: null, userId: $principal);
			} catch (Throwable $e) {
				$this->logger->warning(
					message: '[PrincipalReach] Could not read the permitted actions on a schema',
					context: ['schema' => $schema->getId(), 'error' => $e->getMessage()]
				);
				continue;
			}

			if ($actions === []) {
				continue;
			}

			$provenance = [];
			try {
				$provenance = $this->permissions->provenanceFor(
					schema: $schema,
					actions: $actions,
					userId: $principal
				);
			} catch (Throwable $e) {
				$this->logger->warning(
					message: '[PrincipalReach] Could not read the provenance of a grant',
					context: ['schema' => $schema->getId(), 'error' => $e->getMessage()]
				);
			}

			foreach ($actions as $action) {
				$entries[] = [
					'source' => PrincipalReach::SOURCE_AUTHORIZATION,
					'revocable' => PrincipalReach::isRevocable(PrincipalReach::SOURCE_AUTHORIZATION),
					'schema' => $schema->getId(),
					'schemaTitle' => $schema->getTitle(),
					'action' => $action,
					// The rule that decided, straight from the resolver. Without
					// it an administrator is told they can act and not why, and
					// cannot tell which rule to change.
					'grantedBy' => ($provenance[$action] ?? null),
				];
			}
		}

		return $entries;
	}//end fromAuthorization()

	/**
	 * The groups the principal belongs to, which rules then name.
	 *
	 * @param string $principal The account.
	 *
	 * @return array<int, array<string, mixed>> The entries.
	 */
	private function fromGroups(string $principal): array {
		$user = $this->userManager->get($principal);
		if ($user === null) {
			return [];
		}

		$entries = [];
		foreach ($this->groupManager->getUserGroupIds($user) as $groupId) {
			$entries[] = [
				'source' => PrincipalReach::SOURCE_GROUP,
				'revocable' => PrincipalReach::isRevocable(PrincipalReach::SOURCE_GROUP),
				'group' => $groupId,
				'note' => 'Listed, not revoked. Removing an account from a group changes the group '
					. 'and moves every other right it carries, so the revocation leaves it standing.',
			];
		}

		return $entries;
	}//end fromGroups()

	/**
	 * The grants derived from the claims this account signed in with.
	 *
	 * @param string $principal The account.
	 *
	 * @return array<int, array<string, mixed>> The entries.
	 */
	private function fromDerived(string $principal): array {
		$entries = [];

		try {
			$grants = $this->derived->grantsFor(userId: $principal);
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[PrincipalReach] Could not read the derived grants',
				context: ['principal' => $principal, 'error' => $e->getMessage()]
			);
			return [];
		}

		foreach ($grants as $grant) {
			$entries[] = [
				'source' => PrincipalReach::SOURCE_DERIVED,
				'revocable' => PrincipalReach::isRevocable(PrincipalReach::SOURCE_DERIVED),
				'grant' => $grant,
			];
		}

		return $entries;
	}//end fromDerived()

	/**
	 * The delegations this principal holds, excluding the ones already gone.
	 *
	 * @param string $principal The account.
	 *
	 * @return array<int, array<string, mixed>> The entries.
	 */
	private function fromDelegations(string $principal): array {
		$entries = [];
		$now = new DateTime();

		try {
			$held = $this->delegations->findHeldBy(principal: $principal);
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[PrincipalReach] Could not read the delegations held',
				context: ['principal' => $principal, 'error' => $e->getMessage()]
			);
			return [];
		}

		foreach ($held as $grant) {
			// A delegation that has lapsed or been revoked is not reach. Listing
			// it would inflate the count an administrator acts on and make the
			// revocation report removals that removed nothing.
			if ($grant->isLiveAt($now) === false) {
				continue;
			}

			$entries[] = [
				'source' => PrincipalReach::SOURCE_DELEGATION,
				'revocable' => PrincipalReach::isRevocable(PrincipalReach::SOURCE_DELEGATION),
				'uuid' => $grant->getUuid(),
				'actingAs' => $grant->getActingAs(),
				'scope' => ($grant->getScope() ?? []),
				'expiresAt' => $grant->getExpiresAt()?->format(DATE_ATOM),
			];
		}

		return $entries;
	}//end fromDelegations()

	/**
	 * Every schema the cascade can be asked about.
	 *
	 * @return array<int, Schema> The schemas.
	 */
	private function schemas(): array {
		try {
			return $this->schemaMapper->findAll();
		} catch (Throwable $e) {
			$this->logger->error(
				message: '[PrincipalReach] Could not list the schemas, so the reach cannot be answered',
				context: ['error' => $e->getMessage()]
			);
			return [];
		}
	}//end schemas()
}//end class
