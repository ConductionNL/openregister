<?php

/**
 * ContentReportServiceInterface: what callers outside the component use of ContentReportService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Audit
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

namespace OCA\OpenRegister\Service\Audit;

use OCA\OpenRegister\Db\ContentReport;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IUser;

/**
 * The content report operations the content report endpoints and the removal listener call.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own.
 */
interface ContentReportServiceInterface {

	/**
	 * The audit action recorded when a removal is matched to a copy.
	 *
	 * @var string
	 */
	public const ACTION_REMOVAL_NAMED = 'content-report.removal-copied';

	/**
	 * File a report, taking the copy now.
	 *
	 * @param ObjectEntity $object   The content being reported.
	 * @param string       $reason   Why, in the reporter's words.
	 * @param string|null  $reporter The uid of whoever filed it.
	 *
	 * @return ContentReport The persisted report, with the copy already taken.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) ContentReport::hashCopy is the entity's own checksum, kept static
	 *   so the copy and its later integrity check hash exactly the same way.
	 *
	 * @spec openspec/specs/enhanced-audit-trail/spec.md
	 */
	public function file(ObjectEntity $object, string $reason, ?string $reporter): ContentReport;

	/**
	 * Whether a user may read the copies on a report.
	 *
	 * @param ContentReport $report The report.
	 * @param IUser|null    $user   The caller.
	 *
	 * @return bool True when the caller is in the report's reviewer group.
	 *
	 * @spec openspec/specs/enhanced-audit-trail/spec.md
	 */
	public function mayReadCopy(ContentReport $report, ?IUser $user): bool;

	/**
	 * Read the copy, when the caller is a reviewer.
	 *
	 * @param ContentReport $report The report.
	 * @param IUser|null    $user   The caller.
	 *
	 * @return array<string, mixed>|null The copy, or null when the caller may not read it.
	 *
	 * @spec openspec/specs/enhanced-audit-trail/spec.md
	 */
	public function readCopy(ContentReport $report, ?IUser $user): ?array;

	/**
	 * The configured reviewer group.
	 *
	 * @return string The group id.
	 *
	 * @spec openspec/specs/enhanced-audit-trail/spec.md
	 */
	public function reviewerGroup(): string;

	/**
	 * Record that the reported content has been removed, on every open report.
	 *
	 * @param string      $objectUuid The removed object's uuid.
	 * @param string|null $auditUuid  The uuid of the audit entry recording the removal.
	 *
	 * @return string[] The uuids of the copies the removal should name.
	 *
	 * @spec openspec/specs/enhanced-audit-trail/spec.md
	 */
	public function noteRemoval(string $objectUuid, ?string $auditUuid = null): array;
}//end interface
