<?php

/**
 * Characterisation golden for ContactMatchingService::invalidateCacheForObject().
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service;

use Exception;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ContactMatchingService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins which cache keys an object's properties invalidate.
 *
 * @covers \OCA\OpenRegister\Service\ContactMatchingService
 */
class ContactMatchingCacheInvalidationGoldenTest extends TestCase {

	public static function objects(): array {
		return [
			'no cache' => [false, ['email' => 'a@b.nl'], []],
			'every email-like key' => [true, [
				'email' => 'One@Example.nl ',
				'E-Mail' => 'two@example.nl',
				'workMail' => 'three@example.nl',
				'emailadres' => 'four@example.nl',
				'EmailAddress' => 'five@example.nl',
			], ['two@example.nl', 'three@example.nl', 'four@example.nl', 'five@example.nl']],
			'skipped values' => [true, [
				'email' => '',
				'mail' => 'not-an-email',
				'name' => 'x@y.nl',
				'contactmail' => ['a@b.nl'],
				'email2' => 42,
				0 => 'z@y.nl',
				'zero' => '0',
			], []],
		];
	}//end objects()

	/**
	 * @dataProvider objects
	 */
	public function testInvalidatedKeys(bool $withCache, array $object, array $expectedEmails): void {
		$removed = [];
		$factory = $this->createMock(ICacheFactory::class);
		if ($withCache === false) {
			$factory->method('createDistributed')->willThrowException(new Exception('no cache'));
		} else {
			$cache = $this->createMock(ICache::class);
			$cache->method('remove')->willReturnCallback(
				function ($key) use (&$removed) {
					$removed[] = $key;
					return true;
				}
			);
			$factory->method('createDistributed')->willReturn($cache);
		}

		$service = new ContactMatchingService(
			$this->createMock(ObjectService::class),
			$this->createMock(SchemaMapper::class),
			$this->createMock(RegisterMapper::class),
			$factory,
			$this->createMock(LoggerInterface::class)
		);
		$service->invalidateCacheForObject($object);

		$expected = array_map(fn (string $email) => 'or_contact_match_email_' . hash('sha256', strtolower(trim($email))), $expectedEmails);
		$this->assertSame($expected, $removed);
	}//end testInvalidatedKeys()
}//end class
