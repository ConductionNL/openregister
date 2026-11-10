<?php

/**
 * How a resolved consumer authenticates, whatever store it came from.
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
 * How a resolved consumer authenticates and whom it acts as.
 *
 * @spec openspec/changes/authorization-service-public-hardened/specs/auth-system/spec.md
 */
final class ConsumerAuthorization {

	/**
	 * Constructor.
	 *
	 * @param string|null          $userId            The Nextcloud user the consumer acts as, if any.
	 * @param string|null          $authorizationType How the consumer authenticates (`jwt`, `apiKey`, ...).
	 * @param array<string, mixed> $configuration     The authorization configuration: algorithm, key or secret, grants.
	 *
	 * @spec openspec/changes/authorization-service-public-hardened/specs/auth-system/spec.md
	 */
	public function __construct(
		public readonly ?string $userId,
		public readonly ?string $authorizationType,
		public readonly array $configuration=[],
	) {
	}//end __construct()
}//end class
