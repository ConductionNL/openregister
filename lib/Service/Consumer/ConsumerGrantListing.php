<?php

/**
 * ConsumerGrantListing: the Integrations side of ConsumerGrantsInterface.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Consumer
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md#requirement-a-token-carries-a-required-end-date-and-is-warned-before-it-lapses-req-sat-003
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Consumer;

use OCA\OpenRegister\Db\Consumer;
use OCA\OpenRegister\Db\ConsumerMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ConsumerGrantsInterface;

/**
 * Reads every Consumer through its mapper and hands out the token id, holder,
 * name and stored grant of each.
 *
 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md#requirement-a-token-carries-a-required-end-date-and-is-warned-before-it-lapses-req-sat-003
 */
final class ConsumerGrantListing implements ConsumerGrantsInterface {

	/**
	 * The key a Consumer's grant is stored under in its authorization configuration
	 * (the same value as Service\Rbac\TokenGrant::KEY).
	 *
	 * @var string
	 */
	private const GRANT_KEY = 'grant';

	/**
	 * Constructor.
	 *
	 * @param ConsumerMapperInterface $consumers The Consumers.
	 */
	public function __construct(
		private readonly ConsumerMapperInterface $consumers,
	) {
	}//end __construct()

	/**
	 * Every Consumer, as the facts the lapse sweep needs.
	 *
	 * @return array<int, array{tokenId: string, holder: string, name: string, stored: mixed}>
	 *
	 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md#requirement-a-token-carries-a-required-end-date-and-is-warned-before-it-lapses-req-sat-003
	 */
	public function consumerGrants(): array {
		return array_values(array_map(static fn (Consumer $consumer): array => self::row(consumer: $consumer), $this->consumers->findAll()));
	}//end consumerGrants()

	/**
	 * One Consumer as the sweep's facts.
	 *
	 * @param Consumer $consumer The Consumer.
	 *
	 * @return array{tokenId: string, holder: string, name: string, stored: mixed}
	 *
	 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md#requirement-a-token-carries-a-required-end-date-and-is-warned-before-it-lapses-req-sat-003
	 */
	public static function row(Consumer $consumer): array {
		$tokenId = (string)($consumer->getUuid() ?? $consumer->getId());

		return [
			'tokenId' => $tokenId,
			'holder'  => (string)($consumer->getUserId() ?? ''),
			'name'    => (string)($consumer->getName() ?? $tokenId),
			'stored'  => ($consumer->getAuthorizationConfiguration()[self::GRANT_KEY] ?? null),
		];
	}//end row()
}//end class
