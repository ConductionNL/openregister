<?php

/**
 * QueryPreviewException — the database refused a previewed query.
 *
 * Carries the database's error class only; its message, which can name other
 * tables or columns, is kept out of the answer (design D-3).
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Dbal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/dbal-query-schema-guarded-and-previewed/specs/dbal-virtual-registers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Dbal;

use RuntimeException;
use Throwable;

/**
 * A previewed query the database refused.
 *
 * @spec openspec/changes/dbal-query-schema-guarded-and-previewed/specs/dbal-virtual-registers/spec.md
 */
class QueryPreviewException extends RuntimeException {

	/**
	 * Constructor.
	 *
	 * @param string         $errorClass The short class name of the database error, e.g. `SyntaxErrorException`.
	 * @param Throwable|null $previous   The database error, for the log.
	 */
	public function __construct(
		public readonly string $errorClass,
		?Throwable $previous = null,
	) {
		parent::__construct(message: 'The database refused the query (' . $errorClass . ').', previous: $previous);
	}//end __construct()
}//end class
