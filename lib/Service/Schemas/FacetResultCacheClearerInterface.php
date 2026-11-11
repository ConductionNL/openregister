<?php

/**
 * FacetResultCacheClearerInterface: what callers outside the Service component ask of FacetResultCacheClearer.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Schemas
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

namespace OCA\OpenRegister\Service\Schemas;

/**
 * Clearing cached facet results, as the schemas controller uses it. Only the
 * method that caller calls.
 */
interface FacetResultCacheClearerInterface {

	/**
	 * Clear every cached facet result and facet label.
	 *
	 * @return void
	 */
	public function clearAll(): void;
}//end interface
