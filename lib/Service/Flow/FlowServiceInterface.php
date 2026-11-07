<?php

/**
 * FlowServiceInterface: what callers outside the Service component ask of FlowService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
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

namespace OCA\OpenRegister\Service\Flow;

use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Exception\FlowRunRefused;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * The request-facing, organisation-scoped flow surface, as the flow
 * controllers, the MCP flow tools and the scheduled-flow store use it. Only
 * the methods those callers call.
 */
interface FlowServiceInterface {

	/**
	 * List the caller's flows, newest first.
	 *
	 * @param string|null  $app             Restrict to one owning app id.
	 * @param string|null  $applicationSlug Restrict to one OpenBuild virtual-app slug.
	 * @param boolean|null $enabled         Restrict to enabled or disabled flows.
	 * @param integer      $limit           Page size.
	 * @param integer      $offset          Page offset.
	 *
	 * @return array<int, Flow> The flows visible to the caller.
	 */
	public function findAll(?string $app = null, ?string $applicationSlug = null, ?bool $enabled = null, int $limit = 100, int $offset = 0): array;

	/**
	 * Count the flows visible to the caller.
	 *
	 * @param string|null $app             Restrict to one owning app id.
	 * @param string|null $applicationSlug Restrict to one OpenBuild virtual-app slug.
	 *
	 * @return integer The number of matching flows.
	 */
	public function count(?string $app = null, ?string $applicationSlug = null): int;

	/**
	 * Load one flow the caller is allowed to see.
	 *
	 * @param string $uuid The flow uuid.
	 *
	 * @return Flow The flow.
	 *
	 * @throws DoesNotExistException When no such flow exists, or it is not the caller's.
	 */
	public function find(string $uuid): Flow;

	/**
	 * Refuse unless this caller may run this flow.
	 *
	 * @param Flow $flow The flow being run.
	 *
	 * @return void
	 *
	 * @throws FlowRunRefused When the caller may not run it.
	 */
	public function assertRunnable(Flow $flow): void;

	/**
	 * Make the calling user the flow's owner.
	 *
	 * @param Flow $flow The flow to adopt, already loaded through find().
	 *
	 * @return Flow The stored flow, now owned by the caller.
	 *
	 * @throws FlowAdoptionRefused When there is no acting user, or the flow belongs to someone else.
	 */
	public function adopt(Flow $flow): Flow;

	/**
	 * Create or update a flow.
	 *
	 * @param array<string, mixed> $data The flow's fields.
	 * @param string|null          $uuid The flow to update, or null to create.
	 *
	 * @return Flow The stored flow.
	 *
	 * @throws DoesNotExistException When updating a flow that is not the caller's.
	 * @throws \InvalidArgumentException When a trigger node rejects its own config.
	 * @throws FlowLifecycleRefused When the definition changes and the head is not a draft.
	 */
	public function save(array $data, ?string $uuid = null): Flow;

	/**
	 * Delete a flow the caller owns.
	 *
	 * @param string $uuid The flow uuid.
	 *
	 * @return void
	 *
	 * @throws DoesNotExistException When no such flow exists, or it is not the caller's.
	 */
	public function delete(string $uuid): void;

	/**
	 * Delete a stored flow with its trigger rows, runs, state and versions,
	 * without asking whose it is.
	 *
	 * @param Flow $flow The stored flow.
	 *
	 * @return void
	 */
	public function deleteStored(Flow $flow): void;

	/**
	 * Queue a manual run of a flow the caller owns.
	 *
	 * @param string               $uuid    The flow uuid.
	 * @param array<string, mixed> $subject `{uuid, register, schema}` of the object.
	 * @param array<string, mixed> $context Run-level metadata.
	 * @param boolean              $sync    Execute inline and return the finished run.
	 * @param string               $trigger The dispatch trigger recorded on the run.
	 *
	 * @return FlowRun The queued run, or the finished run when $sync is true.
	 *
	 * @throws DoesNotExistException When no such flow exists, or it is not the caller's.
	 * @throws FlowDeadEnd When a node's token has nowhere to go, so the run is refused.
	 * @throws FlowLifecycleRefused When the trigger requires a published version and there is none.
	 * @throws FlowRunRefused When the caller may not run this flow.
	 */
	public function run(string $uuid, array $subject = [], array $context = [], bool $sync = false, string $trigger = Flow::TRIGGER_MANUAL): FlowRun;
}//end interface
