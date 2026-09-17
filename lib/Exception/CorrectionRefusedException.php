<?php

/**
 * OpenRegister CorrectionRefusedException
 *
 * The refusal a correction answers with when it is not one.
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
 * Thrown when a correction is asked for without what a correction needs.
 *
 * A correction with no reason, or with no values to correct, is an ordinary
 * update wearing the word. Refusing it here rather than accepting it and
 * recording it as a correction is what keeps the answer to "which of these
 * changes were corrections" worth reading (D-3).
 *
 * HTTP 400: the caller can fix it by sending a reason.
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
class CorrectionRefusedException extends Exception {

	/**
	 * Constructor.
	 *
	 * @param string $message The sentence the caller reads.
	 * @param integer $code The exception code.
	 * @param Throwable|null $previous The exception that caused this one.
	 */
	public function __construct(string $message, int $code = 0, ?Throwable $previous = null) {
		parent::__construct(message: $message, code: $code, previous: $previous);
	}//end __construct()
}//end class
