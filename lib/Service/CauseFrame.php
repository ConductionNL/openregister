<?php

/**
 * OpenRegister CauseFrame
 *
 * One frame on the write-cause stack: why the writes inside it happen, and
 * the run they belong to.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

/**
 * The cause and run {@see WriteCause::runAs()} pushes for the duration of one operation.
 *
 * @spec openspec/changes/runs-recorded-and-causes-named/specs/enhanced-audit-trail/spec.md#requirement-every-audit-entry-names-the-cause-of-the-write-req-rcn-001
 */
final class CauseFrame {

	/**
	 * Constructor.
	 *
	 * @param string      $cause One of {@see WriteCause::ALL}.
	 * @param string|null $run   The run the writes belong to, when there is one.
	 */
	public function __construct(
		public readonly string $cause,
		public readonly ?string $run = null,
	) {
	}//end __construct()
}//end class
