<?php

/**
 * One register/schema pair a bulk chunk row is written to, before the events rule is asked.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object\SaveObjects;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;

/**
 * The schema and register of a chunk row, and the "registerId/schemaId" key they make.
 */
final readonly class BulkEventCandidate {

	/**
	 * Constructor.
	 *
	 * @param mixed                $schema        The row's schema: the caller's, else the schema cache's; may be absent.
	 * @param Register|Schema|null $register      The row's register: the caller's, else the row's own, when it loads.
	 * @param mixed                $rowRegisterId The register id the row itself carries, for the key when no register loaded.
	 */
	public function __construct(
		public mixed $schema,
		public Register|Schema|null $register,
		public mixed $rowRegisterId,
	) {
	}//end __construct()

	/**
	 * The "registerId/schemaId" key of this pair.
	 *
	 * @return string
	 */
	public function key(): string {
		$registerId = ($this->register?->getId() ?? ($this->rowRegisterId ?? ''));
		return ((string) $registerId) . '/' . ((string) $this->schema?->getId());
	}//end key()
}//end class
