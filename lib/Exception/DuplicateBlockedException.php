<?php

/**
 * OpenRegister DuplicateBlockedException
 *
 * Raised when a schema declaring `x-openregister-dedup.onCreate: "block"`
 * refuses a create because the candidate strongly matches a stored object.
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
 * A create refused because it duplicates something already stored.
 *
 * Carries the matches so the caller can show what it collided with rather
 * than only that it collided: a refusal a user cannot act on is a dead end,
 * and the whole point of the declaration is to send the user to the existing
 * record instead of making a second one.
 *
 * HTTP 409 Conflict, for the same reason {@see ObjectExistsException} uses it:
 * the request conflicts with the current state of the collection (RFC 9110
 * 15.5.10). It is deliberately NOT 422 — the body is well formed and the
 * schema accepts it; what refuses is a policy about what is already there.
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
class DuplicateBlockedException extends Exception {

	/**
	 * The matches that caused the refusal.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $matches = [];

	/**
	 * Constructor.
	 *
	 * @param string $message Human-readable refusal.
	 * @param array<int, array<string, mixed>> $matches The stored objects the candidate matched.
	 * @param int $code HTTP status carried on the exception (default: 409 Conflict).
	 * @param Throwable|null $previous Previous exception, when chained.
	 *
	 * @return void
	 */
	public function __construct(
		string $message = 'This object duplicates one that already exists.',
		array $matches = [],
		int $code = 409,
		?Throwable $previous = null,
	) {
		$this->matches = $matches;

		parent::__construct(message: $message, code: $code, previous: $previous);
	}//end __construct()

	/**
	 * The matches that caused the refusal.
	 *
	 * @return array<int, array<string, mixed>> Each entry as
	 *         {@see \OCA\OpenRegister\Service\Quality\DuplicateDetectionService::checkCandidate()} returns it.
	 */
	public function getMatches(): array {
		return $this->matches;
	}//end getMatches()
}//end class
