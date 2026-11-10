<?php

/**
 * OpenRegister draft promotion
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
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

namespace OCA\OpenRegister\Service\Object;

/**
 * One request to make an owner's draft definitive: which draft, who asks, and the last changes laid over it.
 *
 * @spec openspec/changes/draft-owner-activation-and-lifetime/specs/form-destination/spec.md#requirement-a-drafts-owner-must-be-able-to-make-it-definitive-through-the-object-api
 */
final class DraftPromotion {
	/**
	 * Constructor.
	 *
	 * @param DraftAddress         $address The draft's register, schema and uuid.
	 * @param DraftOwner           $owner   The asserted owner (decision 195).
	 * @param array<string, mixed> $changes Last property values, laid over the stored draft before it is judged.
	 */
	public function __construct(
		public readonly DraftAddress $address,
		public readonly DraftOwner $owner,
		public readonly array $changes = [],
	) {
	}//end __construct()
}//end class
