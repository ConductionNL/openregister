<?php

/**
 * OpenRegister BAG bulk loader
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

use DateTime;
use InvalidArgumentException;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\BulkBatchDefaults;
use OCA\OpenRegister\Kernel\Contracts\Value\BulkSaveOptions;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Loads a BAG address extract into the bag register as an upsert keyed on `identificatie`.
 *
 * Rows are normalised the way the lookup reads them, compared with what the
 * register holds, and only new or changed rows are written, in chunks through
 * the bulk save path, without an audit row per object. A withdrawn address
 * keeps its BAG status, which the lookup skips. The load itself writes one
 * audit line with the counts.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Bag
 *
 * @spec openspec/changes/bag-address-lookup-by-postcode/specs/bag-address-lookup/spec.md#requirement-the-bag-register-holds-the-national-address-set-req-bag-001
 */
class BagBulkLoader {

	/**
	 * Rows read, compared and saved together.
	 */
	public const CHUNK = 5000;

	/**
	 * The audit action of a load.
	 */
	public const AUDIT_ACTION = 'bag.load';

	/**
	 * The status of an address in use.
	 */
	public const ISSUED = 'Naamgeving uitgegeven';

	/**
	 * The namespace the uuid of a new address is derived in, so a repeated load finds the same object.
	 */
	private const UUID_NAMESPACE = '6ba7b811-9dad-11d1-80b4-00c04fd430c8';

	/**
	 * The properties a load writes and compares.
	 *
	 * @var list<string>
	 */
	private const PROPERTIES = [
		'identificatie',
		'huisnummer',
		'huisletter',
		'huisnummertoevoeging',
		'postcode',
		'status',
		'typeAdresseerbaarObject',
		'openbareRuimteNaam',
		'woonplaatsNaam',
		'adresseerbaarObject',
	];

