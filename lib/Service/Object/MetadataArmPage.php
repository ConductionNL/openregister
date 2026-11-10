<?php

/**
 * OpenRegister MetadataArmPage
 *
 * The page the metadata arm of a search already served, before content matches widen it.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/zoeken-filteren/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * One page of metadata-match rows, with the arm's total and the page window.
 *
 * @spec openspec/specs/zoeken-filteren/spec.md
 */
final class MetadataArmPage {

	/**
	 * Constructor.
	 *
	 * @param ObjectEntity[] $results The metadata-match rows of this page.
	 * @param int            $total   The metadata arm's total.
	 * @param int            $limit   The page's `_limit` (0 = count-only).
	 * @param int            $offset  The page's `_offset` over the combined list.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	public function __construct(
		public readonly array $results,
		public readonly int $total,
		public readonly int $limit,
		public readonly int $offset=0,
	) {
	}//end __construct()

	/**
	 * The page as the result shape, unchanged.
	 *
	 * @return array{results: ObjectEntity[], total: int}
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	public function unchanged(): array {
		return ['results' => $this->results, 'total' => $this->total];
	}//end unchanged()
}//end class
