<?php

/**
 * ProcessingLogSubject: the data-subject identifier a processing-log read matches on.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\ProcessingLog
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

namespace OCA\OpenRegister\Db\ProcessingLog;

/**
 * A subject identifier: its type and its value, either of which may be open.
 *
 * @spec openspec/specs/avg-verwerkingsregister/spec.md
 */
final class ProcessingLogSubject {

	/**
	 * Constructor.
	 *
	 * @param string|null $type  The identifier type (e.g. bsn), or null for any type.
	 * @param string|null $value The identifier value, or null for any value.
	 */
	public function __construct(
		public readonly ?string $type = null,
		public readonly ?string $value = null,
	) {
	}//end __construct()

	/**
	 * Whether neither the type nor the value is given.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/avg-verwerkingsregister/spec.md
	 */
	public function isOpen(): bool {
		return $this->type === null && $this->value === null;
	}//end isOpen()
}//end class
