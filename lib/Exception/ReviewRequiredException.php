<?php

/**
 * OpenRegister ReviewRequiredException
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
 *
 * @spec openspec/changes/redaction-release-safeguards/tasks.md#task-2.3
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Exception;

use Exception;

/**
 * Anonymised output was asked for before a person checked the document.
 *
 * The endpoint answers it with 409; a direct caller catches it and reads the
 * same fields.
 */
class ReviewRequiredException extends Exception {

	/**
	 * Nobody checked the document.
	 */
	public const NO_MARK = 'no-mark';

	/**
	 * A detection has no decision.
	 */
	public const UNDECIDED_ENTITIES = 'undecided-entities';

	/**
	 * The document was checked, but the detection changed since.
	 */
	public const STALE_MARK = 'stale-mark';

	/**
	 * Constructor.
	 *
	 * @param string $reason    One of the reason constants.
	 * @param int    $undecided How many detections have no decision.
	 * @param string $message   What the operator should do.
	 *
	 * @spec openspec/changes/redaction-release-safeguards/tasks.md#task-2.3
	 */
	public function __construct(
		public readonly string $reason,
		public readonly int $undecided,
		string $message,
	) {
		parent::__construct(message: $message);
	}//end __construct()

	/**
	 * The refusal body.
	 *
	 * @return array{error: string, reason: string, undecided: int, message: string}
	 *
	 * @spec openspec/changes/redaction-release-safeguards/tasks.md#task-2.3
	 */
	public function toRefusal(): array {
		return ['error' => 'review-required', 'reason' => $this->reason, 'undecided' => $this->undecided, 'message' => $this->getMessage()];
	}//end toRefusal()
}//end class
