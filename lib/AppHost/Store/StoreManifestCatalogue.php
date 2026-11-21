<?php

/**
 * OpenRegister AppHost — Store Manifest Catalogue
 *
 * What a declared store offers: the installable allowlist, kinds, card fields and built-in items.
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
 * The items a store offers and how they are shown.
 *
 * @spec openspec/specs/apphost-store-plane/spec.md#requirement-a-leaf-app-must-declare-its-store-rather-than-implement-one
 */
final class StoreManifestCatalogue {

	/**
	 * Constructor.
	 *
	 * @param array<int, string>               $installable Schema slugs an install may write.
	 * @param array<int, string>               $kinds       Kind quick-filters offered on the page.
	 * @param array<string, string>            $cardFields  Remote field to card field map.
	 * @param array<int, array<string, mixed>> $builtIn     The app's own items, for the no-registry case.
	 */
	public function __construct(
		public readonly array $installable = [],
		public readonly array $kinds = [],
		public readonly array $cardFields = StoreManifest::DEFAULT_CARD_FIELDS,
		public readonly array $builtIn = [],
	) {
	}//end __construct()
}//end class
