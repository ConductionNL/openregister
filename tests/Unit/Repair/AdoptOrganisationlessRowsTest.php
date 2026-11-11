<?php

/**
 * Which organisation the repair step gives a stored object without one.
 *
 * Pins the order register, schema, default organisation; that a table none of
 * them covers is left as it is and reported; that a table without org-less
 * rows is not written; and that a failing table does not stop the others.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Repair;

use OCA\OpenRegister\Command\MagicTableSelection;
use OCA\OpenRegister\Command\MagicTableTarget;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Repair\AdoptOrganisationlessRows;
use OCA\OpenRegister\Service\Organisation\OrganisationlessRowAdoption;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class AdoptOrganisationlessRowsTest extends TestCase {

	private static function target(?string $registerOrg, ?string $schemaOrg, string $table): MagicTableTarget {
		$register = new Register();
		$register->setId(1);
		$register->setSlug('r');
		$register->setOrganisation($registerOrg);
		$schema = new Schema();
		$schema->setId(2);
		$schema->setSlug('s');
		$schema->setOrganisation($schemaOrg);

		return new MagicTableTarget(register: $register, schema: $schema, tableName: $table);
	}//end target()

	private function adoption(string $default = '', string $legacy = ''): OrganisationlessRowAdoption {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key): string => match ($key) {
				'defaultOrganisation' => $default,
				'organisation' => $legacy,
				default => '',
			}
		);

		return new OrganisationlessRowAdoption(db: $this->createMock(IDBConnection::class), appConfig: $config);
	}//end adoption()

	public function testTheRegistersOrganisationComesFirstThenTheSchemasThenTheDefault(): void {
		$adoption = $this->adoption(default: 'org-default');

		$this->assertSame('org-register', $adoption->organisationFor(target: self::target(registerOrg: 'org-register', schemaOrg: 'org-schema', table: 't')));
		$this->assertSame('org-schema', $adoption->organisationFor(target: self::target(registerOrg: ' ', schemaOrg: 'org-schema', table: 't')));
		$this->assertSame('org-default', $adoption->organisationFor(target: self::target(registerOrg: null, schemaOrg: null, table: 't')));
	}//end testTheRegistersOrganisationComesFirstThenTheSchemasThenTheDefault()

	public function testTheLegacyDefaultOrganisationSettingIsRead(): void {
		$adoption = $this->adoption(legacy: '{"default_organisation":"org-legacy"}');

		$this->assertSame('org-legacy', $adoption->organisationFor(target: self::target(registerOrg: null, schemaOrg: null, table: 't')));
		$this->assertNull($this->adoption()->organisationFor(target: self::target(registerOrg: null, schemaOrg: null, table: 't')));
	}//end testTheLegacyDefaultOrganisationSettingIsRead()

	public function testOnlyTablesWithOrgLessRowsAndAKnownOrganisationAreWritten(): void {
		$targets = [
			self::target(registerOrg: 'org-a', schemaOrg: null, table: 'with_rows'),
			self::target(registerOrg: 'org-b', schemaOrg: null, table: 'already_done'),
			self::target(registerOrg: null, schemaOrg: null, table: 'no_owner'),
			self::target(registerOrg: 'org-c', schemaOrg: null, table: 'broken'),
			self::target(registerOrg: null, schemaOrg: 'org-d', table: 'after_broken'),
		];
		$selection = $this->createMock(MagicTableSelection::class);
		$selection->method('registers')->willReturn([]);
		$selection->method('schemas')->willReturn([]);
		$selection->method('tables')->willReturn($targets);

		$adoption = $this->getMockBuilder(OrganisationlessRowAdoption::class)
			->setConstructorArgs([$this->createMock(IDBConnection::class), $this->createMock(IAppConfig::class)])
			->onlyMethods(['count', 'adopt'])
			->getMock();
		$adoption->method('count')->willReturnCallback(static function (string $tableName): int {
			if ($tableName === 'broken') {
				throw new RuntimeException('gone');
			}

			return ($tableName === 'already_done') ? 0 : 3;
		});
		$written = [];
		$adoption->method('adopt')->willReturnCallback(static function (string $tableName, string $organisation) use (&$written): int {
			$written[$tableName] = $organisation;
			return 3;
		});

		$warnings = [];
		$output = $this->createMock(IOutput::class);
		$output->method('warning')->willReturnCallback(static function (string $message) use (&$warnings): void {
			$warnings[] = $message;
		});

		(new AdoptOrganisationlessRows(selection: $selection, adoption: $adoption, logger: $this->createMock(LoggerInterface::class)))->run($output);

		$this->assertSame(['with_rows' => 'org-a', 'after_broken' => 'org-d'], $written);
		$this->assertCount(2, $warnings);
		$this->assertStringContainsString('no_owner', $warnings[0]);
		$this->assertStringContainsString('broken', $warnings[1]);
	}//end testOnlyTablesWithOrgLessRowsAndAKnownOrganisationAreWritten()
}//end class
