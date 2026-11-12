<?php

/**
 * Characterisation of the projected-field write and the organisation search.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ObjectSource
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/the-organisation-projection-is-writable/specs/organisation-projection/spec.md#requirement-only-the-identity-facet-is-writable-req-orp-107
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ObjectSource;

use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterSchema;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\ObjectSource\OrganisationActingUser;
use OCA\OpenRegister\Service\ObjectSource\OrganisationObjectSourceProvider;
use OCA\OpenRegister\Service\ObjectSource\SourcedTable;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * One case per branch of the projected-field write and of the search match.
 */
class OrganisationProjectedFieldsTest extends TestCase {

	/**
	 * Update an administered organisation with this data and return it and whether it was saved.
	 *
	 * @param array<string, mixed> $data The update.
	 *
	 * @return array{0: Organisation, 1: int}
	 */
	private function update(array $data): array {
		$existing = new Organisation();
		$existing->setUuid('org-uuid');
		$existing->setName('Gemeente Utrecht');
		$existing->setOin('0001');
		$existing->setKvk('123');

		$saves  = 0;
		$mapper = $this->createMock(OrganisationMapper::class);
		$mapper->method('findByUuid')->willReturn($existing);
		$mapper->method('save')->willReturnCallback(
			static function (Organisation $organisation) use (&$saves): Organisation {
				$saves++;
				return $organisation;
			}
		);
		$service = $this->createMock(OrganisationService::class);
		$service->method('isOrganisationAdmin')->willReturn(true);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$provider = new OrganisationObjectSourceProvider(
			organisationMapper: $mapper,
			actingUser: new OrganisationActingUser(userSession: $session, groupManager: $this->createMock(IGroupManager::class)),
			logger: $this->createMock(LoggerInterface::class),
			organisationService: $service
		);
		$provider->update(source: new SourcedTable(new RegisterSchema(new Register(), new Schema())), id: 'org-uuid', data: $data);

		return [$existing, $saves];
	}

	public function testScalarsAreWrittenAsStringsAndNullClearsAField(): void {
		[$organisation, $saves] = $this->update(['oin' => 42, 'kvk' => null, 'description' => true]);

		$this->assertSame('42', $organisation->getOin());
		$this->assertNull($organisation->getKvk());
		$this->assertSame('1', $organisation->getDescription());
		$this->assertSame(1, $saves);
	}

	public function testANonScalarValueIsSkippedAndAloneSavesNothing(): void {
		[$organisation, $saves] = $this->update(['oin' => ['x'], 'kvk' => new \stdClass()]);

		$this->assertSame('0001', $organisation->getOin());
		$this->assertSame('123', $organisation->getKvk());
		$this->assertSame(0, $saves);
	}

	public function testANonScalarValueDoesNotStopTheOtherFields(): void {
		[$organisation, $saves] = $this->update(['oin' => ['x'], 'summary' => 'Een gemeente']);

		$this->assertSame('0001', $organisation->getOin());
		$this->assertSame('Een gemeente', $organisation->getSummary());
		$this->assertSame(1, $saves);
	}

	/**
	 * @param array<string, string|null> $fields Field values.
	 */
	private function organisation(string $uuid, array $fields, ?string $mergedInto = null): Organisation {
		$organisation = new Organisation();
		$organisation->setUuid($uuid);
		foreach ($fields as $field => $value) {
			$organisation->{'set' . ucfirst($field)}($value);
		}

		$organisation->setMergedInto($mergedInto);

		return $organisation;
	}

	public function testTheSearchMatchesEachTypedFieldAndSkipsMergedAndForeignEntries(): void {
		$byName = $this->organisation('a', ['name' => 'Gemeente Utrecht']);
		$byOin  = $this->organisation('b', ['name' => 'X', 'oin' => '00000001UTR']);
		$byRsin = $this->organisation('c', ['name' => 'Y', 'rsin' => 'utr-rsin']);
		$byKvk  = $this->organisation('d', ['name' => 'Z', 'kvk' => 'KVK-UTR']);
		$none   = $this->organisation('e', ['name' => 'Amersfoort']);
		$merged = $this->organisation('f', ['name' => 'Utrecht oud'], 'a');

		$all = [$byName, $byOin, 'not an organisation', $byRsin, $byKvk, $none, $merged];

		$this->assertSame(
			[0 => $byName, 1 => $byOin, 3 => $byRsin, 4 => $byKvk],
			OrganisationObjectSourceProvider::matching($all, 'UTR')
		);
		$this->assertSame(
			[0 => $byName, 1 => $byOin, 3 => $byRsin, 4 => $byKvk, 5 => $none],
			OrganisationObjectSourceProvider::matching($all, '')
		);
	}
}
