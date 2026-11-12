<?php

/**
 * The owner and organisation a bulk save stamps on every object.
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

/**
 * Owner uid from the session and the active organisation; never taken from client data.
 */
final readonly class BulkObjectOwnership {

	/**
	 * Constructor.
	 *
	 * @param string|null $owner        The owner uid taken from the session.
	 * @param string|null $organisation The active organisation.
	 */
	public function __construct(
		public ?string $owner,
		public ?string $organisation,
	) {
	}//end __construct()
}//end class
