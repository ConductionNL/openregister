<?php

/**
 * An address outside the product, as somebody adds it to an object.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Relation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Relation;

/**
 * An address outside the product: where it points, what it is called and what kind of link it is.
 *
 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
 */
final class ExternalLink {
	/**
	 * The link.
	 *
	 * @param string      $url          The address.
	 * @param string|null $title        What to call it.
	 * @param string|null $relationType The vocabulary key, when the schema names one.
	 * @param string|null $label        What the link reads as, when no schema can answer.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public function __construct(
		public readonly string $url,
		public readonly ?string $title = null,
		public readonly ?string $relationType = null,
		public readonly ?string $label = null,
	) {
	}//end __construct()
}//end class
