<?php

/**
 * AnalyticsLinkServiceInterface: the Analytics link operations of AnalyticsLinkService that controllers use.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
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

namespace OCA\OpenRegister\Service;

use Exception;
use OCA\OpenRegister\Db\AnalyticsLink;

/**
 * The methods of AnalyticsLinkService that callers outside the Service component use.
 */
interface AnalyticsLinkServiceInterface {

	/**
	 * Whether NC Analytics is installed + enabled for the current user.
	 *
	 * @return bool
	 */
	public function isAnalyticsAvailable(): bool;

	/**
	 * Link an existing NC Analytics report to an OR object.
	 *
	 * Idempotent: a duplicate link raises a 409 Exception. Report
	 * metadata (title/type/subheader/created/modified) is cached at link
	 * time.
	 *
	 * @param LinkTarget $target   The object to link to.
	 * @param int        $reportId NC Analytics report id.
	 *
	 * @return AnalyticsLink The persisted link row.
	 *
	 * @throws Exception On missing user (401), missing report (404),
	 *                   duplicate (409), Analytics unavailable (503).
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function linkReport(LinkTarget $target, int $reportId): AnalyticsLink;

	/**
	 * Create a new NC Analytics report and link it to an OR object.
	 *
	 * The new report is a blank group/folder report by default; callers
	 * may pass a different NC Analytics datasource type integer via
	 * `$type`.
	 *
	 * @param LinkTarget $target The object to link to.
	 * @param AnalyticsReportDraft $draft The report name and optional datasource type (default group).
	 *
	 * @return AnalyticsLink The persisted link row.
	 *
	 * @throws Exception On missing user (401), empty name (400),
	 *                   Analytics unavailable (503), create failure (500).
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function createAndLinkReport(LinkTarget $target, AnalyticsReportDraft $draft): AnalyticsLink;

	/**
	 * Unlink a report from an object.
	 *
	 * Does NOT delete the report itself — it stays in NC Analytics for
	 * the user and for any other linked objects.
	 *
	 * @param string $objectUuid Parent OR object uuid.
	 * @param int $reportId NC Analytics report id.
	 *
	 * @return void
	 *
	 * @throws Exception On missing user (401) or no matching link (404).
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function unlinkReport(string $objectUuid, int $reportId): void;

	/**
	 * Return the linked reports for an object, refreshing the cached
	 * title/type/subheader/modified columns when a row is older than 24h.
	 *
	 * When Analytics is unavailable the cached link-row values are
	 * returned unchanged.
	 *
	 * @param string $objectUuid Parent OR object uuid.
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function getLinkedReports(string $objectUuid): array;

	/**
	 * Return the current user's NC Analytics reports (picker source).
	 *
	 * Optional substring search against the report name. Returns an empty
	 * array when Analytics is unavailable.
	 *
	 * @param string|null $search Optional name-substring filter.
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function getAvailableReports(?string $search = null): array;
}//end interface
