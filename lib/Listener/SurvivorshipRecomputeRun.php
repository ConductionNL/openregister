<?php

/**
 * OpenRegister SurvivorshipRecomputeRun
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/mdm-survivorship-engine/tasks.md#4.1
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;

/**
 * One golden-record recompute of a master object whose schema declares survivorship.
 *
 * Built by {@see SurvivorshipRecomputeListener} once the annotation resolved;
 * it never leaves that class (ADR-011 Rule 3, modelled on CascadeRun).
 *
 * @spec openspec/changes/mdm-survivorship-engine/tasks.md#4.1
 */
final class SurvivorshipRecomputeRun {

	/**
	 * The master object being created or updated.
	 *
	 * @var ObjectEntity|null
	 */
	public ?ObjectEntity $object = null;

	/**
	 * The master's schema.
	 *
	 * @var Schema|null
	 */
	public ?Schema $schema = null;

	/**
	 * The schema's survivorship annotation.
	 *
	 * @var array<string,mixed>
	 */
	public array $config = [];

	/**
	 * The master's current data.
	 *
	 * @var array<string,mixed>
	 */
	public array $data = [];
}//end class
