<?php

/**
 * One edit of an organisation: the uuid the route named and the request data.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/tenant-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Support;

/**
 * The organisation uuid exactly as the route named it, and the fields the request sent.
 *
 * @spec openspec/specs/tenant-lifecycle/spec.md
 */
final class OrganisationEdit {

	/**
	 * Constructor.
	 *
	 * @param string               $uuid The organisation uuid as the route named it.
	 * @param array<string, mixed> $data The request data.
	 */
	public function __construct(
		public readonly string $uuid,
		public readonly array $data,
	) {
	}//end __construct()
}//end class
