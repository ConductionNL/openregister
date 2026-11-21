<?php

/**
 * UnconfirmedMappingException: a record import refused until its mapping is decided.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Import\RecordImport
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/widget-record-import/specs/widget-record-import/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Import\RecordImport;

use InvalidArgumentException;

/**
 * Names the columns that still need a decision.
 *
 * @spec openspec/changes/widget-record-import/specs/widget-record-import/spec.md
 */
class UnconfirmedMappingException extends InvalidArgumentException {

	/**
	 * Constructor.
	 *
	 * @param string       $message The reason.
	 * @param list<string> $columns The columns concerned.
	 */
	public function __construct(string $message, public readonly array $columns) {
		parent::__construct(message: $message);
	}//end __construct()
}//end class
