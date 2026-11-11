<?php

/**
 * TenantLogRedactorInterface: what callers outside the component use of TenantLogRedactor.
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
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

/**
 * Declares only what callers outside the component use of TenantLogRedactor; the rest
 * of the class stays its own.
 */
interface TenantLogRedactorInterface {

	/**
	 * Clean one log context, or refuse it.
	 *
	 * @param array<string, mixed> $context The context about to be logged.
	 *
	 * @return array<string, mixed>|null The cleaned context, or null when the line must be dropped.
	 *
	 * @spec openspec/changes/several-legal-entities-in-one-instance/specs/tenant-isolation-audit/spec.md#requirement-a-log-line-names-the-tenant-pseudonymously-and-never-carries-a-secret-req-sle-003
	 */
	public function line(array $context): ?array;

	/**
	 * A stable, one-way reference to a user, for a log line.
	 *
	 * @param string|null $userId The Nextcloud user id, or null for an anonymous or system actor.
	 *
	 * @return string The pseudonymous reference.
	 *
	 * @spec openspec/changes/several-legal-entities-in-one-instance/specs/tenant-isolation-audit/spec.md#requirement-a-log-line-names-the-tenant-pseudonymously-and-never-carries-a-secret-req-sle-003
	 */
	public function pseudonym(?string $userId): string;
}//end interface
