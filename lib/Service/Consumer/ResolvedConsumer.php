<?php

/**
 * The consumer a credential authenticated as, whatever store it came from.
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
 * A consumer as AuthorizationService needs it, independent of its store.
 *
 * `record` is the store's own row (a Consumer entity, an ObjectEntity, ...),
 * so a caller that keys rate limits or call logs on it gets back exactly what
 * its source handed in.
 *
 * @spec openspec/changes/authorization-service-public-hardened/specs/auth-system/spec.md
 */
final class ResolvedConsumer {

	/**
	 * Constructor.
	 *
	 * @param string               $source                     Which store resolved it (`openregister`, or the app id).
	 * @param string|null          $uuid                       The consumer's identifier in that store.
	 * @param string               $name                       The consumer's name (a JWT's `iss`).
	 * @param string|null          $userId                     The Nextcloud user the consumer acts as, if any.
	 * @param string|null          $authorizationType          How the consumer authenticates (`jwt`, `apiKey`, ...).
	 * @param array<string, mixed> $configuration              The authorization configuration: algorithm, key or secret, grants.
	 * @param mixed                $record                     The store's own row.
	 *
	 * @spec openspec/changes/authorization-service-public-hardened/specs/auth-system/spec.md
	 */
	public function __construct(
		public readonly string $source,
		public readonly ?string $uuid,
		public readonly string $name,
		public readonly ?string $userId,
		public readonly ?string $authorizationType,
		public readonly array $configuration,
		public readonly mixed $record,
	) {
	}//end __construct()
}//end class
