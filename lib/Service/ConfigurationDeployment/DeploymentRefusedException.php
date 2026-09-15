<?php

/**
 * DeploymentRefusedException — a refusal that names the rule and the value.
 *
 * ADR-005: a deployment that cannot be applied in full applies none of it.
 * The half that makes that usable is the NAME: "one value refused" sends an
 * administrator through nine settings; "rbac refused: the live value moved
 * since the draft was taken" sends them to one.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ConfigurationDeployment
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ConfigurationDeployment;

use Exception;
use OCP\AppFramework\Http;

/**
 * Class DeploymentRefusedException
 */
class DeploymentRefusedException extends Exception {

	/**
	 * The draft set, the deployment or the value could not be found.
	 *
	 * @var string
	 */
	public const REASON_UNKNOWN = 'unknown';

	/**
	 * The instance requires an approver other than the author.
	 *
	 * @var string
	 */
	public const REASON_FOUR_EYES = 'four-eyes';

	/**
	 * The set is not in a state that can be deployed.
	 *
	 * @var string
	 */
	public const REASON_STATE = 'state';

	/**
	 * At least one value in the set would not apply.
	 *
	 * @var string
	 */
	public const REASON_VALUE_REFUSED = 'value-refused';

	/**
	 * The set holds no pending value.
	 *
	 * @var string
	 */
	public const REASON_EMPTY = 'empty';

	/**
	 * The caller asked for something the vocabulary does not hold.
	 *
	 * @var string
	 */
	public const REASON_INVALID = 'invalid';

	/**
	 * Constructor.
	 *
	 * @param string                      $reason     One of the REASON_* constants.
	 * @param string                      $message    What refused, in words.
	 * @param array<int, array<string, mixed>> $refusals The refusing values, when there are any.
	 * @param integer                     $statusCode The HTTP status to answer with.
	 */
	public function __construct(
		private readonly string $reason,
		string $message,
		private readonly array $refusals = [],
		private readonly int $statusCode = Http::STATUS_CONFLICT
	) {
		parent::__construct($message);

	}//end __construct()

	/**
	 * The machine-readable reason.
	 *
	 * @return string One of the REASON_* constants.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function getReason(): string {
		return $this->reason;

	}//end getReason()

	/**
	 * The values that refused, each naming its address and its reason.
	 *
	 * @return array<int, array<string, mixed>> The refusals.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function getRefusals(): array {
		return $this->refusals;

	}//end getRefusals()

	/**
	 * The HTTP status this refusal answers with.
	 *
	 * @return integer The status code.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function getStatusCode(): int {
		return $this->statusCode;

	}//end getStatusCode()

	/**
	 * The refusal as a response body.
	 *
	 * @return array<string, mixed> The body.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function toResponseBody(): array {
		return [
			'error' => $this->reason,
			'message' => $this->getMessage(),
			'refusals' => $this->refusals,
			'applied' => 0,
		];

	}//end toResponseBody()
}//end class
