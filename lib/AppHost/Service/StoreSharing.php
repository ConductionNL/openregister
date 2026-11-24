<?php

/**
 * OpenRegister AppHost — Store Sharing
 *
 * Immutable value object naming what an app's store SHARES: the shareable
 * configuration types that select federated discovery, and the fields and
 * groups that opt the store in to publishing. The default shares nothing.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\AppHost\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/apphost-store-plane/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\AppHost\Service;

/**
 * The sharing side of a store descriptor (ADR-080 Decision 2, ADR-011 Rule 2).
 *
 * @spec openspec/specs/apphost-store-plane/spec.md
 */
final class StoreSharing {
	/**
	 * Constructor.
	 *
	 * @param array<int, string> $types         Shareable configuration type ids the calling app declared.
	 *                                          A non-empty list selects federated discovery, where an item
	 *                                          is a configuration set, a flow or a schema that marked
	 *                                          itself shareable. An empty list keeps the remote objects
	 *                                          API, so an app that has not moved is untouched.
	 * @param array<int, string> $publishFields Remote object properties a publish may send, next to
	 *                                          the slug. Empty means the store cannot publish.
	 * @param array<int, string> $publishGroups Nextcloud groups whose members may publish, usually
	 *                                          the groups the app's own ADR-023 matrix holds for its
	 *                                          publish action. Empty means nobody may publish.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly array $types = [],
		public readonly array $publishFields = [],
		public readonly array $publishGroups = [],
	) {
	}//end __construct()
}//end class
