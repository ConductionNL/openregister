<?php

/**
 * The `next` hints a run answers with.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/macro-flows-with-next-item/specs/flow-engine/spec.md#requirement-a-manual-trigger-declares-where-the-person-goes-next
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

/**
 * The `next` hints a run answers with.
 *
 * FlowNextHint implements this, so FlowNextHint::STAY and the rest still
 * resolve; callers outside Service name these constants here.
 *
 * @spec openspec/changes/macro-flows-with-next-item/specs/flow-engine/spec.md#requirement-a-manual-trigger-declares-where-the-person-goes-next
 */
interface FlowNextHintsInterface {

	/**
	 * Stay on the record the macro ran against.
	 *
	 * @var string
	 */
	public const STAY = 'stay';

	/**
	 * Go to the next item in the list the person came from.
	 *
	 * @var string
	 */
	public const NEXT = 'next';

	/**
	 * Go back to the list.
	 *
	 * @var string
	 */
	public const LIST = 'list';

	/**
	 * The whole vocabulary.
	 *
	 * @var array<int, string>
	 */
	public const HINTS = [self::STAY, self::NEXT, self::LIST];
}//end interface
