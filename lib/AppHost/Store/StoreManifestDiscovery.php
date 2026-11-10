<?php

/**
 * OpenRegister AppHost — Store Manifest Discovery
 *
 * How a declared store finds its items: shareable types, the source and its topics.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Store
 * @package  OCA\OpenRegister\AppHost\Store
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

namespace OCA\OpenRegister\AppHost\Store;

/**
 * How a store discovers items.
 *
 * @spec openspec/specs/apphost-store-plane/spec.md#requirement-a-leaf-app-must-declare-its-store-rather-than-implement-one
 */
final class StoreManifestDiscovery {

	/**
	 * Constructor.
	 *
	 * @param array<int, string> $types  Shareable configuration type ids this store surfaces.
	 * @param string             $source Discovery source: one of StoreManifest::SOURCES.
	 * @param array<int, string> $topics GitHub topics searched when $source is `github`.
	 */
	public function __construct(
		public readonly array $types = [],
		public readonly string $source = StoreManifest::DEFAULT_SOURCE,
		public readonly array $topics = [],
	) {
	}//end __construct()
}//end class
