<?php

/**
 * OpenRegister AppHost — Declared Shareable Type
 *
 * A declared shareable type id together with what the store reads of the type that owns it.
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
 * A store's declared type id and what the store reads of the shareable type registered under it.
 *
 * The catalogue reads them together for every discovery, card and slug, so
 * they travel as one value rather than as loose parameters. The shareable
 * configuration catalogue builds it from the registered type.
 */
final class DeclaredShareableType {
	/**
	 * Constructor.
	 *
	 * @param string $typeId   The declared type id.
	 * @param string $typeName The display name of the registered type that owns it.
	 * @param string $topic    The discovery topic of that type.
	 */
	public function __construct(
		public readonly string $typeId,
		public readonly string $typeName,
		public readonly string $topic,
	) {
	}//end __construct()
}//end class
