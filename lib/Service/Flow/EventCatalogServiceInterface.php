<?php

/**
 * EventCatalogServiceInterface: what callers outside the component use of EventCatalogService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

/**
 * The flow trigger catalogue the flow endpoints list.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own.
 */
interface EventCatalogServiceInterface {

	/**
	 * Return the catalog for the API/builder.
	 *
	 * @return array<int, array{id: string, label: string, group: string, legacy?: string}>
	 */
	public function getCatalog(): array;
}//end interface
