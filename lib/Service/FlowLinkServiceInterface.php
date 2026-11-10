<?php

/**
 * FlowLinkServiceInterface: what callers outside the Service component ask of FlowLinkService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use Exception;
use OCA\OpenRegister\Db\FlowLink;

/**
 * Links Nextcloud Flow (workflow engine) operations to objects.
 */
interface FlowLinkServiceInterface {

	/**
	 * Return Flow operations visible to the current user (picker source).
	 *
	 * Admin-only: non-admins receive an empty array (the picker UX is
	 * gated behind admin status). Optional substring search against the
	 * operation name.
	 *
	 * @param string|null $search Optional name-substring filter.
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function getAvailableOperations(?string $search = null): array;

	/**
	 * Return the linked Flow operations for an object, refreshing the
	 * cached `enabled` / `operation_name` / `entity_type` columns from
	 * `flow_operations` on every call.
	 *
	 * Available to everyone (read-only) — non-admins still see which
	 * automations are bound to an OR object even though they can't
	 * change them.
	 *
	 * @param string $objectUuid Parent OR object uuid.
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function getLinkedOperations(string $objectUuid): array;

	/**
	 * Whether the current user is an administrator.
	 *
	 * NC Flow operations are admin-configured globally, so we gate the
	 * mutating service methods on this flag.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function isCurrentUserAdmin(): bool;

	/**
	 * Whether NC WorkflowEngine is installed.
	 *
	 * @return bool
	 */
	public function isFlowAvailable(): bool;

	/**
	 * Link an existing Flow operation to an OR object.
	 *
	 * Admin-only. Idempotent: a duplicate link raises a 409 Exception.
	 *
	 * @param string $objectUuid Parent OR object uuid.
	 * @param int $registerId OR register id.
	 * @param int $schemaId OR schema id.
	 * @param int $operationId workflowengine operation id.
	 *
	 * @return FlowLink The persisted link row.
	 *
	 * @throws Exception On missing user, non-admin (403), missing operation (404),
	 *                   duplicate (409), Flow unavailable (503).
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function linkOperation(string $objectUuid, int $registerId, int $schemaId, int $operationId): FlowLink;

	/**
	 * Unlink an operation from an object.
	 *
	 * Admin-only. Does NOT delete the operation itself — it stays in
	 * NC Flow for other linked objects.
	 *
	 * @param string $objectUuid Parent OR object uuid.
	 * @param int $operationId workflowengine operation id.
	 *
	 * @return void
	 *
	 * @throws Exception On non-admin (403) or no matching link (404).
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function unlinkOperation(string $objectUuid, int $operationId): void;
}//end interface
