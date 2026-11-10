<?php

/**
 * Class SearchTermSyntaxException
 *
 * Exception thrown when a full-text search term cannot be parsed.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Exception
 * @package   OCA\OpenRegister\Exception
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Exception;

use InvalidArgumentException;

/**
 * Thrown when a search term is malformed.
 *
 * A term the parser cannot read is refused rather than evaluated as a literal
 * string, because a literal fallback returns zero rows and looks exactly like
 * a search that found nothing. The refusal carries the 1-based character
 * position of the fault so the caller can point at it.
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/search-quality-operators-and-facets/specs/zoeken-filteren/spec.md
 */
class SearchTermSyntaxException extends InvalidArgumentException {
	/**
	 * Constructor.
	 *
	 * @param string $reason   What is wrong, without the position.
	 * @param int    $position The 1-based character position of the fault.
	 * @param string $term     The term as the caller typed it.
	 */
	public function __construct(
		private readonly string $reason,
		private readonly int $position,
		private readonly string $term = '',
	) {
		parent::__construct(message: $reason . ' at position ' . $position . '.');
	}//end __construct()

	/**
	 * The 1-based character position of the fault.
	 *
	 * @return int The position.
	 */
	public function getPosition(): int {
		return $this->position;
	}//end getPosition()

	/**
	 * The reason without the position suffix.
	 *
	 * @return string The reason.
	 */
	public function getReason(): string {
		return $this->reason;
	}//end getReason()

	/**
	 * The term that could not be parsed.
	 *
	 * @return string The term.
	 */
	public function getTerm(): string {
		return $this->term;
	}//end getTerm()
}//end class
