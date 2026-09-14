<?php

/**
 * DestructionRefusedException — a refusal that names the rule that refused.
 *
 * Every way a destruction can be refused answers on the same terms: a status
 * code, a machine-readable rule and a sentence a person can act on. "Forbidden"
 * with no rule is the shape that makes an operator guess, and guessing about a
 * destructive act is how the wrong thing gets destroyed next time.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\Deletion
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Deletion;

use Exception;
use Throwable;

/**
 * A destruction that was refused, carrying the rule that refused it.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Deletion
 *
 * @spec openspec/specs/deletion-audit-trail/spec.md
 */
class DestructionRefusedException extends Exception {
	/**
	 * Build a refusal.
	 *
	 * @param string               $rule       Machine-readable rule id, e.g. `destroy-right-missing`.
	 * @param string               $reason     The sentence a person reads.
	 * @param int                  $statusCode The HTTP status the caller should answer with.
	 * @param array<string, mixed> $context    Anything else the caller needs to act.
	 * @param Throwable|null       $previous   Previous exception.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly string $rule,
		private readonly string $reason,
		private readonly int $statusCode = 403,
		private readonly array $context = [],
		?Throwable $previous = null,
	) {
		parent::__construct(message: $reason, code: $statusCode, previous: $previous);
	}//end __construct()

	/**
	 * The rule that refused.
	 *
	 * @return string The rule id.
	 *
	 * @spec openspec/changes/delete-window-and-recorded-destruction/specs/deletion-audit-trail/spec.md
	 */
	public function getRule(): string {
		return $this->rule;
	}//end getRule()

	/**
	 * The HTTP status the caller should answer with.
	 *
	 * @return int The status code.
	 *
	 * @spec openspec/changes/delete-window-and-recorded-destruction/specs/deletion-audit-trail/spec.md
	 */
	public function getStatusCode(): int {
		return $this->statusCode;
	}//end getStatusCode()

	/**
	 * The refusal as the API publishes it.
	 *
	 * @return array<string, mixed> The response body.
	 *
	 * @spec openspec/changes/delete-window-and-recorded-destruction/specs/deletion-audit-trail/spec.md
	 */
	public function toResponseBody(): array {
		return array_merge(
			[
				'error' => 'DESTRUCTION_REFUSED',
				'rule' => $this->rule,
				'message' => $this->reason,
			],
			$this->context
		);
	}//end toResponseBody()
}//end class
