<?php
/**
 * AppDeepLinksInterface: the deep link, icon and app label registered for a register/schema pair.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts\Core
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Core;

use OCA\OpenRegister\Service\DeepLinkResolverInterface;

/**
 * What Core asks of the deep link registry to present an object as the app that owns it.
 *
 * Core search builds each result with the owning app's link, icon and label; the
 * registry lives in Integrations and binds its answer in its own wiring.
 *
 * @spec openspec/specs/deep-link-registry/spec.md#requirement-deep-link-registry-shall-resolve-urls-for-unified-search-results
 */
interface AppDeepLinksInterface extends DeepLinkResolverInterface {

	/**
	 * The label of the app that claimed a register/schema pair: its display name, else its app id.
	 *
	 * @param int $registerId The register database ID.
	 * @param int $schemaId   The schema database ID.
	 *
	 * @return string|null The label, or null when no app claimed the pair.
	 *
	 * @spec openspec/specs/deep-link-registry/spec.md
	 */
	public function resolveDisplayName(int $registerId, int $schemaId): ?string;
}//end interface
