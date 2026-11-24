<?php

/**
 * The registered API consumer an account is, read from OpenRegister's consumers.
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
 * @spec openspec/specs/enhanced-audit-trail/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Consumer;

use OCA\OpenRegister\Db\Consumer;
use OCA\OpenRegister\Db\ConsumerMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\Compliance\AccountConsumerInterface;
use OCA\OpenRegister\Service\Audit\NamedIdentifier;
use Throwable;

/**
 * Finds the one consumer whose user id is the account's.
 *
 * Matched on the consumer's `user_id`, never on a token name. An ambiguous
 * match, where two consumers share one user, resolves to no consumer rather
 * than to the first of them; a failed lookup resolves to none as well.
 *
 * @spec openspec/specs/enhanced-audit-trail/spec.md
 */
class AccountConsumerLookup implements AccountConsumerInterface {

	/**
	 * Constructor.
	 *
	 * @param ConsumerMapperInterface $consumerMapper Registered API consumers.
	 */
	public function __construct(
		private readonly ConsumerMapperInterface $consumerMapper,
	) {
	}//end __construct()

	/**
	 * The consumer the account is, when exactly one consumer names it; null otherwise.
	 *
	 * @param string $ownerUid The account's user id.
	 *
	 * @return NamedIdentifier|null The consumer's uuid and name, or null.
	 *
	 * @spec openspec/specs/enhanced-audit-trail/spec.md
	 */
	public function soleConsumerOf(string $ownerUid): ?NamedIdentifier {
		$consumers = $this->consumersOf(ownerUid: $ownerUid);
		if (count($consumers) !== 1) {
			return null;
		}

		$consumer = $consumers[0];
		if (($consumer instanceof Consumer) === false) {
			return null;
		}

		return new NamedIdentifier(id: $consumer->getUuid(), name: $consumer->getName());
	}//end soleConsumerOf()

	/**
	 * The consumers registered for a user, or none when the lookup fails.
	 *
	 * @param string $ownerUid The account's user id.
	 *
	 * @return array<int, mixed> The consumers.
	 */
	private function consumersOf(string $ownerUid): array {
		try {
			return $this->consumerMapper->findAll(filters: ['user_id' => $ownerUid]);
		} catch (Throwable $lookupFailed) {
			return [];
		}
	}//end consumersOf()
}//end class
