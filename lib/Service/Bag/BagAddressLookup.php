<?php

/**
 * OpenRegister BAG address lookup
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\Bag
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Bag;

use InvalidArgumentException;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;

/**
 * Finds the addresses at a postcode and house number in the bag register.
 *
 * The input is normalised the way the register stores it, so "2611 ab" finds
 * "2611AB". Withdrawn addresses are skipped. With only postcode and number
 * every letter and addition at that number is returned, so a form can ask
 * which one.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Bag
 *
 * @spec openspec/changes/bag-address-lookup-by-postcode/specs/bag-address-lookup/spec.md#requirement-an-address-is-found-by-postcode-and-house-number-req-bag-002
 */
final class BagAddressLookup {

	/**
	 * The register of the BAG addresses.
	 */
	public const REGISTER = 'bag';

	/**
	 * The schema of a BAG address.
	 */
	public const SCHEMA = 'nummeraanduiding';

	/**
	 * The status of a withdrawn address.
	 */
	public const WITHDRAWN = 'Naamgeving ingetrokken';

	/**
	 * The most addresses one postcode and number can carry in an answer.
	 */
	private const LIMIT = 100;

	/**
	 * Constructor.
	 *
	 * @param ObjectOperationsInterface $objects Reads the bag register.
	 *
	 * @spec openspec/changes/bag-address-lookup-by-postcode/specs/bag-address-lookup/spec.md#requirement-an-address-is-found-by-postcode-and-house-number-req-bag-002
	 */
	public function __construct(
		private readonly ObjectOperationsInterface $objects,
	) {
	}//end __construct()

	/**
	 * The addresses at a postcode and house number, optionally narrowed by letter and addition.
	 *
	 * @param string      $postcode   The postcode, in any case and with or without a space.
	 * @param string      $number     The house number.
	 * @param string|null $letter     The house letter, if any.
	 * @param string|null $addition   The house number addition, if any.
	 *
	 * @return array{results: list<array<string, mixed>>, total: int} The addresses; none when nothing matches.
	 *
	 * @throws InvalidArgumentException When the postcode or number is not one.
	 *
	 * @spec openspec/changes/bag-address-lookup-by-postcode/specs/bag-address-lookup/spec.md#requirement-an-address-is-found-by-postcode-and-house-number-req-bag-002
	 */
	public function find(string $postcode, string $number, ?string $letter=null, ?string $addition=null): array {
		$postcode = self::normalisePostcode(postcode: $postcode);
		$number   = self::normaliseNumber(number: $number);
		$letter   = self::normaliseLetter(letter: $letter);
		$addition = self::normaliseAddition(addition: $addition);

		$rows = $this->objects->searchObjectsBySlug(
			registerSlug: self::REGISTER,
			schemaSlug: self::SCHEMA,
			filters: ['postcode' => $postcode, 'huisnummer' => $number, '_limit' => self::LIMIT],
			_rbac: false,
			_multitenancy: false
		);

		$results = [];
		foreach ((array) $rows as $row) {
			$address = self::dataOf(row: $row);
			if (self::matches(address: $address, postcode: $postcode, number: $number, letter: $letter, addition: $addition) === false) {
				continue;
			}

			$results[] = self::answer(address: $address);
		}

		usort($results, static fn (array $a, array $b): int => self::compareAnswers(a: $a, b: $b));

		return ['results' => $results, 'total' => count($results)];
	}//end find()

	/**
	 * A postcode as stored: four digits not starting with 0, then two upper case letters, no space.
	 *
	 * @param string $postcode The postcode.
	 *
	 * @return string The normalised postcode.
	 *
	 * @throws InvalidArgumentException When it is not a Dutch postcode.
	 *
	 * @spec openspec/changes/bag-address-lookup-by-postcode/specs/bag-address-lookup/spec.md#requirement-an-address-is-found-by-postcode-and-house-number-req-bag-002
	 */
	public static function normalisePostcode(string $postcode): string {
		$normalised = strtoupper((string) preg_replace('/\s+/', '', $postcode));
		if (preg_match('/^[1-9][0-9]{3}[A-Z]{2}$/', $normalised) !== 1) {
			throw new InvalidArgumentException('postcode must be four digits and two letters, such as 2611AB');
		}

		return $normalised;
	}//end normalisePostcode()

	/**
	 * A house number as stored: an integer from 1 to 99999.
	 *
	 * @param string $number The number.
	 *
	 * @return int The number.
	 *
	 * @throws InvalidArgumentException When it is not a house number.
	 *
	 * @spec openspec/changes/bag-address-lookup-by-postcode/specs/bag-address-lookup/spec.md#requirement-an-address-is-found-by-postcode-and-house-number-req-bag-002
	 */
	public static function normaliseNumber(string $number): int {
		$number = trim($number);
		if (preg_match('/^[0-9]{1,5}$/', $number) !== 1 || (int) $number < 1) {
			throw new InvalidArgumentException('huisnummer must be a whole number from 1 to 99999');
		}

		return (int) $number;
	}//end normaliseNumber()