	/**
	 * Constructor.
	 *
	 * @param ObjectOperationsInterface $objects The bag register.
	 * @param BagExtractReader          $reader  Reads an extract file.
	 * @param BagExtractSource          $source  Turns a source into a local file.
	 * @param AuditTrailMapperInterface $audit   Records the load.
	 * @param LoggerInterface           $logger  Records rows that could not be saved.
	 *
	 * @spec openspec/changes/bag-address-lookup-by-postcode/specs/bag-address-lookup/spec.md#requirement-the-bag-register-holds-the-national-address-set-req-bag-001
	 */
	public function __construct(
		private readonly ObjectOperationsInterface $objects,
		private readonly BagExtractReader $reader,
		private readonly BagExtractSource $source,
		private readonly AuditTrailMapperInterface $audit,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Load an extract from a local path or an http(s) URL.
	 *
	 * @param string $source The extract: a file path or URL.
	 *
	 * @return array{created: int, updated: int, withdrawn: int, unchanged: int, invalid: int, failed: int} The counts.
	 *
	 * @throws InvalidArgumentException When the source cannot be read.
	 *
	 * @spec openspec/changes/bag-address-lookup-by-postcode/specs/bag-address-lookup/spec.md#requirement-the-bag-register-holds-the-national-address-set-req-bag-001
	 */
	public function loadFrom(string $source): array {
		$path = $this->source->localPath(source: $source);
		try {
			return $this->load(rows: $this->reader->rows(path: $path), label: $source);
		} finally {
			$this->source->release(path: $path, source: $source);
		}
	}//end loadFrom()

	/**
	 * Upsert rows into the bag register and write one audit line with the counts.
	 *
	 * @param iterable<array<string, mixed>> $rows  The rows, keyed by schema property.
	 * @param string                         $label Where they came from, for the audit line.
	 *
	 * @return array{created: int, updated: int, withdrawn: int, unchanged: int, invalid: int, failed: int} The counts.
	 *
	 * @spec openspec/changes/bag-address-lookup-by-postcode/specs/bag-address-lookup/spec.md#requirement-the-bag-register-holds-the-national-address-set-req-bag-001
	 */
	public function load(iterable $rows, string $label): array {
		$counts = ['created' => 0, 'updated' => 0, 'withdrawn' => 0, 'unchanged' => 0, 'invalid' => 0, 'failed' => 0];
		$chunk  = [];
		foreach ($rows as $row) {
			$address = self::normalise(row: $row);
			if ($address === null) {
				$counts['invalid']++;
				continue;
			}

			$chunk[$address['identificatie']] = $address;
			if (count($chunk) >= self::CHUNK) {
				$this->flush(chunk: $chunk, counts: $counts);
				$chunk = [];
			}
		}

		if ($chunk !== []) {
			$this->flush(chunk: $chunk, counts: $counts);
		}

		$this->recordLoad(counts: $counts, label: $label);

		return $counts;
	}//end load()

	/**
	 * A row as the register stores it, or null when it is no address.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return array<string, mixed>|null The normalised address.
	 *
	 * @spec openspec/changes/bag-address-lookup-by-postcode/specs/bag-address-lookup/spec.md#requirement-the-bag-register-holds-the-national-address-set-req-bag-001
	 */
	public static function normalise(array $row): ?array {
		$identificatie = trim((string) ($row['identificatie'] ?? ''));
		if (preg_match('/^[0-9]{16}$/', $identificatie) !== 1) {
			return null;
		}

		try {
			$address = [
				'identificatie' => $identificatie,
				'huisnummer' => BagAddressLookup::normaliseNumber(number: (string) ($row['huisnummer'] ?? '')),
				'huisletter' => BagAddressLookup::normaliseLetter(letter: self::text(value: ($row['huisletter'] ?? null))),
				'huisnummertoevoeging' => BagAddressLookup::normaliseAddition(addition: self::text(value: ($row['huisnummertoevoeging'] ?? null))),
				'postcode' => BagAddressLookup::normalisePostcode(postcode: (string) ($row['postcode'] ?? '')),
			];
		} catch (InvalidArgumentException $e) {
			return null;
		}

		$address['status'] = (self::text(value: ($row['status'] ?? null)) ?? self::ISSUED);
		foreach (['typeAdresseerbaarObject', 'openbareRuimteNaam', 'woonplaatsNaam', 'adresseerbaarObject'] as $property) {
			$address[$property] = self::text(value: ($row[$property] ?? null));
		}

		return array_filter($address, static fn (mixed $value): bool => $value !== null);
	}//end normalise()

	/**
	 * The uuid a new address gets, the same on every load.
	 *
	 * @param string $identificatie The nummeraanduiding id.
	 *
	 * @return string The uuid.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) Uuid::v5/fromString are the standard symfony/uid factories.
	 *
	 * @spec openspec/changes/bag-address-lookup-by-postcode/specs/bag-address-lookup/spec.md#requirement-the-bag-register-holds-the-national-address-set-req-bag-001
	 */
	public static function uuidFor(string $identificatie): string {
		return Uuid::v5(Uuid::fromString(self::UUID_NAMESPACE), 'openregister:bag:nummeraanduiding:' . $identificatie)->toRfc4122();
	}//end uuidFor()

	/**
	 * Compare one chunk with the register and save what is new or changed.
	 *
	 * @param array<string, array<string, mixed>> $chunk  The addresses, by identificatie.
	 * @param array<string, int>                  $counts The counts, updated in place.
	 *
	 * @return void
	 */
	private function flush(array $chunk, array &$counts): void {
		$existing = $this->existing(identificaties: array_map('strval', array_keys($chunk)));
		$save     = [];
		foreach ($chunk as $identificatie => $address) {
			$identificatie = (string) $identificatie;
			$stored        = ($existing[$identificatie] ?? null);
			$withdrawn     = $address['status'] === BagAddressLookup::WITHDRAWN;
			if ($stored !== null && self::sameAddress(stored: $stored['data'], address: $address) === true) {
				$counts['unchanged']++;
				continue;
			}

			$wasWithdrawn = $stored !== null && ($stored['data']['status'] ?? null) === BagAddressLookup::WITHDRAWN;
			$bucket       = 'updated';
			if ($withdrawn === true && $wasWithdrawn === false) {
				$bucket = 'withdrawn';
			} else if ($stored === null) {
				$bucket = 'created';
			}

			$counts[$bucket]++;
			$uuid   = ($stored['uuid'] ?? self::uuidFor(identificatie: $identificatie));
			$save[] = $address + ['@self' => ['id' => $uuid, 'slug' => $identificatie]];
		}

		if ($save === []) {
			return;
		}

		$result = $this->objects->saveObjects(
			objects: $save,
			options: new BulkSaveOptions(
				defaults: new BulkBatchDefaults(register: BagAddressLookup::REGISTER, schema: BagAddressLookup::SCHEMA),
				validation: false,
				events: false,
				audit: false
			),
			_rbac: false,
			_multitenancy: false
		);
		$failed = (int) (($result['statistics']['invalid'] ?? 0) + ($result['statistics']['errors'] ?? 0));
		if ($failed > 0) {
			$counts['failed'] += $failed;
			$this->logger->warning(
				message: '[BagBulkLoader] Some addresses of a chunk could not be saved',
				context: ['failed' => $failed, 'chunk' => count($save)]
			);
		}
	}//end flush()

	/**
	 * The stored addresses among the given ids, with their uuid.
	 *
	 * @param list<string> $identificaties The nummeraanduiding ids.
	 *
	 * @return array<string, array{uuid: string, data: array<string, mixed>}> By identificatie.
	 */
	private function existing(array $identificaties): array {
		$rows = $this->objects->searchObjectsBySlug(
			registerSlug: BagAddressLookup::REGISTER,
			schemaSlug: BagAddressLookup::SCHEMA,
			filters: ['identificatie' => $identificaties, '_limit' => count($identificaties)],
			_rbac: false,
			_multitenancy: false
		);

		$wanted = array_flip($identificaties);
		$found  = [];
		foreach ((array) $rows as $row) {
			[$uuid, $data] = self::uuidAndData(row: $row);
			$identificatie = (string) ($data['identificatie'] ?? '');
			if ($uuid === null || isset($wanted[$identificatie]) === false) {
				continue;
			}

			$found[$identificatie] = ['uuid' => $uuid, 'data' => $data];
		}

		return $found;
	}//end existing()

	/**
	 * The uuid and data of a found row.
	 *
	 * @param mixed $row An entity or a rendered object.
	 *
	 * @return array{0: string|null, 1: array<string, mixed>} The uuid (null when it has none) and the data.
	 */
	private static function uuidAndData(mixed $row): array {
		if (is_object($row) === true && method_exists($row, 'getObject') === true && method_exists($row, 'getUuid') === true) {
			return [self::text(value: $row->getUuid()), (array) $row->getObject()];
		}

		if (is_array($row) === false) {
			return [null, []];
		}

		$self = ($row['@self'] ?? []);
		if (is_array($self) === false) {
			$self = [];
		}

		$uuid = self::text(value: ($self['id'] ?? ($self['uuid'] ?? ($row['id'] ?? null))));

		return [$uuid, $row];
	}//end uuidAndData()

	/**
	 * Whether a stored address already holds what the extract says.
	 *
	 * @param array<string, mixed> $stored  The stored data.
	 * @param array<string, mixed> $address The normalised address.
	 *
	 * @return bool True when no property differs.
	 */
	private static function sameAddress(array $stored, array $address): bool {
		foreach (self::PROPERTIES as $property) {
			if ((string) self::text(value: ($stored[$property] ?? null)) !== (string) self::text(value: ($address[$property] ?? null))) {
				return false;
			}
		}

		return true;
	}//end sameAddress()

	/**
	 * Write the one audit line of a load.
	 *
	 * @param array<string, int> $counts The counts.
	 * @param string             $label  Where the rows came from.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) Uuid::v4 is the standard symfony/uid factory.
	 */
	private function recordLoad(array $counts, string $label): void {
		$entry = new AuditTrail();
		$entry->setUuid(Uuid::v4()->toRfc4122());
		$entry->setAction(self::AUDIT_ACTION);
		$entry->setUser('system');
		$entry->setUserName('BAG load');
		$entry->setCreated(new DateTime());
		$entry->setChanged($counts + ['source' => self::sourceLabel(source: $label)]);
		$this->audit->insert(entity: $entry);
	}//end recordLoad()

	/**
	 * A source as the audit line names it: a URL without its query or credentials, a file by its name.
	 *
	 * @param string $source The source.
	 *
	 * @return string The label.
	 */
	private static function sourceLabel(string $source): string {
		$parts = parse_url($source);
		if (is_array($parts) === true && isset($parts['scheme'], $parts['host']) === true) {
			return $parts['scheme'] . '://' . $parts['host'] . ($parts['path'] ?? '');
		}

		return basename($source);
	}//end sourceLabel()

	/**
	 * A scalar as trimmed text, or null when empty.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string|null The text.
	 */
	private static function text(mixed $value): ?string {
		if (is_scalar($value) === false) {
			return null;
		}

		$value = trim((string) $value);
		if ($value === '') {
			return null;
		}

		return $value;
	}//end text()
}//end class
