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
	 * The search term, for relevance ordering.
	 *
	 * @var string|null
	 */
	public ?string $searchTerm = null;

	/**
	 * The read history ordering a `_recent` page (uuid => last read), or null.
	 *
	 * @var mixed
	 */
	public mixed $recentViews = null;

	/**
	 * Columns whose real type is a date or timestamp; null when unknown.
	 *
	 * @var array<int, string>|null
	 */
	public ?array $dateTimeColumns = null;

	/**
	 * Language chain a translatable property sorts by.
	 *
	 * @var array<int, string>
	 */
	public array $sortLanguages = [];

	/**
	 * Constructor.
	 *
	 * The search term, read history, date-time columns and language chain
	 * are set one by one by the search that knows them.
	 *
	 * @param array<int|string, mixed> $order  The requested order, field => direction.
	 * @param Schema                   $schema The schema being searched.
	 */
	public function __construct(
		public readonly array $order,
		public readonly Schema $schema,
	) {
	}//end __construct()
}//end class
