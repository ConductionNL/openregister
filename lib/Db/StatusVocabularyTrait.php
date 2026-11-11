<?php

/**
 * StatusVocabularyTrait: check a status against an entity's vocabulary.
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
 * Check a status against the STATUS_VOCABULARY constant of the entity that uses this trait.
 */
trait StatusVocabularyTrait {

	/**
	 * Whether a status string is in this entity's STATUS_VOCABULARY.
	 *
	 * @param string|null $status Candidate status string.
	 *
	 * @return bool True when the status is one this entity recognises.
	 *
	 * @spec exclude shared entity plumbing, not a capability of its own
	 */
	public static function isValidStatus(?string $status): bool {
		if ($status === null || $status === '') {
			return false;
		}

		return in_array(needle: $status, haystack: self::STATUS_VOCABULARY, strict: true);
	}//end isValidStatus()
}//end trait
