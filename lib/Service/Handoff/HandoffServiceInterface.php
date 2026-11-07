<?php

/**
 * HandoffServiceInterface: what callers outside the service layer use of HandoffService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Handoff
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Handoff;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\HandoffException;
use OCA\OpenRegister\Exception\NotAuthorizedException;

/**
 * Execute, list and drain declared semantic-object handoffs.
 *
 * Declares only the methods the controller, the queue-drain job and the
 * handoff listeners call; the engine's internals stay on the class.
 */
interface HandoffServiceInterface {

	/**
	 * Report, per declared handoff on the object's schema, whether it can run.
	 *
	 * @param string $register The register slug or id.
	 * @param string $schema   The schema slug or id.
	 * @param string $id       The object id/uuid.
	 *
	 * @return array<int, array<string, mixed>> One availability record per declared handoff.
	 *
	 * @throws \Exception When the object cannot be read by the caller.
	 *
	 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
	 */
	public function listAvailability(string $register, string $schema, string $id): array;

	/**
	 * Execute one declared handoff on a source object, or park it in queue mode.
	 *
	 * @param string      $register      The source register slug or id.
	 * @param string      $schema        The source schema slug or id.
	 * @param string      $id            The source object id/uuid.
	 * @param string      $handoffId     The declared handoff entry id.
	 * @param bool        $deferred      True when draining a parked queue entry.
	 * @param string|null $correlationId Correlation id to reuse (queue drain); minted when null.
	 *
	 * @return array<string, mixed> `{status: executed, ...}` or `{status: parked, ...}`.
	 *
	 * @throws HandoffException       For not-declared / provider-unavailable (hide mode).
	 * @throws NotAuthorizedException When the caller lacks write-on-source or create-on-target.
	 * @throws \Exception             Underlying write-path errors (surfaced after rollback).
	 *
	 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
	 */
	public function execute(
		string $register,
		string $schema,
		string $id,
		string $handoffId,
		bool $deferred = false,
		?string $correlationId = null,
	): array;

	/**
	 * Drain parked queue entries whose kind now resolves to a provider.
	 *
	 * @param string|null $kindUri Drain only entries parked for this kind (null = all parked kinds).
	 *
	 * @return array{drained: int, failed: int, skipped: int} Drain summary.
	 *
	 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
	 */
	public function drainParked(?string $kindUri = null): array;

	/**
	 * All `x-openregister-handoff` entries declared on a schema.
	 *
	 * @param Schema $schema The source schema.
	 *
	 * @return array<int, array<string, mixed>> The declared entries (empty when none).
	 *
	 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
	 */
	public function declaredHandoffs(Schema $schema): array;
}//end interface
