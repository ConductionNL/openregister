<?php

/**
 * One outbound call to a store registry's objects API.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\AppHost\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\AppHost\Service;

/**
 * The HTTP method and the caller's request options of one registry call
 * (ADR-011 Rule 2). GenericStoreService applies the transport rules on top.
 *
 * @spec openspec/specs/apphost-store-plane/spec.md#requirement-a-publish-must-travel-under-the-planes-transport-rules
 */
final class RegistryCall {
	/**
	 * Constructor.
	 *
	 * @param string               $method  'GET' or 'POST'.
	 * @param array<string, mixed> $options Request options (query, body, headers).
	 */
	public function __construct(
		public readonly string $method,
		public readonly array $options,
	) {
	}//end __construct()
}//end class
