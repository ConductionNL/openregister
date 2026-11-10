<?php

/**
 * The Talk group room a set's conversation lives in.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ExampleSet
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-put-talk-conversations-on-its-objects
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

/**
 * Whose room it is, its name, and who takes part.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-put-talk-conversations-on-its-objects
 */
final class ExampleSetTalkRoom {
	/**
	 * Constructor.
	 *
	 * @param string $owner The account that owns the room.
	 * @param string $name The room name.
	 * @param array<int, string> $participants The accounts taking part; the owner and unknown accounts are skipped.
	 */
	public function __construct(
		public readonly string $owner,
		public readonly string $name,
		public readonly array $participants,
	) {
	}//end __construct()
}//end class
