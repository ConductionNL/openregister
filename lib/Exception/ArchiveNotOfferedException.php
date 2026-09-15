<?php

/**
 * OpenRegister ArchiveNotOfferedException
 *
 * The refusal a schema that does not declare archiving answers with.
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
 * Thrown when archiving is asked of a schema that does not offer it.
 *
 * `x-openregister-archive` is what turns the action on, and a schema without
 * it never grows an Archive button in any app, because the rule is declared
 * once on the schema rather than decided by each leaf that renders a button
 * (ADR-031).
 *
 * HTTP 422 rather than 404: the object exists and the route exists, and the
 * caller can fix the refusal by declaring the annotation. A 404 would send
 * them looking for a missing record.
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
 *
 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md
 */
class ArchiveNotOfferedException extends Exception {

	/**
	 * The HTTP status controllers MUST map this exception to.
	 *
	 * @var integer
	 */
	public const HTTP_STATUS = 422;

	/**
	 * Constructor for ArchiveNotOfferedException.
	 *
	 * @param string $message The refusal message.
	 * @param int $code The error code (default: 422).
	 * @param Throwable|null $previous The previous exception that caused this one.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		string $message = 'This schema does not offer archiving',
		int $code = 422,
		?Throwable $previous = null,
	) {
		parent::__construct(message: $message, code: $code, previous: $previous);
	}//end __construct()
}//end class
