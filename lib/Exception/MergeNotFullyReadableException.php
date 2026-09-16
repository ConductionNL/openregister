<?php

/**
 * OpenRegister MergeNotFullyReadableException
 *
 * Raised when a merge is requested by a caller who cannot read every property
 * the two objects carry.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Exception;

use Exception;
use Throwable;

/**
 * A merge refused because the merger cannot see all of what is being merged.
 *
 * The refusal names the PROPERTIES and never their values: telling a handler
 * which restricted field blocked them is a workable instruction, telling them
 * what it contains is the disclosure field-level security exists to prevent.
 *
 * HTTP 403. It is a permissions refusal, not a malformed request: the caller
 * may read both objects, and still may not decide what happens to a field
 * inside them.
 *
 * The failure it prevents is quiet. `MergeService` reads both objects through
 * the rendering path, which STRIPS properties the caller may not read, so
 * before this guard a handler without the medical domain merged two client
 * records and the medical field simply was not in the payload the merge
 * worked from.
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */
class MergeNotFullyReadableException extends Exception {

	/**
	 * The properties the caller may not read.
	 *
	 * @var array<int, string>
	 */
	private array $properties = [];

	/**
	 * Constructor.
	 *
	 * @param string $message The sentence naming the refusal.
	 * @param array<int, string> $properties The unreadable property names.
	 * @param int $code HTTP status carried on the exception (default: 403).
	 * @param Throwable|null $previous Previous exception, when chained.
	 *
	 * @return void
	 */
	public function __construct(
		string $message = 'This merge touches properties you may not read.',
		array $properties = [],
		int $code = 403,
		?Throwable $previous = null,
	) {
		$this->properties = array_values($properties);

		parent::__construct(message: $message, code: $code, previous: $previous);
	}//end __construct()

	/**
	 * The unreadable property names.
	 *
	 * @return array<int, string> The properties, never their values.
	 */
	public function getProperties(): array {
		return $this->properties;
	}//end getProperties()
}//end class