	/**
	 * A house letter as stored: one upper case letter, or null.
	 *
	 * @param string|null $letter The letter.
	 *
	 * @return string|null The letter.
	 *
	 * @throws InvalidArgumentException When it is not one letter.
	 *
	 * @spec openspec/changes/bag-address-lookup-by-postcode/specs/bag-address-lookup/spec.md#requirement-an-address-is-found-by-postcode-and-house-number-req-bag-002
	 */
	public static function normaliseLetter(?string $letter): ?string {
		$letter = strtoupper(trim((string) $letter));
		if ($letter === '') {
			return null;
		}

		if (preg_match('/^[A-Z]$/', $letter) !== 1) {
			throw new InvalidArgumentException('huisletter must be one letter');
		}

		return $letter;
	}//end normaliseLetter()

	/**
	 * A house number addition as stored: up to four upper case letters or digits, or null.
	 *
	 * @param string|null $addition The addition.
	 *
	 * @return string|null The addition.
	 *
	 * @throws InvalidArgumentException When it is longer than four characters or not alphanumeric.
	 *
	 * @spec openspec/changes/bag-address-lookup-by-postcode/specs/bag-address-lookup/spec.md#requirement-an-address-is-found-by-postcode-and-house-number-req-bag-002
	 */
	public static function normaliseAddition(?string $addition): ?string {
		$addition = strtoupper(trim((string) $addition));
		if ($addition === '') {
			return null;
		}

		if (preg_match('/^[A-Z0-9]{1,4}$/', $addition) !== 1) {
			throw new InvalidArgumentException('toevoeging must be up to four letters or digits');
		}

		return $addition;
	}//end normaliseAddition()

	/**
	 * Whether a stored address is a live one at the asked postcode, number, letter and addition.
	 *
	 * The search narrows; this decides, so stored values in another case or a
	 * store that ignores a filter can never answer another address.
	 *
	 * @param array<string, mixed> $address  The stored address.
	 * @param string               $postcode The normalised postcode.
	 * @param int                  $number   The number.
	 * @param string|null          $letter   The normalised letter, or null for any.
	 * @param string|null          $addition The normalised addition, or null for any.
	 *
	 * @return bool True when it matches.
	 */
	private static function matches(array $address, string $postcode, int $number, ?string $letter, ?string $addition): bool {
		if ((string) ($address['status'] ?? '') === self::WITHDRAWN) {
			return false;
		}

		if (strtoupper(str_replace(' ', '', (string) ($address['postcode'] ?? ''))) !== $postcode
			|| (int) ($address['huisnummer'] ?? 0) !== $number
		) {
			return false;
		}

		if ($letter !== null && self::stored(value: ($address['huisletter'] ?? null)) !== $letter) {
			return false;
		}

		return $addition === null || self::stored(value: ($address['huisnummertoevoeging'] ?? null)) === $addition;
	}//end matches()

	/**
	 * An address as the lookup answers it.
	 *
	 * @param array<string, mixed> $address The stored address.
	 *
	 * @return array<string, mixed> The answer.
	 */
	private static function answer(array $address): array {
		$object = ($address['adresseerbaarObject'] ?? ($address['verblijfsobject'] ?? null));
		if (is_array($object) === true) {
			$object = ($object['identificatie'] ?? ($object['id'] ?? null));
		}

		if ($object === '') {
			$object = null;
		}

		if ($object !== null) {
			$object = (string) $object;
		}

		return [
			'nummeraanduiding' => (string) ($address['identificatie'] ?? ''),
			'verblijfsobject'  => $object,
			'straat'           => (string) ($address['openbareRuimteNaam'] ?? ''),
			'huisnummer'       => (int) ($address['huisnummer'] ?? 0),
			'huisletter'       => self::stored(value: ($address['huisletter'] ?? null)),
			'toevoeging'       => self::stored(value: ($address['huisnummertoevoeging'] ?? null)),
			'postcode'         => strtoupper(str_replace(' ', '', (string) ($address['postcode'] ?? ''))),
			'plaats'           => (string) ($address['woonplaatsNaam'] ?? ''),
		];
	}//end answer()

	/**
	 * Order answers by letter, then addition; the plain number first.
	 *
	 * @param array<string, mixed> $a An answer.
	 * @param array<string, mixed> $b Another answer.
	 *
	 * @return int The comparison.
	 */
	private static function compareAnswers(array $a, array $b): int {
		return [(string) $a['huisletter'], (string) $a['toevoeging']] <=> [(string) $b['huisletter'], (string) $b['toevoeging']];
	}//end compareAnswers()

	/**
	 * A stored letter or addition in upper case, or null when empty.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return string|null The value.
	 */
	private static function stored(mixed $value): ?string {
		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		return strtoupper(trim($value));
	}//end stored()

	/**
	 * A found row as an array.
	 *
	 * @param mixed $row The row.
	 *
	 * @return array<string, mixed> Its data.
	 */
	private static function dataOf(mixed $row): array {
		if (is_array($row) === true) {
			return $row;
		}

		if (is_object($row) === true && method_exists($row, 'getObject') === true) {
			return (array) $row->getObject();
		}

		if ($row instanceof \JsonSerializable) {
			return (array) $row->jsonSerialize();
		}

		return [];
	}//end dataOf()
}//end class
