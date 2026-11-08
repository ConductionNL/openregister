<?php

/**
 * ErasureRefusedException — a refused erasure that names the rule that refused.
 *
 * The sibling of {@see \OCA\OpenRegister\Service\Deletion\DestructionRefusedException}
 * for the act one layer up: a destruction refusal is about one object, an
 * erasure refusal is about the request. Both answer on the same terms — a
 * status code, a machine-readable rule and a sentence a person can act on —
 * because a handler who is told "Forbidden" with no rule has to guess, and
 * guessing about an erasure is how the wrong person's records go.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\Gdpr\Erasure
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Gdpr\Erasure;

use Exception;
use Throwable;

/**
 * An erasure that was refused, carrying the rule that refused it.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Gdpr\Erasure
 *
 * @spec openspec/specs/gdpr-data-subject-rights/spec.md
 */
class ErasureRefusedException extends Exception {
	/**
	 * The `error` label every erasure refusal publishes.
	 *
	 * @var string
	 */
	private const ERROR = 'ERASURE_REFUSED';

	/**
	 * Build a refusal.
	 *
	 * The sentence and the status code are kept where Exception keeps them —
	 * the message and the code — so there is one copy of each.
	 *
	 * @param string               $rule       Machine-readable rule id, e.g. `erasure-not-approved`.
	 * @param string               $reason     The sentence a person reads.
	 * @param int                  $statusCode The HTTP status the caller should answer with.
	 * @param array<string, mixed> $context    Anything else the caller needs to act.
	 * @param Throwable|null       $previous   Previous exception.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly string $rule,
		string $reason,
		int $statusCode = 409,
		private readonly array $context = [],
		?Throwable $previous = null,
	) {
		parent::__construct(message: $reason, code: $statusCode, previous: $previous);
	}//end __construct()

	/**
	 * The refusal as the API publishes it.
	 *
	 * @return array<string, mixed> The response body.
	 *
	 * @spec openspec/specs/gdpr-data-subject-rights/spec.md
	 */
	public function toResponseBody(): array {
		$body = ['error' => self::ERROR, 'rule' => $this->getRule(), 'message' => $this->getMessage()];

		return array_merge($body, $this->context);
	}//end toResponseBody()

	/**
	 * The HTTP status the caller should answer with.
	 *
	 * @return int The status code.
	 *
	 * @spec openspec/specs/gdpr-data-subject-rights/spec.md
	 */
	public function getStatusCode(): int {
		return (int)$this->getCode();
	}//end getStatusCode()

	/**
	 * The rule that refused.
	 *
	 * @return string The rule id.
	 *
	 * @spec openspec/specs/gdpr-data-subject-rights/spec.md
	 */
	public function getRule(): string {
		return $this->rule;
	}//end getRule()
}//end class
