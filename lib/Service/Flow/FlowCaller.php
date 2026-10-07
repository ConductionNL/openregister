<?php

/**
 * Who is asking about flows, and which of them are theirs.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use OCA\OpenRegister\Db\FlowMapper;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * The caller's identity, their organisation, and the flows they own.
 *
 * 🔴 ONE PLACE DECIDES OWNERSHIP, AND IT HAS ALWAYS HAD TWO READERS.
 * `FlowService::save()` is not the only path that inserts a Flow:
 * `FlowShareableConfigType::deserialise()` writes one when a federated bundle
 * is installed, and it used to stamp nulls, reproducing on that path the
 * permanent orphan the refusal exists to prevent. Two writers each deriving
 * ownership their own way is how a rule comes to hold on one of them and not
 * the other, so the derivation lives here and both ask it.
 *
 * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
 */
class FlowCaller {

	/**
	 * Constructor.
	 *
	 * @param FlowMapper         $mapper      Reads which flows a uid owns.
	 * @param IUserSession       $userSession Identifies the acting user.
	 * @param ContainerInterface $container   Resolves OrganisationService lazily.
	 * @param LoggerInterface    $logger      Records an unreadable ownership listing.
	 */
	public function __construct(
		private readonly FlowMapper $mapper,
		private readonly IUserSession $userSession,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The ids of the flows the acting user owns.
	 *
	 * Used by the run-history visibility rule, which shows a caller the runs
	 * they triggered PLUS the runs of flows they own — the second half matters
	 * because `triggered_by` is null for cron- and trigger-fired runs.
	 *
	 * @return array<int, string> The flow uuids.
	 *
	 * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
	 */
	public function idsOwnedByCaller(): array {
		$uid = $this->actingUser();
		if ($uid === null) {
			return [];
		}

		try {
			return $this->mapper->findIdsOwnedBy($uid);
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[FlowCaller] Could not list the caller\'s owned flows: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return [];
		}

	}//end idsOwnedByCaller()

	/**
	 * The owner and organisation a flow written by THIS caller must carry.
	 *
	 * 🔴 IT HAS A SECOND READER. `FlowService::flowToSave()` is not the only
	 * path that inserts a Flow: `FlowShareableConfigType::deserialise()` writes
	 * one when a federated bundle is installed, and it used to stamp nulls —
	 * reproducing, on that path, the permanent orphan the refusal below exists
	 * to prevent. Two writers each deriving ownership their own way is how the
	 * rule came to hold on one of them and not the other; this is the one place
	 * that decides it.
	 *
	 * @return array{owner: string|null, organisation: string|null} The caller's ownership, either field null when it does not resolve.
	 *
	 * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
	 */
	public function ownership(): array {
		return [
			'owner'        => $this->actingUser(),
			'organisation' => $this->activeOrganisation(),
		];
	}//end ownership()

	/**
	 * The acting user's uid, or null when there is no session.
	 *
	 * @return string|null The uid.
	 *
	 * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
	 */
	public function actingUser(): ?string {
		$uid = (string)($this->userSession->getUser()?->getUID() ?? '');
		if ($uid === '') {
			return null;
		}

		return $uid;
	}//end actingUser()

	/**
	 * The caller's active organisation uuid, or null when none resolves.
	 *
	 * Resolved lazily through the container for the same reason
	 * `FlowRunService` does it: this service is reachable from paths that run
	 * without a session, and dragging the whole organisation/RBAC graph in to
	 * read a value that will be null there is wasted work.
	 *
	 * @return string|null The organisation uuid.
	 *
	 * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
	 */
	public function activeOrganisation(): ?string {
		try {
			$organisationService = $this->container->get('OCA\OpenRegister\Service\OrganisationService');
			$uuid = $organisationService->getActiveOrganisation()?->getUuid();
		} catch (Throwable $e) {
			$this->logger->debug(
				message: '[FlowService] Could not resolve the active organisation: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return null;
		}

		if ((string)$uuid === '') {
			return null;
		}

		return (string)$uuid;
	}//end activeOrganisation()

}//end class
