<?php

/**
 * Unit tests for the export-key list of the admin settings.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Exchange
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/exchange-encrypted-instance-export/specs/data-import-export/spec.md#requirement-export-keys-are-kept-in-the-credential-broker
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Exchange;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Exchange\BrokeredKeys;
use OCA\OpenRegister\Service\Exchange\ExportKeyDirectory;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Exchange\ExportKeyDirectory
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 */
class ExportKeyDirectoryTest extends TestCase {

	/**
	 * A listed credential object.
	 *
	 * @param string $uuid         Its id.
	 * @param array  $data         Its fields.
	 *
	 * @return ObjectEntity
	 */
	private function credential(string $uuid, array $data): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setObject($data);

		return $object;
	}//end credential()

	/**
	 * The directory over these listed objects, asserting how they are listed.
	 *
	 * @param array<int, mixed> $listed  What findAll returns.
	 * @param int               $lookups How often the store may be asked.
	 *
	 * @return ExportKeyDirectory
	 */
	private function directory(array $listed, int $lookups = 1): ExportKeyDirectory {
		$objects = $this->createMock(ObjectOperationsInterface::class);
		$objects->expects($this->exactly($lookups))->method('setRegister')->with('credential-broker')->willReturnSelf();
		$objects->method('setSchema')->with('brokeredcredential')->willReturnSelf();
		$objects->expects($this->exactly($lookups))->method('findAll')->willReturnCallback(
			function (array $config, bool $_rbac, bool $_multitenancy) use ($listed): array {
				$this->assertSame(['filters' => ['provider' => BrokeredKeys::EXPORT_PROVIDER]], $config);
				$this->assertFalse($_rbac);
				$this->assertFalse($_multitenancy);
				return $listed;
			}
		);

		return new ExportKeyDirectory(objectService: $objects);
	}//end directory()

	/**
	 * The organisation's keys come back newest first, each with its fingerprint and date.
	 *
	 * @return void
	 */
	public function testListsTheOrganisationsKeysNewestFirstByFingerprint(): void {
		$directory = $this->directory(
			[
				$this->credential('old', ['provider' => BrokeredKeys::EXPORT_PROVIDER, 'organisation' => 'org-a', 'name' => 'Export key 0123456789abcdef', 'createdAt' => '2026-01-01T10:00:00+00:00']),
				$this->credential('new', ['provider' => BrokeredKeys::EXPORT_PROVIDER, 'organisation' => 'org-a', 'name' => 'Export key fedcba9876543210', 'createdAt' => '2026-06-01T10:00:00+00:00']),
			]
		);

		$this->assertSame(
			[
				['id' => 'new', 'fingerprint' => 'fedcba9876543210', 'createdAt' => '2026-06-01T10:00:00+00:00'],
				['id' => 'old', 'fingerprint' => '0123456789abcdef', 'createdAt' => '2026-01-01T10:00:00+00:00'],
			],
			$directory->list(organisation: 'org-a')
		);
	}//end testListsTheOrganisationsKeysNewestFirstByFingerprint()

	/**
	 * Another organisation's key, another provider's credential and a non-object are left out.
	 *
	 * @return void
	 */
	public function testLeavesOutOtherOrganisationsAndOtherProviders(): void {
		$directory = $this->directory(
			[
				$this->credential('mine', ['provider' => BrokeredKeys::EXPORT_PROVIDER, 'organisation' => 'org-a', 'name' => 'Export key 0123456789abcdef', 'createdAt' => '2026-01-01T10:00:00+00:00']),
				$this->credential('theirs', ['provider' => BrokeredKeys::EXPORT_PROVIDER, 'organisation' => 'org-b', 'name' => 'Export key 1111111111111111', 'createdAt' => '2026-02-01T10:00:00+00:00']),
				$this->credential('job', ['provider' => 'openregister-job-key', 'organisation' => 'org-a', 'name' => 'Job key', 'createdAt' => '2026-03-01T10:00:00+00:00']),
				['provider' => BrokeredKeys::EXPORT_PROVIDER, 'organisation' => 'org-a'],
			]
		);

		$this->assertSame(['mine'], array_column($directory->list(organisation: 'org-a'), 'id'));
	}//end testLeavesOutOtherOrganisationsAndOtherProviders()

	/**
	 * A key without a recognisable name or date is listed with nulls, and sorts last.
	 *
	 * @return void
	 */
	public function testAKeyWithoutNameOrDateIsListedWithNullsAndSortsLast(): void {
		$directory = $this->directory(
			[
				$this->credential('bare', ['provider' => BrokeredKeys::EXPORT_PROVIDER, 'organisation' => 'org-a', 'name' => 'Renamed by hand']),
				$this->credential('dated', ['provider' => BrokeredKeys::EXPORT_PROVIDER, 'organisation' => 'org-a', 'name' => 'Export key 0123456789abcdef', 'createdAt' => '2026-01-01T10:00:00+00:00']),
			]
		);

		$this->assertSame(
			[
				['id' => 'dated', 'fingerprint' => '0123456789abcdef', 'createdAt' => '2026-01-01T10:00:00+00:00'],
				['id' => 'bare', 'fingerprint' => null, 'createdAt' => null],
			],
			$directory->list(organisation: 'org-a')
		);
	}//end testAKeyWithoutNameOrDateIsListedWithNullsAndSortsLast()

	/**
	 * No organisation asks the store nothing and lists nothing.
	 *
	 * @return void
	 */
	public function testAnEmptyOrganisationListsNothingWithoutAskingTheStore(): void {
		$this->assertSame([], $this->directory([], 0)->list(organisation: '  '));
	}//end testAnEmptyOrganisationListsNothingWithoutAskingTheStore()

	/**
	 * Listing never carries a secret: only id, fingerprint and date.
	 *
	 * @return void
	 */
	public function testARowCarriesNoSecretField(): void {
		$directory = $this->directory(
			[
				$this->credential('k', ['provider' => BrokeredKeys::EXPORT_PROVIDER, 'organisation' => 'org-a', 'name' => 'Export key 0123456789abcdef', 'createdAt' => '2026-01-01T10:00:00+00:00', 'secret' => 'leak']),
			]
		);

		$rows = $directory->list(organisation: 'org-a');

		$this->assertSame(['id', 'fingerprint', 'createdAt'], array_keys($rows[0]));
	}//end testARowCarriesNoSecretField()
}//end class
