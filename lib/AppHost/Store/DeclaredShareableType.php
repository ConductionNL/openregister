<?php

/**
 * OpenRegister AppHost — Declared Shareable Type
 *
 * A declared shareable type id together with the registered type that owns it.
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

use OCA\OpenRegister\Service\Config\IShareableConfigType;

/**
 * A store's declared type id and the shareable type registered under it.
 *
 * The catalogue reads both together for every discovery, card and slug, so
 * they travel as one value rather than as two loose parameters.
 */
final class DeclaredShareableType {
	/**
	 * Constructor.
	 *
	 * @param string               $typeId The declared type id.
	 * @param IShareableConfigType $type   The registered type that owns it.
	 */
	public function __construct(
		public readonly string $typeId,
		public readonly IShareableConfigType $type,
	) {
	}//end __construct()
}//end class
