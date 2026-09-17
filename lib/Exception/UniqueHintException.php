<?php

/**
 * OpenRegister UniqueHintException
 *
 * Raised at schema save when `x-openregister-unique-hint` nominates a property
 * the schema does not declare.
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
 * A uniqueness nomination the schema save refuses.
 *
 * FATAL, unlike the sibling `x-openregister-dedup` block which degrades to a
 * logged warning, and the difference is deliberate. A malformed dedup block
 * costs you a duplicate sweep you can re-run. A nomination of a property that
 * does not exist costs you a uniqueness alert that silently never fires, and
 * the only symptom is a second case on the same KvK number found months later
 * or never. The administrator who typed the name is the one person who can
 * fix it, and they are still holding the form.
 *
 * HTTP 422: the request is well formed and the schema is valid JSON; what
 * refuses is a declaration inside it that names something absent.
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
class UniqueHintException extends Exception {

	/**
	 * The individual refusals.
	 *
	 * @var array<int, array{code: string, property: string, message: string}>
	 */
	private array $errors = [];

	/**
	 * Constructor.
	 *
	 * @param string $message The sentence naming what was refused.
	 * @param array<int, array{code: string, property: string, message: string}> $errors The per-property errors.
	 * @param int $code HTTP status carried on the exception (default: 422).
	 * @param Throwable|null $previous Previous exception, when chained.
	 *
	 * @return void
	 */
	public function __construct(
		string $message = 'x-openregister-unique-hint names a property this schema does not declare.',
		array $errors = [],
		int $code = 422,
		?Throwable $previous = null,
	) {
		$this->errors = array_values($errors);

		parent::__construct(message: $message, code: $code, previous: $previous);
	}//end __construct()

	/**
	 * The individual refusals, so a client can tell which nomination refused.
	 *
	 * @return array<int, array{code: string, property: string, message: string}> The per-property errors.
	 */
	public function getErrors(): array {
		return $this->errors;
	}//end getErrors()
}//end class
