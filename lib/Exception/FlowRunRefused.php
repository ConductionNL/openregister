<?php

/**
 * A run refused because this caller may not run THIS flow.
 *
 * Carries the VERDICT as well as the message, so a controller can map four
 * different refusals to the right status without re-deciding anything: no
 * session is a 401, everything else is a 403. Collapsing them would make "sign
 * in" and "this is not yours" arrive as the same answer.
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/flow-runs-honour-their-declaration/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Exception;

use RuntimeException;
use Throwable;

/**
 * Raised when a run is refused for this caller on this flow.
 *
 * @spec openspec/changes/flow-runs-honour-their-declaration/specs/flow-engine/spec.md
 */
class FlowRunRefused extends RuntimeException {

	/**
	 * Constructor.
	 *
	 * @param string         $verdict  The refusal verdict.
	 * @param string         $message  The sentence the caller reads.
	 * @param Throwable|null $previous Previous exception.
	 */
	public function __construct(
		private readonly string $verdict,
		string $message,
		?Throwable $previous = null,
	) {
		parent::__construct(message: $message, code: 403, previous: $previous);
	}//end __construct()

	/**
	 * The verdict, so a caller maps it without re-deciding.
	 *
	 * @return string The verdict.
	 */
	public function getVerdict(): string {
		return $this->verdict;
	}//end getVerdict()
}//end class
