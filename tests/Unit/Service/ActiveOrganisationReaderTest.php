<?php

/**
 * Unit tests for the active-organisation contract and its Core adapter.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
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

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Kernel\Contracts\ActiveOrganisationReaderInterface;
use OCA\OpenRegister\Service\ActiveOrganisationReader;
use OCA\OpenRegister\Service\CoreServiceBindings;
use OCA\OpenRegister\Service\OrganisationServiceInterface;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\ActiveOrganisationReader
 * @uses   \OCA\OpenRegister\Db\Organisation
 */
class ActiveOrganisationReaderTest extends TestCase {

	/**
	 * The reader over an active organisation with this UUID, or none.
	 *
	 * @param string|null|false $uuid The UUID, null for an organisation without one, false for no organisation.
	 *
	 * @return ActiveOrganisationReader
	 */
	private function reader(string|null|false $uuid): ActiveOrganisationReader {
		$service = $this->createMock(OrganisationServiceInterface::class);
		if ($uuid === false) {
			$service->method('getActiveOrganisation')->willReturn(null);
		} else {
			$organisation = new Organisation();
			$organisation->setUuid($uuid);
			$service->method('getActiveOrganisation')->willReturn($organisation);
		}

		return new ActiveOrganisationReader(organisationService: $service);
	}//end reader()

	/**
	 * The active organisation's UUID comes back as it is.
	 *
	 * @return void
	 */
	public function testAnswersTheActiveOrganisationsUuid(): void {
		$this->assertSame('org-a', $this->reader('org-a')->activeOrganisationUuid());
	}//end testAnswersTheActiveOrganisationsUuid()

	/**
	 * No organisation, one without a UUID and a blank UUID all answer null.
	 *
	 * @return void
	 */
	public function testNoUsableOrganisationAnswersNull(): void {
		$this->assertNull($this->reader(false)->activeOrganisationUuid());
		$this->assertNull($this->reader(null)->activeOrganisationUuid());
		$this->assertNull($this->reader('  ')->activeOrganisationUuid());
	}//end testNoUsableOrganisationAnswersNull()

	/**
	 * The contract is implemented and bound, or the controller that types against it is a 500.
	 *
	 * @return void
	 */
	public function testTheContractIsImplementedAndBound(): void {
		$this->assertTrue(is_subclass_of(ActiveOrganisationReader::class, ActiveOrganisationReaderInterface::class));
		$this->assertSame(ActiveOrganisationReader::class, CoreServiceBindings::BINDINGS[ActiveOrganisationReaderInterface::class]);
	}//end testTheContractIsImplementedAndBound()
}//end class
