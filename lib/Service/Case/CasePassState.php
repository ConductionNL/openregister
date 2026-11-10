<?php

/**
 * What one pass of a case-plan evaluation reads.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Case
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-cases/spec.md#requirement-stages-nest-and-complete-by-a-written-rule
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Case;

/**
 * The plan, the anchor's data, the event and the actor one pass reads.
 *
 * @spec openspec/specs/flow-cases/spec.md#requirement-stages-nest-and-complete-by-a-written-rule
 */
final class CasePassState {

	/**
	 * The event being handled, as the pass started.
	 *
	 * @var string|null
	 */
	public readonly ?string $event;

	/**
	 * Its payload, as the pass started.
	 *
	 * @var array<string, mixed>
	 */
	public readonly array $payload;

	/**
	 * The triggering identity.
	 *
	 * @var string|null
	 */
	public readonly ?string $actor;

	/**
	 * Constructor.
	 *
	 * The event, payload and actor are taken from the run as this pass
	 * starts; a later consumption of the event does not reach this pass.
	 *
	 * @param CasePlanTree         $tree   The plan, freshly read.
	 * @param array<string, mixed> $object The anchor's data.
	 * @param CaseCascadeRun       $run    The evaluation this pass belongs to.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-stages-nest-and-complete-by-a-written-rule
	 */
	public function __construct(
		public readonly CasePlanTree $tree,
		public readonly array $object,
		CaseCascadeRun $run,
	) {
		$this->event = $run->event;
		$this->payload = $run->payload;
		$this->actor = $run->actor;
	}//end __construct()
}//end class
