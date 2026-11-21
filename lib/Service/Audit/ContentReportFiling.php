<?php

/**
 * OpenRegister ContentReportFiling
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

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * One content report being filed against an object.
 *
 * The operation object ContentReportService::file() builds from its interface signature.
 */
final class ContentReportFiling {

	/**
	 * Constructor.
	 *
	 * @param ObjectEntity $object   The reported object.
	 * @param string       $reason   Why it was reported.
	 * @param string|null  $reporter Who reported it, or null for an anonymous report.
	 */
	public function __construct(
		public readonly ObjectEntity $object,
		public readonly string $reason,
		public readonly ?string $reporter,
	) {
	}//end __construct()
}//end class
