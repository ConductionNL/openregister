<?php

/**
 * A cascade restore that refuses the whole act.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Deletion
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/records-restore-with-cascade/specs/deletion-audit-trail/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Deletion;

use RuntimeException;

/**
 * Raised before anything is written: the caller may not restore one of the
 * objects (403), or the cascade is larger than one restore may take (409).
 *
 * @spec openspec/changes/records-restore-with-cascade/specs/deletion-audit-trail/spec.md
 */
final class CascadeRestoreRefusedException extends RuntimeException {
	/**
	 * Constructor.
	 *
	 * @param string               $reason  What refused the act, in words.
	 * @param int                  $status  The HTTP status the refusal answers with.
	 * @param array<string, mixed> $details Counts and schemas, never uuids of objects the caller cannot see.
	 */
	public function __construct(
		string $reason,
		public readonly int $status,
		public readonly array $details=[],
	) {
		parent::__construct(message: $reason);
	}//end __construct()

	/**
	 * The refusal for objects the caller may not restore.
	 *
	 * @param CascadeRestorePlan $plan The plan with at least one forbidden item.
	 *
	 * @return self A 403 naming the count and the schemas.
	 *
	 * @spec openspec/changes/records-restore-with-cascade/specs/deletion-audit-trail/spec.md
	 */
	public static function forbidden(CascadeRestorePlan $plan): self {
		$summary = $plan->forbiddenSummary();

		return new self(
			reason: sprintf(
				'You may not restore %d related object(s) in schema(s) %s; nothing was restored',
				$summary['count'],
				implode(', ', array_keys($summary['schemas']))
			),
			status: 403,
			details: ['forbidden' => $summary]
		);
	}//end forbidden()

	/**
	 * The refusal for a cascade over the cap.
	 *
	 * @param int $cap The most rows one restore takes.
	 *
	 * @return self A 409 naming the cap.
	 *
	 * @spec openspec/changes/records-restore-with-cascade/specs/deletion-audit-trail/spec.md
	 */
	public static function tooLarge(int $cap): self {
		return new self(
			reason: sprintf('The delete cascaded to more than %d objects; restore them in parts or with cascade: false', $cap),
			status: 409,
			details: ['cap' => $cap]
		);
	}//end tooLarge()
}//end class
