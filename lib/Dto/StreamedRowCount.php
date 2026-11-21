<?php

/**
 * OpenRegister Streamed Row Count
 *
 * @category Dto
 * @package  OCA\OpenRegister\Dto
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Dto;

/**
 * How many rows a streaming bulk save was asked for and how many its status accounts for.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class StreamedRowCount {
	/**
	 * Name the two counts.
	 *
	 * @param int $requested Rows the caller submitted.
	 * @param int $accounted Rows the status accounts for (created + updated + unchanged).
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		public readonly int $requested,
		public readonly int $accounted,
	) {
	}//end __construct()
}//end class
