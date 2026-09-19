<?php

/**
 * ExportRefusedException — a refused export that names the verb, not the record.
 *
 * "Forbidden" on an export tells an operator nothing they can act on. They do
 * not know whether the register is closed to them, whether one row was out of
 * scope, or whether they simply may not take data off the instance. The verb is
 * the answer to all three, so the refusal carries it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\Export
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Export;

use Exception;
use Throwable;

/**
 * An export that was refused, carrying the rule and the verb that refused it.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Export
 *
 * @spec openspec/changes/export-as-its-own-right/specs/authorization-rbac/spec.md
 */
class ExportRefusedException extends Exception {
	/**
	 * Build a refusal.
	 *
	 * @param string               $rule       Machine-readable rule id, e.g. `export-right-missing`.
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
	 * @spec openspec/changes/export-as-its-own-right/specs/authorization-rbac/spec.md
	 */
	public function getRule(): string {
		return $this->rule;
	}//end getRule()

	/**
	 * The HTTP status the caller should answer with.
	 *
	 * @return int The status code.
	 *
	 * @spec openspec/changes/export-as-its-own-right/specs/authorization-rbac/spec.md
	 */
	public function getStatusCode(): int {
		return $this->statusCode;
	}//end getStatusCode()

	/**
	 * The refusal as the API publishes it.
	 *
	 * The body names the verb in its own field. A client that wants to hide an
	 * export button reads `verb`, not the sentence, so the sentence stays free
	 * to be rewritten without breaking anybody.
	 *
	 * @return array<string, mixed> The response body.
	 *
	 * @spec openspec/changes/export-as-its-own-right/specs/authorization-rbac/spec.md
	 */
	public function toResponseBody(): array {
		return array_merge(
			[
				'error' => 'EXPORT_REFUSED',
				'verb' => ExportRightService::ACTION,
				'rule' => $this->rule,
				'message' => $this->reason,
			],
			$this->context
		);
	}//end toResponseBody()
}//end class
