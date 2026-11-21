<?php

/**
 * RuleRefusedException — a refusal that names the rule that refused.
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

use Exception;
use Throwable;

/**
 * A refused act carrying a status code, a machine-readable rule and the sentence a person reads.
 *
 * Subclasses name the act through {@see responseLabels()}; the rest of the published body is shared.
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
 *
 * @spec openspec/specs/authorization-rbac/spec.md
 */
abstract class RuleRefusedException extends Exception {
	/**
	 * Build a refusal.
	 *
	 * @param string               $rule       Machine-readable rule id.
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
	 * @spec openspec/specs/authorization-rbac/spec.md
	 */
	public function getRule(): string {
		return $this->rule;
	}//end getRule()

	/**
	 * The HTTP status the caller should answer with.
	 *
	 * @return int The status code.
	 *
	 * @spec openspec/specs/authorization-rbac/spec.md
	 */
	public function getStatusCode(): int {
		return $this->statusCode;
	}//end getStatusCode()

	/**
	 * The refusal as the API publishes it: the act's labels, the rule, the sentence, then the context.
	 *
	 * @return array<string, mixed> The response body.
	 *
	 * @spec openspec/specs/authorization-rbac/spec.md
	 */
	public function toResponseBody(): array {
		return array_merge(
			$this->responseLabels(),
			[
				'rule' => $this->rule,
				'message' => $this->getMessage(),
			],
			$this->context
		);
	}//end toResponseBody()

	/**
	 * The fields that name the refused act, leading the published body.
	 *
	 * @return array<string, string> The labels, at least `error`.
	 *
	 * @spec openspec/specs/authorization-rbac/spec.md
	 */
	abstract protected function responseLabels(): array;
}//end class
