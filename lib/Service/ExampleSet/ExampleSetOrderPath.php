<?php

/**
 * OpenRegister Example Set Order Path
 *
 * One step of the search ExampleSetOrder runs to name the apps in a cycle:
 * where the search is, the app it looks for, and the apps it already
 * followed. Passed down the search as one value.
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
 * @spec openspec/specs/example-sets/spec.md#requirement-a-set-is-gathered-from-every-enabled-app-in-a-declared-order-req-exs-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

/**
 * Where a cycle search is.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-set-is-gathered-from-every-enabled-app-in-a-declared-order-req-exs-001
 */
class ExampleSetOrderPath {
	/**
	 * Constructor.
	 *
	 * @param string              $from   Where to go on from.
	 * @param string              $target The app looked for.
	 * @param array<string, bool> $seen   Apps already followed.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $from,
		public readonly string $target,
		public readonly array $seen=[],
	) {
	}//end __construct()

	/**
	 * The path one step further, at the given app.
	 *
	 * @param string $next The app the search goes on to.
	 *
	 * @return self
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-set-is-gathered-from-every-enabled-app-in-a-declared-order-req-exs-001
	 */
	public function step(string $next): self {
		return new self(from: $next, target: $this->target, seen: ($this->seen + [$next => true]));
	}//end step()
}//end class
