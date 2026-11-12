<?php

/**
 * OpenRegister PartyAddressUnionRun
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
 * @spec openspec/specs/mdm-merge/spec.md#requirement-parties-merge-through-the-existing-merge-primitive-req-prm-005
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Listener\Support\PartyCarryOver;

/**
 * One merged-away party's addresses being united onto the survivor.
 *
 * Built by {@see PartyMergeListener} once both parties were found; it never
 * leaves that class (ADR-011 Rule 3, modelled on CascadeRun).
 *
 * @spec openspec/specs/mdm-merge/spec.md#requirement-parties-merge-through-the-existing-merge-primitive-req-prm-005
 */
final class PartyAddressUnionRun {

	/**
	 * The surviving party.
	 *
	 * @var ObjectEntity|null
	 */
	public ?ObjectEntity $survivor = null;

	/**
	 * The party merged away.
	 *
	 * @var ObjectEntity|null
	 */
	public ?ObjectEntity $loser = null;

	/**
	 * The carry-over: survivor uuid and the survivor's party declaration.
	 *
	 * @var PartyCarryOver|null
	 */
	public ?PartyCarryOver $step = null;
}//end class
