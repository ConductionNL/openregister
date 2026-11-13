<?php
/**
 * Unit tests for BagAddressLookup — addresses by postcode and house number.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Bag
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Bag;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use InvalidArgumentException;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Bag\BagAddressLookup;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BagAddressLookup::class)]
final class BagAddressLookupTest extends TestCase {

	/**
	 * The searches made.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $searches = [];

	private static function address(string $id, array $fields = []): array {
		return $fields + [
			'identificatie' => $id,
			'huisnummer' => 12,
			'postcode' => '2611AB',
			'openbareRuimteNaam' => 'Lindelaan',
			'woonplaatsNaam' => 'Zuiddrecht',
			'status' => 'Naamgeving uitgegeven',
			'typeAdresseerbaarObject' => 'Verblijfsobject',
		];
	}

	private function lookup(array $rows): BagAddressLookup {
		$objects = $this->createMock(ObjectOperationsInterface::class);
		$objects->method('searchObjectsBySlug')->willReturnCallback(
			function (string $registerSlug, string $schemaSlug, array $filters = [], bool $_rbac = true, bool $_multitenancy = true) use ($rows): array {
				$this->searches[] = ['register' => $registerSlug, 'schema' => $schemaSlug, 'filters' => $filters, 'rbac' => $_rbac, 'multitenancy' => $_multitenancy];
				return $rows;
			}
		);

		return new BagAddressLookup($objects);
	}

	public function testAPostcodeWithASpaceInLowerCaseFindsTheAddress(): void {
		$answer = $this->lookup([self::address('0599200000001234', ['adresseerbaarObject' => '0599010000005678'])])->find(' 2611 ab ', '12');

		$this->assertSame(
			[
				'results' => [[
					'nummeraanduiding' => '0599200000001234',
					'verblijfsobject' => '0599010000005678',
					'straat' => 'Lindelaan',
					'huisnummer' => 12,
					'huisletter' => null,
					'toevoeging' => null,
					'postcode' => '2611AB',
					'plaats' => 'Zuiddrecht',
				]],
				'total' => 1,
			],
			$answer
		);
		$this->assertSame(
			[['register' => 'bag', 'schema' => 'nummeraanduiding', 'filters' => ['postcode' => '2611AB', 'huisnummer' => 12, '_limit' => 100], 'rbac' => false, 'multitenancy' => false]],
			$this->searches
		);
	}

	public function testANumberWithSeveralAdditionsAnswersEveryOneInOrder(): void {
		$rows = [
			self::address('3', ['huisnummertoevoeging' => 'b']),
			self::address('1', ['huisletter' => 'a']),
			self::address('2', ['huisnummertoevoeging' => 'A']),
		];

		$answer = $this->lookup($rows)->find('2611AB', '12');

		$this->assertSame(3, $answer['total']);
		$this->assertSame(['2', '3', '1'], array_column($answer['results'], 'nummeraanduiding'));
		$this->assertSame([null, null, 'A'], array_column($answer['results'], 'huisletter'));
		$this->assertSame(['A', 'B', null], array_column($answer['results'], 'toevoeging'));
	}

	public function testALetterOrAdditionNarrowsTheAnswer(): void {
		$rows = [
			self::address('1', ['huisletter' => 'A']),
			self::address('2', ['huisletter' => 'B']),
			self::address('3', ['huisnummertoevoeging' => 'BIS']),
		];

		$this->assertSame(['2'], array_column($this->lookup($rows)->find('2611AB', '12', 'b')['results'], 'nummeraanduiding'));
		$this->assertSame(['3'], array_column($this->lookup($rows)->find('2611AB', '12', null, ' bis ')['results'], 'nummeraanduiding'));
	}

	public function testWithdrawnAndOtherAddressesAreSkipped(): void {
		$rows = [
			self::address('withdrawn', ['status' => 'Naamgeving ingetrokken']),
			self::address('other-number', ['huisnummer' => 14]),
			self::address('other-postcode', ['postcode' => '2611AC']),
		];

		$this->assertSame(['results' => [], 'total' => 0], $this->lookup($rows)->find('2611AB', '12'));
	}

	public function testNoMatchAnswersAnEmptyList(): void {
		$this->assertSame(['results' => [], 'total' => 0], $this->lookup([])->find('9999ZZ', '1'));
	}

	public function testAnExpandedVerblijfsobjectIsAnsweredByItsIdentification(): void {
		$answer = $this->lookup([self::address('1', ['adresseerbaarObject' => ['identificatie' => '0599010000009999']])])->find('2611AB', '12');

		$this->assertSame('0599010000009999', $answer['results'][0]['verblijfsobject']);
	}

	/**
	 * @return array<string, array{0: string, 1: string, 2: ?string, 3: ?string}>
	 */
	public static function invalidInput(): array {
		return [
			'postcode starting with 0' => ['0611AB', '12', null, null],
			'postcode without letters' => ['2611', '12', null, null],
			'number zero' => ['2611AB', '0', null, null],
			'number with a letter' => ['2611AB', '12a', null, null],
			'two letters' => ['2611AB', '12', 'ab', null],
			'addition too long' => ['2611AB', '12', null, 'ABCDE'],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('invalidInput')]
	public function testInputThatIsNoAddressIsRefusedWithoutASearch(string $postcode, string $number, ?string $letter, ?string $addition): void {
		$lookup = $this->lookup([]);

		try {
			$lookup->find($postcode, $number, $letter, $addition);
			$this->fail('Expected the input to be refused');
		} catch (InvalidArgumentException $e) {
			$this->assertNotSame('', $e->getMessage());
		}

		$this->assertSame([], $this->searches);
	}
}
