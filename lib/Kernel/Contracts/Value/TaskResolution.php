<?php

/**
 * TaskResolution: the result text and comment a task is resolved with.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts\Value
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Value;

/**
 * What a resolved task reports: the result text and a comment, both optional.
 *
 * @spec openspec/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
 */
final class TaskResolution {

	/**
	 * Constructor
	 *
	 * @param string|null $resultText The result text.
	 * @param string|null $comment A free-text comment.
	 */
	public function __construct(
		public readonly ?string $resultText = null,
		public readonly ?string $comment = null,
	) {
	}//end __construct()
}//end class
