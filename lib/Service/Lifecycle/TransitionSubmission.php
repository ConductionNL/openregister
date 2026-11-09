<?php

/**
 * OpenRegister TransitionSubmission
 *
 * What a caller posted for a transition: the action and its input values.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Lifecycle;

/**
 * What a caller posted for a transition: the action name and its payload.
 *
 * The two travel together into the `inputs` allowlist of
 * {@see TransitionEngine}, which names the action in every refusal it throws
 * (ADR-011 rule 2).
 *
 * @spec openspec/specs/object-lifecycle/spec.md#requirement-a-transition-may-declare-inputs-bounding-the-payload-it-accepts
 */
final class TransitionSubmission {

	/**
	 * Name the submission.
	 *
	 * @param string               $action The transition action name.
	 * @param array<string, mixed> $data   The caller-supplied input values.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		public readonly string $action,
		public readonly array $data,
	) {
	}//end __construct()
}//end class
