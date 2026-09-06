<?php

/**
 * OpenRegister AppHost — Store discovery source.
 *
 * A store's discovery half, behind one seam. `GenericStoreService` used to BE
 * the OpenRegister source; splitting the interface out is what lets an app
 * declare `"source": "github"` and get a store that searches repositories by
 * topic without the app writing a controller.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Store
 * @package  OCA\OpenRegister\AppHost\Store\Source
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

namespace OCA\OpenRegister\AppHost\Store\Source;

use OCA\OpenRegister\AppHost\Store\StoreManifest;

/**
 * One way of finding store items.
 *
 * 🔴 A SOURCE NEVER THROWS FOR AN UPSTREAM PROBLEM. A registry that is down,
 * rate limited or talking nonsense is a fact to report to the reader, not a
 * server error in the app hosting the page: every implementation answers an
 * outcome and an empty card list instead. The only exceptions worth raising
 * are programming errors in the caller.
 *
 * @spec openspec/changes/store-plane-declarative-sources/specs/apphost-store-plane/spec.md#requirement-a-store-must-declare-its-discovery-source-defaulting-to-openregister
 */
interface StoreSourceInterface {
	/**
	 * The `source` value this implementation answers to.
	 *
	 * Matched against StoreManifest::SOURCES, so a source that names something
	 * outside that list can never be selected — the manifest would already
	 * have been rejected as malformed.
	 *
	 * @return string
	 */
	public function sourceId(): string;

	/**
	 * Search this source for store items.
	 *
	 * @param StoreManifest $manifest The declaring app's store block.
	 * @param string        $query    Free-text search, possibly empty.
	 * @param string|null   $kind     Kind filter, or null for every kind.
	 *
	 * @return array{outcome: string, cards: array<int, array<string, mixed>>}
	 */
	public function search(StoreManifest $manifest, string $query, ?string $kind): array;
}
