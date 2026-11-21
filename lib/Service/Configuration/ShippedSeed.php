<?php

/**
 * A seed object as an import lists it, with the hash of the content last shipped for it.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Configuration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Configuration;

/**
 * The shipped seed content and the remembered hash {@see ShippedSeedContent} compares it with.
 *
 * @spec openspec/specs/data-import-export/spec.md
 */
final class ShippedSeed {

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $content    The seed as listed, with `@self`.
	 * @param string|null          $remembered The hash of the content last shipped; null when none was kept.
	 */
	public function __construct(
		public readonly array $content,
		public readonly ?string $remembered,
	) {
	}//end __construct()
}//end class
