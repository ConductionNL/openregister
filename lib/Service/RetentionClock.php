<?php

/**
 * OpenRegister retention clock
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
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;

/**
 * A record, the schema that says where its retention period starts counting, and the period.
 *
 * @spec openspec/specs/retention-management/spec.md
 */
final class RetentionClock {
	/**
	 * Constructor.
	 *
	 * @param ObjectEntity $object          The object to calculate for.
	 * @param Schema       $schema          The schema with its afleidingswijze configuration.
	 * @param string       $retentionPeriod ISO 8601 duration (e.g. P5Y, P20Y).
	 */
	public function __construct(
		public readonly ObjectEntity $object,
		public readonly Schema $schema,
		public readonly string $retentionPeriod,
	) {
	}//end __construct()
}//end class
