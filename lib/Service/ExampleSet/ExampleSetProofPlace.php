<?php

/**
 * OpenRegister Example Set Proof Place
 *
 * One place in the comparison ExampleSetProof makes: what was read back there,
 * and the dot path that names it.
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
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-an-install-proves-what-arrived-req-exs-006
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

/**
 * The stored value at one place, and its path.
 */
class ExampleSetProofPlace {
	/**
	 * Constructor.
	 *
	 * @param mixed  $stored What was read back.
	 * @param string $path   The dot path so far.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly mixed $stored,
		public readonly string $path,
	) {
	}//end __construct()

	/**
	 * The dot path of a key below this place.
	 *
	 * @param int|string $key The key.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-an-install-proves-what-arrived-req-exs-006
	 */
	public function pathOf(int|string $key): string {
		return ltrim($this->path.'.'.$key, '.');
	}//end pathOf()
}//end class
