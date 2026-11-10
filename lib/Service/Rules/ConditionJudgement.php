<?php

/**
 * OpenRegister condition judgement
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rules
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

namespace OCA\OpenRegister\Service\Rules;

/**
 * A condition the owning engine has already judged: the condition, the data, the verdict and the engine's sentence.
 *
 * @spec openspec/specs/flow-engine/spec.md
 */
final class ConditionJudgement {
	/**
	 * Constructor.
	 *
	 * @param mixed                $condition The condition as the author declared it.
	 * @param array<string, mixed> $document  The data the condition was evaluated against.
	 * @param string               $verdict   The verdict the owning engine reached.
	 * @param string|null          $message   The engine's own sentence, when it has one.
	 */
	public function __construct(
		public readonly mixed $condition,
		public readonly array $document,
		public readonly string $verdict,
		public readonly ?string $message = null,
	) {
	}//end __construct()
}//end class
