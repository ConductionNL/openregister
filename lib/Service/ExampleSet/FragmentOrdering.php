<?php

/**
 * OpenRegister Fragment Ordering
 *
 * The app lists an example set's fragments are ordered by.
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
 * What {@see ExampleSetOrder::order()} orders: each fragment's `after`, the enabled apps, and the apps the set names.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-set-is-gathered-from-every-enabled-app-in-a-declared-order-req-exs-001
 */
final class FragmentOrdering {

	/**
	 * Constructor.
	 *
	 * @param array<string, array<int, string>> $after    The `after` list of each app that ships a fragment.
	 * @param array<int, string>                $enabled  Every enabled app id.
	 * @param array<int, string>                $expected Apps the set names as taking part, enabled or not.
	 */
	public function __construct(
		public readonly array $after,
		public readonly array $enabled,
		public readonly array $expected=[],
	) {
	}//end __construct()
}//end class
