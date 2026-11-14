<?php

/**
 * One preservation listing: the lens mode, the schema listed and what the table offers.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/object-lifecycle/spec.md#requirement-a-preservation-regime-sits-between-active-and-transferred-req-aps-006
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\Schema;

/**
 * The inputs of {@see PreservedListLens::clauseFor()}.
 *
 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/object-lifecycle/spec.md#requirement-a-preservation-regime-sits-between-active-and-transferred-req-aps-006
 */
final class PreservedListing {

	/**
	 * Constructor.
	 *
	 * @param string      $mode          One of the PreservedListLens modes.
	 * @param Schema|null $schema        The schema listed; the lens applies only where it offers the regime.
	 * @param string      $platform      The platform short name: postgres, mysql or sqlite.
	 * @param bool        $columnMissing Whether the table lacks the `_tmlo` column.
	 */
	public function __construct(
		public readonly string $mode,
		public readonly ?Schema $schema,
		public readonly string $platform,
		public readonly bool $columnMissing = false,
	) {
	}//end __construct()
}//end class
