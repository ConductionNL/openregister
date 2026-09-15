<?php

/**
 * OpenRegister RuleCeilingException
 *
 * Signals a rule run refused as a whole because the selection is larger than
 * the ceiling the rule declares.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rules
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rules;

use RuntimeException;

/**
 * The refusal a ceiling produces, carrying the two numbers behind it.
 *
 * It is an exception rather than a returned value because the ceiling's whole
 * property is that nothing after it runs. A caller that may ignore a returned
 * refusal is a caller that can write the first object anyway, which is the
 * partial mutation D-4 refuses.
 *
 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
 */
final class RuleCeilingException extends RuntimeException {

	/**
	 * Constructor.
	 *
	 * @param string $message The refusal, in a sentence the operator reads.
	 * @param string $ruleId The rule that declared the ceiling.
	 * @param int $ceiling The ceiling the rule declares.
	 * @param int $count The number of objects the run would have touched.
	 *
	 * @return void
	 */
	public function __construct(
		string $message,
		private readonly string $ruleId,
		private readonly int $ceiling,
		private readonly int $count,
	) {
		parent::__construct(message: $message);
	}//end __construct()

	/**
	 * The rule that declared the ceiling.
	 *
	 * @return string The derived rule id.
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	public function getRuleId(): string {
		return $this->ruleId;
	}//end getRuleId()

	/**
	 * The ceiling the rule declares.
	 *
	 * @return int The ceiling.
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	public function getCeiling(): int {
		return $this->ceiling;
	}//end getCeiling()

	/**
	 * How many objects the run would have touched.
	 *
	 * @return int The count taken before the first write.
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	public function getCount(): int {
		return $this->count;
	}//end getCount()

	/**
	 * The refusal in the shape every rules endpoint returns.
	 *
	 * @return array<string, mixed> The refusal envelope.
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	public function toArray(): array {
		return [
			'ok' => false,
			'error' => [
				'code' => RuleCeilingService::CODE_CEILING_EXCEEDED,
				'message' => $this->getMessage(),
				'ruleId' => $this->ruleId,
				'maxObjects' => $this->ceiling,
				'count' => $this->count,
			],
		];
	}//end toArray()
}//end class
