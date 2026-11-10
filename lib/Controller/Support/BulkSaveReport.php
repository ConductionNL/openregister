<?php

/**
 * OpenRegister BulkSaveReport
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Support;

use OCA\OpenRegister\Dto\BulkSaveOutcomeInterface;
use OCP\AppFramework\Http;

/**
 * The answer to one bulk save, built so a shortfall can never read as a success.
 *
 * The rule this enforces (issue #2778): `success` is true only when EVERY
 * submitted object was written. It used to be the constant `true` on the
 * non-streaming path, so a batch where 27 of 58 rows were refused for
 * failing schema validation answered "completed successfully" and only
 * `saved_count` — which the caller had to think to compare against its own
 * request size — carried the loss. A caller that trusts `success` has
 * already moved on by then, and `events: false` on this path means nothing
 * downstream notices either.
 *
 * `success` is therefore unconditional and never softened by `partial`:
 * making the flag mean "some rows landed" would re-create exactly the shape
 * this fixes. What `partial` changes is only the HTTP status — an opted-in
 * caller gets 200 with an honest `success: false` plus the failures, rather
 * than a 422 its client library would raise on.
 *
 * 422 (not 500) matches the sibling `save()` catch for a unique-constraint
 * collision: the request was understood, and the server refused the content
 * of specific rows. Rows that DID write are not rolled back — this endpoint
 * has never been transactional — which is why `saved_count` stays in the
 * body of the failure response: it is what the caller must reconcile against.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class BulkSaveReport {
	/**
	 * Hold what one save path wrote and lost.
	 *
	 * @param BulkSaveSummary          $summary What the path wrote, and its success message and payload.
	 * @param BulkSaveOutcomeInterface $outcome What the save path lost, and why.
	 * @param bool                     $partial Whether the caller opted into best-effort semantics.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		public readonly BulkSaveSummary $summary,
		public readonly BulkSaveOutcomeInterface $outcome,
		public readonly bool $partial,
	) {
	}//end __construct()

	/**
	 * The HTTP status: 200 when every row was written or the caller opted into partial, else 422.
	 *
	 * @return int The status.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function status(): int {
		if ($this->outcome->isComplete() === true || $this->partial === true) {
			return Http::STATUS_OK;
		}

		return Http::STATUS_UNPROCESSABLE_ENTITY;
	}//end status()

	/**
	 * The message: the path's success message, or how many rows were refused.
	 *
	 * @return string The message.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function message(): string {
		if ($this->outcome->isComplete() === true) {
			return $this->summary->successMessage;
		}

		return sprintf(
			'Bulk save incomplete: %d of %d objects were rejected and NOT written. See "failures" for the reason per object.',
			$this->outcome->failedCount(),
			$this->summary->requestedCount
		);
	}//end message()

	/**
	 * The response body: the counts, the failures and the path's own payload.
	 *
	 * @return array<string, mixed> The body.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function body(): array {
		return array_merge(
			[
				'success' => $this->outcome->isComplete(),
				'message' => $this->message(),
				'saved_count' => $this->summary->savedCount,
				'failed_count' => $this->outcome->failedCount(),
				'requested_count' => $this->summary->requestedCount,
				'failures' => $this->outcome->failures(),
				'partial' => $this->partial,
			],
			$this->summary->extra
		);
	}//end body()
}//end class
