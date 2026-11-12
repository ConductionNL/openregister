<?php

/**
 * OpenRegister render enrichers
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
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

/**
 * What a render adds to the stored data: translations and derived values.
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */
final class RenderEnrichers {
	/**
	 * Bundle the services.
	 *
	 * @param RenderTranslations      $translations The translation services.
	 * @param RenderDerivations       $derivations  The derivation services.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function __construct(
		public readonly RenderTranslations $translations,
		public readonly RenderDerivations $derivations,
	) {
	}//end __construct()
}//end class
