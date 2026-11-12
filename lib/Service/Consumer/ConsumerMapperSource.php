<?php

/**
 * OpenRegister's own consumers (the openregister_consumers table) as a ConsumerSource.
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

use OCA\OpenRegister\Db\Consumer;
use OCA\OpenRegister\Db\ConsumerMapperInterface;

/**
 * The default consumer source: OpenRegister's Consumer entities.
 *
 * @spec openspec/changes/authorization-service-public-hardened/specs/auth-system/spec.md
 */
class ConsumerMapperSource implements ConsumerSource {

	/**
	 * The source name a consumer from this store carries.
	 *
	 * @var string
	 */
	public const SOURCE = 'openregister';

	/**
	 * Constructor.
	 *
	 * @param ConsumerMapperInterface $consumers OpenRegister's consumer table.
	 *
	 * @spec openspec/changes/authorization-service-public-hardened/specs/auth-system/spec.md
	 */
	public function __construct(
		private readonly ConsumerMapperInterface $consumers,
	) {
	}//end __construct()

	/**
	 * The consumer named after the issuer.
	 *
	 * @param string $issuer The issuer.
	 *
	 * @return ResolvedConsumer|null The consumer, or null.
	 *
	 * @spec openspec/changes/authorization-service-public-hardened/specs/auth-system/spec.md
	 */
	public function findByIssuer(string $issuer): ?ResolvedConsumer {
		$found = $this->consumers->findAll(filters: ['name' => $issuer]);
		if (count($found) === 0) {
			return null;
		}

		return self::resolve(consumer: $found[0]);
	}//end findByIssuer()

	/**
	 * The apiKey consumer whose `authorizationConfiguration.apiKey` equals the key.
	 *
	 * @param string $apiKey The presented key.
	 *
	 * @return ResolvedConsumer|null The consumer, or null.
	 *
	 * @spec openspec/changes/authorization-service-public-hardened/specs/auth-system/spec.md
	 */
	public function findByApiKey(string $apiKey): ?ResolvedConsumer {
		if ($apiKey === '') {
			return null;
		}

		foreach ($this->consumers->findAll() as $consumer) {
			if (self::holdsApiKey(consumer: $consumer, apiKey: $apiKey) === true) {
				return self::resolve(consumer: $consumer);
			}
		}

		return null;
	}//end findByApiKey()

	/**
	 * Whether an apiKey-type consumer stores exactly this key (compared in constant time).
	 *
	 * @param Consumer $consumer The consumer.
	 * @param string   $apiKey   The presented, non-empty key.
	 *
	 * @return bool True when the consumer holds the key.
	 */
	private static function holdsApiKey(Consumer $consumer, string $apiKey): bool {
		if (strtolower((string)$consumer->getAuthorizationType()) !== 'apikey') {
			return false;
		}

		$stored = ($consumer->getAuthorizationConfiguration()['apiKey'] ?? '');
		return (is_string($stored) === true && $stored !== '' && hash_equals($stored, $apiKey) === true);
	}//end holdsApiKey()

	/**
	 * A Consumer entity as a ResolvedConsumer.
	 *
	 * @param Consumer $consumer The entity.
	 *
	 * @return ResolvedConsumer
	 *
	 * @spec openspec/changes/authorization-service-public-hardened/specs/auth-system/spec.md
	 */
	public static function resolve(Consumer $consumer): ResolvedConsumer {
		return new ResolvedConsumer(
			source: self::SOURCE,
			uuid: $consumer->getUuid(),
			name: (string)$consumer->getName(),
			userId: $consumer->getUserId(),
			authorizationType: $consumer->getAuthorizationType(),
			configuration: $consumer->getAuthorizationConfiguration(),
			record: $consumer
		);
	}//end resolve()
}//end class
