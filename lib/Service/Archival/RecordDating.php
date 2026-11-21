<?php

/**
 * One record whose archiefactiedatum is being dated.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Archival
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Archival;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * The record, its schema's archive configuration, and the derivation method (afleidingswijze) that says where its brondatum comes from.
 *
 * @spec openspec/specs/retention-management/spec.md#requirement-the-system-must-calculate-archiefactiedatum-using-configurable-afleidingswijzen
 */
final class RecordDating {
	/**
	 * Hold the values.
	 *
	 * @param ObjectEntity $object          The record.
	 * @param array        $archive         The schema's archive configuration.
	 * @param mixed        $afleidingswijze The derivation method, as configured.
	 */
	public function __construct(
		public readonly ObjectEntity $object,
		public readonly array $archive,
		public readonly mixed $afleidingswijze,
	) {
	}//end __construct()
}//end class
