<?php

/**
 * A query refused because it named no usable purpose.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Audit
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/verwerkingsregister-api/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Audit;

use Exception;
use Throwable;

/**
 * The refusal, carrying the reason and the purpose that was named.
 *
 * The purpose is quoted back verbatim. A refusal that does not say which
 * purpose it refused sends the caller to read the whole administered list to
 * find its own typo, and the string is one the caller itself sent.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Audit
 *
 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/verwerkingsregister-api/spec.md
 */
class PurposeRefusedException extends Exception {
	/**
	 * No purpose was named at all.
	 *
	 * @var string
	 */
	public const RULE_MISSING = 'purpose-missing';

	/**
	 * A purpose was named and the administered list does not have it.
	 *
	 * @var string
	 */
	public const RULE_UNKNOWN = 'purpose-unknown';

	/**
	 * The purpose exists and names no entry in the processing register.
	 *
	 * @var string
	 */
	public const RULE_UNBOUND = 'purpose-unbound';

	/**
	 * The purpose exists, is bound, and was withdrawn.
	 *
	 * @var string
	 */
	public const RULE_RETIRED = 'purpose-retired';

	/**
	 * Build a refusal.
	 *
	 * @param string               $rule     Machine-readable rule id, one of this class's RULE_ constants.
	 * @param string               $reason   The sentence a person reads.
	 * @param string|null          $purpose  The purpose the caller named, quoted back. Null when none was.
	 * @param array<string, mixed> $context  Anything else the caller needs to act.
	 * @param Throwable|null       $previous Previous exception.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly string $rule,
		private readonly string $reason,
		private readonly ?string $purpose = null,
		private readonly array $context = [],
		?Throwable $previous = null,
	) {
		parent::__construct(message: $reason, code: 403, previous: $previous);
	}//end __construct()

	/**
	 * The rule that refused.
	 *
	 * @return string The rule id.
	 *
	 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/verwerkingsregister-api/spec.md
	 */
	public function getRule(): string {
		return $this->rule;
	}//end getRule()

	/**
	 * The purpose the caller named.
	 *
	 * @return string|null The purpose code, or null when none was named.
	 *
	 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/verwerkingsregister-api/spec.md
	 */
	public function getPurpose(): ?string {
		return $this->purpose;
	}//end getPurpose()

	/**
	 * The HTTP status the caller should answer with.
	 *
	 * 403, not 400: the request is well formed and the caller may not make it.
	 *
	 * @return int The status code.
	 *
	 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/verwerkingsregister-api/spec.md
	 */
	public function getStatusCode(): int {
		return 403;
	}//end getStatusCode()

	/**
	 * The refusal as the API publishes it.
	 *
	 * @return array<string, mixed> The response body.
	 *
	 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/verwerkingsregister-api/spec.md
	 */
	public function toResponseBody(): array {
		return array_merge(
			[
				'error' => 'PURPOSE_REFUSED',
				'rule' => $this->rule,
				'message' => $this->reason,
				'purpose' => $this->purpose,
			],
			$this->context
		);
	}//end toResponseBody()
}//end class
