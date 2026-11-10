<?php

/**
 * OpenRegister SearchRenderRequest
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
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/zoeken-filteren/spec.md
 */

namespace OCA\OpenRegister\Service\Object;

/**
 * The query and flags a QueryHandler::searchObjects() result is shaped with: the `_count` answer,
 * or the render that applies `_extend`, `_fields`, `_filter`, `_unset` and property authorization.
 *
 * @spec openspec/specs/zoeken-filteren/spec.md
 */
final class SearchRenderRequest {

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $query        The search query the result was found with.
	 * @param bool                 $rbac         Whether the render applies RBAC.
	 * @param bool                 $multitenancy Whether the render applies multitenancy.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	public function __construct(
		public readonly array $query,
		public readonly bool $rbac,
		public readonly bool $multitenancy,
	) {
	}//end __construct()
}//end class
