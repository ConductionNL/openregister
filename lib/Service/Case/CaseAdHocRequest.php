<?php

/**
 * OpenRegister CaseAdHocRequest
 *
 * A caseworker's request to attach an item no author drew: on which case, which item, and who attaches it.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Case
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-cases/spec.md#requirement-a-caseworker-may-attach-work-no-author-drew
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Case;

/**
 * The anchoring object, the item and the acting identity of one ad-hoc attachment.
 *
 * @spec openspec/specs/flow-cases/spec.md#requirement-a-caseworker-may-attach-work-no-author-drew
 */
final class CaseAdHocRequest {

	/**
	 * Constructor.
	 *
	 * @param string               $objectUuid The anchoring object.
	 * @param array<string, mixed> $data       The item (`key`, `type`, `name`, `parent` uuid, criteria, candidates, deadlines).
	 * @param string|null          $actor      The acting identity.
	 */
	public function __construct(
		public readonly string $objectUuid,
		public readonly array $data,
		public readonly ?string $actor,
	) {
	}//end __construct()
}//end class
