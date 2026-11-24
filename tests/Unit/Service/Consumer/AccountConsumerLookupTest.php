<?php

/**
 * Unit tests for naming the one consumer an account is.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Consumer
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/enhanced-audit-trail/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Consumer;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\Consumer;
use OCA\OpenRegister\Db\ConsumerMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\Compliance\AccountConsumerInterface;
use OCA\OpenRegister\Service\Consumer\AccountConsumerLookup;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\Consumer\AccountConsumerLookup
 */
final class AccountConsumerLookupTest extends TestCase {

	private function lookup(array|RuntimeException $rows, ?array &$filters = null): AccountConsumerLookup {
		$mapper = $this->createMock(ConsumerMapperInterface::class);
		$mapper->method('findAll')->willReturnCallback(
			static function (...$args) use ($rows, &$filters): array {
				$filters = ($args[2] ?? null);
				if ($rows instanceof RuntimeException) {
					throw $rows;
				}

				return $rows;
			}
		);

		return new AccountConsumerLookup($mapper);
	}//end lookup()

	private function consumer(string $uuid, string $name): Consumer {
		$consumer = new Consumer();
		$consumer->setUuid($uuid);
		$consumer->setName($name);

		return $consumer;
	}//end consumer()

	public function testItIsTheAuditTrailsPort(): void {
		self::assertInstanceOf(AccountConsumerInterface::class, $this->lookup([]));
	}//end testItIsTheAuditTrailsPort()

	public function testTheSingleConsumerOfTheAccountIsNamedAndLookedUpByUserId(): void {
		$filters  = null;
		$consumer = $this->lookup([$this->consumer('consumer-uuid', 'Leverancier BV')], $filters)->soleConsumerOf('svc-leverancier');

		self::assertNotNull($consumer);
		self::assertSame('consumer-uuid', $consumer->id);
		self::assertSame('Leverancier BV', $consumer->name);
		self::assertSame(['user_id' => 'svc-leverancier'], $filters);
	}//end testTheSingleConsumerOfTheAccountIsNamedAndLookedUpByUserId()

	public function testNoConsumerIsNone(): void {
		self::assertNull($this->lookup([])->soleConsumerOf('svc-leverancier'));
	}//end testNoConsumerIsNone()

	public function testTwoConsumersSharingOneAccountAreNeither(): void {
		$rows = [$this->consumer('uuid-a', 'Leverancier A'), $this->consumer('uuid-b', 'Leverancier B')];

		self::assertNull($this->lookup($rows)->soleConsumerOf('svc-gedeeld'));
	}//end testTwoConsumersSharingOneAccountAreNeither()

	public function testARowThatIsNotAConsumerIsNone(): void {
		self::assertNull($this->lookup([new \stdClass()])->soleConsumerOf('svc-leverancier'));
	}//end testARowThatIsNotAConsumerIsNone()

	public function testAFailedLookupIsNoneAndNotAnError(): void {
		self::assertNull($this->lookup(new RuntimeException('database gone'))->soleConsumerOf('svc-leverancier'));
	}//end testAFailedLookupIsNoneAndNotAnError()
}//end class
