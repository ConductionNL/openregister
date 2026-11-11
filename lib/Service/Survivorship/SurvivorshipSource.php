<?php

/**
 * The source system and freshness anchor of one source record.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Survivorship
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Survivorship;

use DateTimeImmutable;

/**
 * Where one source record's values come from and how fresh they are.
 *
 * @spec openspec/changes/mdm-survivorship-engine/tasks.md#2.1
 */
final readonly class SurvivorshipSource {

	/**
	 * Bundle the source.
	 *
	 * @param string                 $sourceSystem The source system.
	 * @param DateTimeImmutable|null $anchor       The record's freshness anchor, null when absent.
	 *
	 * @spec openspec/changes/mdm-survivorship-engine/tasks.md#2.1
	 */
	public function __construct(
		public string $sourceSystem,
		public ?DateTimeImmutable $anchor,
	) {
	}//end __construct()
}//end class
