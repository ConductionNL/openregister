<?php

/**
 * Who a resolved consumer is, whatever store it came from.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Consumer
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/authorization-service-public-hardened/specs/auth-system/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Consumer;

/**
 * Who a resolved consumer is: the store that resolved it, its identifier there and its name.
 *
 * @spec openspec/changes/authorization-service-public-hardened/specs/auth-system/spec.md
 */
final class ConsumerIdentity {

	/**
	 * Constructor.
	 *
	 * @param string      $source Which store resolved it (`openregister`, or the app id).
	 * @param string|null $uuid   The consumer's identifier in that store.
	 * @param string      $name   The consumer's name (a JWT's `iss`).
	 *
	 * @spec openspec/changes/authorization-service-public-hardened/specs/auth-system/spec.md
	 */
	public function __construct(
		public readonly string $source,
		public readonly ?string $uuid,
		public readonly string $name,
	) {
	}//end __construct()
}//end class
