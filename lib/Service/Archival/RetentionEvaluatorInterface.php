<?php

/**
 * RetentionEvaluatorInterface: what callers outside the component use of RetentionEvaluator.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Archival
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Archival;

use DateTimeInterface;
use InvalidArgumentException;

/**
 * Computes a row's effective retention from the schema's archival annotation.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own.
 */
interface RetentionEvaluatorInterface {

	/**
	 * Compute the effective retention for a row.
	 *
	 * @param array<string, mixed> $annotation Full `x-openregister-archival` block from the schema's configuration.
	 * @param array<string, mixed> $row        Row data keyed by field name.
	 * @param DateTimeInterface    $createdAt  The row's `_created` timestamp.
	 *
	 * @return array{
	 *     effectiveRetention: string,
	 *     matchedRule: int|null,
	 *     expiresAt: string,
	 *     aggregationLevel?: string,
	 *     useRestriction?: array{type: string, description?: string},
	 *     temporalCoverage?: array{type: string, start: string, end?: string}
	 * } Effective retention, matched rule index, absolute expiry and the archival facts established for the row.
	 *
	 * @throws InvalidArgumentException When the annotation has no usable retention.
	 *
	 * @spec openspec/changes/add-archival-annotation-support/tasks.md#task-4
	 */
	public function evaluate(array $annotation, array $row, DateTimeInterface $createdAt): array;
}//end interface
