<?php

/**
 * FacetCacheVersionInterface: what callers outside the Service component ask of FacetCacheVersion.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

/**
 * The write side of the facet cache version: the invalidation listener bumps
 * a scope when an object in it is written.
 *
 * @spec openspec/specs/faceting-configuration/spec.md#requirement-an-object-write-must-invalidate-the-facet-response-derived-from-it
 */
interface FacetCacheVersionInterface {

	/**
	 * Record that an object was written in a (register, schema) scope.
	 *
	 * @param string|null $register Register id the written object belongs to.
	 * @param string|null $schema   Schema id the written object belongs to.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-an-object-write-must-invalidate-the-facet-response-derived-from-it
	 */
	public function bump(?string $register, ?string $schema): void;
}//end interface
