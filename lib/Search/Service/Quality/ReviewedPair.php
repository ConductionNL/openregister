<?php

/**
 * OpenRegister reviewed pair
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Search\Service\Quality
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

namespace OCA\OpenRegister\Search\Service\Quality;

/**
 * Two objects a person reviewed, and the register and schema they reviewed them under.
 *
 * The uuids are kept in the order given; {@see DismissedPairStore::canonical()} orders them.
 *
 * @spec openspec/specs/duplicate-detection/spec.md#requirement-a-reviewed-pair-is-recorded-as-not-a-duplicate-and-stops-being-offered-req-dmd-003
 */
final class ReviewedPair {
	/**
	 * The pair.
	 *
	 * @param string $first        One uuid.
	 * @param string $second       The other uuid.
	 * @param string $registerSlug Register the pair was reviewed in.
	 * @param string $schemaSlug   Schema the pair was reviewed under.
	 *
	 * @spec openspec/specs/duplicate-detection/spec.md#requirement-a-reviewed-pair-is-recorded-as-not-a-duplicate-and-stops-being-offered-req-dmd-003
	 */
	public function __construct(
		public readonly string $first,
		public readonly string $second,
		public readonly string $registerSlug,
		public readonly string $schemaSlug,
	) {
	}//end __construct()
}//end class
