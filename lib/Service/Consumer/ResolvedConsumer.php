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
	 * Which store resolved it (`openregister`, or the app id).
	 *
	 * @var string
	 */
	public readonly string $source;

	/**
	 * The consumer's identifier in that store.
	 *
	 * @var string|null
	 */
	public readonly ?string $uuid;

	/**
	 * The consumer's name (a JWT's `iss`).
	 *
	 * @var string
	 */
	public readonly string $name;

	/**
	 * The Nextcloud user the consumer acts as, if any.
	 *
	 * @var string|null
	 */
	public readonly ?string $userId;

	/**
	 * How the consumer authenticates (`jwt`, `apiKey`, ...).
	 *
	 * @var string|null
	 */
	public readonly ?string $authorizationType;

	/**
	 * The authorization configuration: algorithm, key or secret, grants.
	 *
	 * @var array<string, mixed>
	 */
	public readonly array $configuration;

	/**
	 * Constructor.
	 *
	 * The flat properties stay readable as before; they are copied from the
	 * two value objects so readers never change.
	 *
	 * @param ConsumerIdentity      $identity      Who the consumer is and which store resolved it.
	 * @param ConsumerAuthorization $authorization How it authenticates and whom it acts as.
	 * @param mixed                 $record        The store's own row.
	 *
	 * @spec openspec/changes/authorization-service-public-hardened/specs/auth-system/spec.md
	 */
	public function __construct(
		public readonly ConsumerIdentity $identity,
		public readonly ConsumerAuthorization $authorization,
		public readonly mixed $record=null,
	) {
		$this->source            = $identity->source;
		$this->uuid              = $identity->uuid;
		$this->name              = $identity->name;
		$this->userId            = $authorization->userId;
		$this->authorizationType = $authorization->authorizationType;
		$this->configuration     = $authorization->configuration;
	}//end __construct()
}//end class
