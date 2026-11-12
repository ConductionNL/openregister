<?php

/**
 * DeletionRetention — how long a soft-deleted object is kept, and which rule said so.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\Deletion
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Deletion;

/**
 * The stated retention of a soft-deleted object in days, with the rule that
 * produced it: the schema, the instance or the built-in default.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Deletion
 *
 * @spec openspec/specs/deletion-audit-trail/spec.md
 */
final class DeletionRetention {
	/**
	 * Name the retention.
	 *
	 * @param int    $days   The stated retention in days.
	 * @param string $source One of the DeletionWindow::SOURCE_* constants.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly int $days,
		public readonly string $source,
	) {
	}//end __construct()
}//end class
