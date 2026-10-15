<?php

/**
 * A draft's activation was refused, and nothing was changed (decision 216).
 *
 * The refusal carries the HTTP status its caller answers with, a stable
 * reason a client can branch on, and the per-property findings when the
 * draft is not yet valid in full.
 *
 * 404 `not-found`: no such object in this register and schema.
 * 409 `not-a-draft`: the object exists and is not in status `draft`.
 * 403 `not-the-owner`: the draft belongs to somebody else, or the owner may not write it.
 * 422 `invalid`: the draft does not pass the schema in full; `findings` says where.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/draft-owner-activation-and-lifetime/specs/form-destination/spec.md#requirement-a-drafts-owner-must-be-able-to-make-it-definitive-through-the-object-api
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Exception;

use Exception;
use InvalidArgumentException;

/**
 * Why a draft did not become definitive.
 *
 * @spec openspec/changes/draft-owner-activation-and-lifetime/specs/form-destination/spec.md#requirement-a-drafts-owner-must-be-able-to-make-it-definitive-through-the-object-api
 */
class DraftActivationRefusedException extends Exception {

	/**
	 * No such object in this register and schema.
	 *
	 * @var string
	 */
	public const NOT_FOUND = 'not-found';

	/**
	 * The object is not in status `draft`.
	 *
	 * @var string
	 */
	public const NOT_A_DRAFT = 'not-a-draft';

	/**
	 * The draft belongs to somebody else, or its owner may not write it.
	 *
	 * @var string
	 */
	public const NOT_THE_OWNER = 'not-the-owner';

	/**
	 * The draft does not pass its schema in full.
	 *
	 * @var string
	 */
	public const INVALID = 'invalid';

	/**
	 * The HTTP status each reason answers with.
	 *
	 * @var array<string, int>
	 */
	private const STATUS = [
		self::NOT_FOUND => 404,
		self::NOT_A_DRAFT => 409,
		self::NOT_THE_OWNER => 403,
		self::INVALID => 422,
	];

	/**
	 * Constructor.
	 *
	 * @param string                           $message  The sentence the caller shows.
	 * @param string                           $reason   One of the reason constants.
	 * @param array<int, array<string, mixed>> $findings The findings, `{ property, code, message }`.
	 *
	 * @throws InvalidArgumentException When the reason is not one of the constants.
	 */
	public function __construct(
		string $message,
		private readonly string $reason,
		private readonly array $findings = [],
	) {
		if (isset(self::STATUS[$reason]) === false) {
			throw new InvalidArgumentException('"' . $reason . '" is not a reason a draft activation is refused for.');
		}

		parent::__construct(message: $message);
	}//end __construct()

	/**
	 * The reason, for a client to branch on.
	 *
	 * @return string One of the reason constants.
	 *
	 * @spec openspec/changes/draft-owner-activation-and-lifetime/specs/form-destination/spec.md#requirement-a-drafts-owner-must-be-able-to-make-it-definitive-through-the-object-api
	 */
	public function getReason(): string {
		return $this->reason;
	}//end getReason()

	/**
	 * The HTTP status: always 4xx, never one that reads as success.
	 *
	 * @return int The status.
	 *
	 * @spec openspec/changes/draft-owner-activation-and-lifetime/specs/form-destination/spec.md#requirement-a-drafts-owner-must-be-able-to-make-it-definitive-through-the-object-api
	 */
	public function getStatus(): int {
		return self::STATUS[$this->reason];
	}//end getStatus()

	/**
	 * The findings.
	 *
	 * @return array<int, array<string, mixed>> The findings.
	 *
	 * @spec openspec/changes/draft-owner-activation-and-lifetime/specs/form-destination/spec.md#requirement-a-drafts-owner-must-be-able-to-make-it-definitive-through-the-object-api
	 */
	public function getFindings(): array {
		return $this->findings;
	}//end getFindings()

	/**
	 * The response body: `{ message, reason, findings }`.
	 *
	 * @return array{message: string, reason: string, findings: array<int, array<string, mixed>>} The body.
	 *
	 * @spec openspec/changes/draft-owner-activation-and-lifetime/specs/form-destination/spec.md#requirement-a-drafts-owner-must-be-able-to-make-it-definitive-through-the-object-api
	 */
	public function toBody(): array {
		return [
			'message' => $this->getMessage(),
			'reason' => $this->reason,
			'findings' => $this->findings,
		];
	}//end toBody()
}//end class
