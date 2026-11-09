<?php

/**
 * OpenRegister XwikiPendingLink
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/integration-xwiki/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

/**
 * A remote xWiki page resolved for an object and not yet stored as a link: the object, the page's canonical reference and its normalised info.
 *
 * @spec openspec/specs/integration-xwiki/spec.md
 */
final class XwikiPendingLink {

	/**
	 * Constructor.
	 *
	 * @param LinkTarget          $target    The object the page is linked to.
	 * @param string              $reference Canonical xWiki page reference.
	 * @param array<string,mixed> $info      Normalised page info `{ reference, title, space, url }`.
	 */
	public function __construct(
		public readonly LinkTarget $target,
		public readonly string $reference,
		public readonly array $info,
	) {

	}//end __construct()
}//end class
