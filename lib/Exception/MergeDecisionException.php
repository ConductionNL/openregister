<?php

/**
 * OpenRegister MergeDecisionException
 *
 * Raised when a merge is asked to execute with a per-property decision map
 * that does not match the preview it was approved with.
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
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Exception;

use Exception;
use Throwable;

/**
 * A decision map the merge refuses.
 *
 * HTTP 422: the request was understood and the objects exist, but the
 * decisions inside it do not describe the merge that was previewed. The
 * properties travel with the refusal so a reviewer is told WHICH ones, and
 * nothing is written.
 *
 * Refusing an incomplete map rather than filling the gaps with the resolver's
 * proposal is the point of the whole feature: a screen that showed a reviewer
 * five properties and wrote a sixth they never saw is worse than no screen.
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
 */
class MergeDecisionException extends Exception {

	/**
	 * The properties the refusal is about.
	 *
	 * @var array<int, string>
	 */
	private array $properties = [];

	/**
	 * Constructor.
	 *
	 * @param string $message The sentence naming what was refused.
	 * @param array<int, string> $properties The property names at fault.
	 * @param int $code HTTP status carried on the exception (default: 422).
	 * @param Throwable|null $previous Previous exception, when chained.
	 *
	 * @return void
	 */
	public function __construct(
		string $message = 'The decision map does not match the preview.',
		array $properties = [],
		int $code = 422,
		?Throwable $previous = null,
	) {
		$this->properties = array_values($properties);

		parent::__construct(message: $message, code: $code, previous: $previous);
	}//end __construct()

	/**
	 * The property names at fault.
	 *
	 * @return array<int, string> The properties, never their values.
	 */
	public function getProperties(): array {
		return $this->properties;
	}//end getProperties()
}//end class
