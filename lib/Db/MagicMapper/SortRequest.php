<?php

/**
 * SortRequest
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Db
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\MagicMapper;

use OCA\OpenRegister\Db\Schema;

/**
 * The order one search asks for: the requested fields and directions, the
 * schema they belong to, and what ordering them needs to know.
 *
 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-multi-field-sorting-with-metadata-and-relevance-support
 */
final class SortRequest {

	/**
	 * Constructor.
	 *
	 * @param array<int|string, mixed> $order           The requested order, field => direction.
	 * @param Schema                   $schema          The schema being searched.
	 * @param string|null              $searchTerm      The search term, for relevance ordering.
	 * @param mixed                    $recentViews     The read history ordering a `_recent` page (uuid => last read), or null.
	 * @param array<int, string>|null  $dateTimeColumns Columns whose real type is a date or timestamp; null when unknown.
	 * @param array<int, string>       $sortLanguages   Language chain a translatable property sorts by.
	 */
	public function __construct(
		public readonly array $order,
		public readonly Schema $schema,
		public readonly ?string $searchTerm = null,
		public readonly mixed $recentViews = null,
		public readonly ?array $dateTimeColumns = null,
		public readonly array $sortLanguages = [],
	) {
	}//end __construct()
}//end class
