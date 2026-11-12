<?php

/**
 * OpenRegister Collective Page Text
 *
 * The title and Markdown of one Collectives page an example set writes.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ExampleSet
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-write-collectives-pages
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

/**
 * The title a page is found by and the Markdown a new page is written with.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-write-collectives-pages
 */
final class CollectivePageText {

	/**
	 * Constructor.
	 *
	 * @param string $title   The page title.
	 * @param string $content The page's Markdown.
	 */
	public function __construct(
		public readonly string $title,
		public readonly string $content,
	) {
	}//end __construct()
}//end class
