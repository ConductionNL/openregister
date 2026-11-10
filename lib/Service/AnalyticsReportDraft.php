<?php

/**
 * OpenRegister AnalyticsReportDraft
 *
 * The report to create in NC Analytics before linking it to an object.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
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

/**
 * A report to create in NC Analytics: its name and optional report type.
 *
 * @spec openspec/specs/generic-integrations/spec.md
 */
final class AnalyticsReportDraft {

	/**
	 * Constructor.
	 *
	 * @param string   $name The report name (trimmed and checked by the service).
	 * @param int|null $type The Analytics report type, or null for the default group type.
	 */
	public function __construct(
		public readonly string $name,
		public readonly ?int $type = null,
	) {
	}//end __construct()
}//end class
