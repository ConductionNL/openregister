<?php

/**
 * IsoTimestampsTrait: the created and updated dates as ISO 8601 strings.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

/**
 * Format an entity's created and updated dates as ISO 8601 strings.
 */
trait IsoTimestampsTrait {

	/**
	 * Get created date formatted as ISO 8601 string or null
	 *
	 * @return string|null Formatted date or null
	 *
	 * @spec exclude shared entity plumbing, not a capability of its own
	 */
	private function getCreatedFormatted(): ?string {
		if ($this->created !== null) {
			return $this->created->format('c');
		}

		return null;
	}//end getCreatedFormatted()

	/**
	 * Get updated date formatted as ISO 8601 string or null
	 *
	 * @return string|null Formatted date or null
	 *
	 * @spec exclude shared entity plumbing, not a capability of its own
	 */
	private function getUpdatedFormatted(): ?string {
		if ($this->updated !== null) {
			return $this->updated->format('c');
		}

		return null;
	}//end getUpdatedFormatted()
}//end trait
