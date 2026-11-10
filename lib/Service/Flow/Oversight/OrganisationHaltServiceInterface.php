<?php

/**
 * The organisation flow halts the halt endpoints engage, release and list.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Oversight
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/organisation-capability-halt/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Oversight;

use InvalidArgumentException;

/**
 * The organisation flow halts the halt endpoints engage, release and list.
 *
 * Bound to OrganisationHaltService in ContractBindings. Every method is described on OrganisationHaltService.
 */
interface OrganisationHaltServiceInterface {

	/**
	 * Engage a halt as the signed-in user.
	 *
	 * Who may engage is the caller's decision (OpenRegister's route lets an
	 * administrator; an app may let an organisation's owner); this method only
	 * refuses what it could not record properly.
	 *
	 * @param HaltRequest $request The organisation, app, reason and node-type prefix ('' means "<app>."); trimmed before use.
	 *
	 * @return array<string, string> The halt.
	 *
	 * @throws InvalidArgumentException Without an actor, organisation, app or reason, or when one is already engaged.
	 *
	 * @spec openspec/changes/organisation-capability-halt/specs/flow-engine/spec.md
	 */
	public function engage(HaltRequest $request): array;

	/**
	 * Release a halt as the signed-in user.
	 *
	 * @param string $id The halt id.
	 *
	 * @return bool False when no such halt is engaged.
	 *
	 * @throws InvalidArgumentException Without an actor.
	 *
	 * @spec openspec/changes/organisation-capability-halt/specs/flow-engine/spec.md
	 */
	public function release(string $id): bool;

	/**
	 * The engaged halts, optionally for one organisation.
	 *
	 * @param string|null $organisation The organisation uuid, or null for all.
	 *
	 * @return array<int, array<string, string>> The halts.
	 *
	 * @spec openspec/changes/organisation-capability-halt/specs/flow-engine/spec.md
	 */
	public function list(?string $organisation=null): array;
}//end interface
