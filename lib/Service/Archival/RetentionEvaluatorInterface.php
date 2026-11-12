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
	 * @param AnnotatedRow $subject The archival annotation, the row it is evaluated against and when the row was created.
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
	public function evaluate(AnnotatedRow $subject): array;
}//end interface
