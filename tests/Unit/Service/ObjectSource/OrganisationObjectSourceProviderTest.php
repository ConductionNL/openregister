<?php

/**
 * Unit tests for the organisation object projection.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ObjectSource
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ObjectSource;

use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Service\ObjectSource\OrganisationObjectSourceProvider;
use PHPUnit\Framework\TestCase;

/**
 * Locks what the projection exposes and what it refuses to offer.
 */
class OrganisationObjectSourceProviderTest extends TestCase {

	/**
	 * Build an organisation.
	 *
	 * @param string      $uuid       The uuid.
	 * @param string      $name       The name.
	 * @param string|null $oin        The OIN, if any.
	 * @param string|null $mergedInto The uuid it was merged into, if any.
	 *
	 * @return Organisation The organisation.
	 */
	private function organisation(
		string $uuid,
		string $name,
		?string $oin = null,
		?string $mergedInto = null
	): Organisation {
		$organisation = new Organisation();
		$organisation->setUuid($uuid);
		$organisation->setName($name);
		$organisation->setOin($oin);
		$organisation->setMergedInto($mergedInto);

		return $organisation;

	}//end organisation()

	/**
	 * The projection carries the uuid as the object id, so a stored reference
	 * resolves to the same record it always named.
	 *
	 * @return void
	 */
	public function testTheUuidIsTheObjectId(): void {
		$data = OrganisationObjectSourceProvider::project(
			organisation: $this->organisation(uuid: 'org-uuid', name: 'Gemeente Utrecht')
		);

		$this->assertSame('org-uuid', $data['id']);
		$this->assertSame('Gemeente Utrecht', $data['name']);

	}//end testTheUuidIsTheObjectId()

	/**
	 * The identity facet is projected, so a leaf app referencing an organisation
	 * gets the fields it used to keep its own copy of.
	 *
	 * @return void
	 */
	public function testTheIdentityFacetIsProjected(): void {
		$organisation = $this->organisation(uuid: 'org-uuid', name: 'Gemeente Utrecht', oin: '00000001002220647000');
		$organisation->setRsin('001234567');
		$organisation->setSummary('A municipality.');

		$data = OrganisationObjectSourceProvider::project(organisation: $organisation);

		$this->assertSame('00000001002220647000', $data['oin']);
		$this->assertSame('001234567', $data['rsin']);
		$this->assertSame('A municipality.', $data['summary']);

	}//end testTheIdentityFacetIsProjected()

	/**
	 * Tenancy administration is NOT projected. An object projection is for
	 * referencing an organisation, not for managing one, and putting quota or
	 * authorization behind the object API would make tenant configuration
	 * readable wherever an object is.
	 *
	 * @return void
	 */
	public function testTenancyAdministrationIsNotProjected(): void {
		$organisation = $this->organisation(uuid: 'org-uuid', name: 'Gemeente Utrecht');
		$organisation->setStorageQuota(1024);
		$organisation->setUsers(['alice', 'bob']);
		$organisation->setAuthorization(['read' => ['admin']]);

		$data = OrganisationObjectSourceProvider::project(organisation: $organisation);

		foreach (['storageQuota', 'bandwidthQuota', 'requestQuota', 'users', 'groups', 'authorization'] as $forbidden) {
			$this->assertArrayNotHasKey($forbidden, $data, $forbidden . ' must not be projected');
		}

	}//end testTenancyAdministrationIsNotProjected()

	/**
	 * An empty field is omitted rather than written as null, so a consumer can
	 * tell "this organisation has no OIN" from "this projection does not carry
	 * OINs at all".
	 *
	 * @return void
	 */
	public function testEmptyFieldsAreOmittedRatherThanNulled(): void {
		$data = OrganisationObjectSourceProvider::project(
			organisation: $this->organisation(uuid: 'org-uuid', name: 'Gemeente Utrecht')
		);

		$this->assertArrayNotHasKey('oin', $data);
		$this->assertArrayNotHasKey('rsin', $data);

	}//end testEmptyFieldsAreOmittedRatherThanNulled()

	/**
	 * A merged-away organisation is not offered. It no longer owns anything, and
	 * listing it invites a reference to a record that is not a usable target.
	 *
	 * @return void
	 */
	public function testAMergedAwayOrganisationIsNotListed(): void {
		$matches = OrganisationObjectSourceProvider::matching(
			organisations: [
				$this->organisation(uuid: 'live', name: 'Gemeente Utrecht'),
				$this->organisation(uuid: 'dead', name: 'Gemeente Utrecht', oin: null, mergedInto: 'live'),
			],
			search: ''
		);

		$this->assertSame(['live'], array_values(array_map(static fn ($o) => $o->getUuid(), $matches)));

	}//end testAMergedAwayOrganisationIsNotListed()

	/**
	 * Search matches the fields a person would actually type.
	 *
	 * @return void
	 */
	public function testSearchMatchesNameAndLegalIdentifiers(): void {
		$organisations = [
			$this->organisation(uuid: 'a', name: 'Gemeente Utrecht', oin: '00000001002220647000'),
			$this->organisation(uuid: 'b', name: 'Provincie Zuid-Holland', oin: '99999999999999999999'),
		];

		$byName = OrganisationObjectSourceProvider::matching(organisations: $organisations, search: 'utrecht');
		$this->assertSame(['a'], array_values(array_map(static fn ($o) => $o->getUuid(), $byName)));

		$byOin = OrganisationObjectSourceProvider::matching(organisations: $organisations, search: '9999999999');
		$this->assertSame(['b'], array_values(array_map(static fn ($o) => $o->getUuid(), $byOin)));

	}//end testSearchMatchesNameAndLegalIdentifiers()

	/**
	 * Search is case-insensitive, because a picker's user types what they read.
	 *
	 * @return void
	 */
	public function testSearchIsCaseInsensitive(): void {
		$matches = OrganisationObjectSourceProvider::matching(
			organisations: [$this->organisation(uuid: 'a', name: 'Gemeente Utrecht')],
			search: 'GEMEENTE'
		);

		$this->assertCount(1, $matches);

	}//end testSearchIsCaseInsensitive()

	/**
	 * A search matching nothing returns nothing, rather than falling back to the
	 * whole list — which would offer every tenant to anyone who typed a typo.
	 *
	 * @return void
	 */
	public function testANonMatchingSearchReturnsNothing(): void {
		$this->assertSame(
			[],
			OrganisationObjectSourceProvider::matching(
				organisations: [$this->organisation(uuid: 'a', name: 'Gemeente Utrecht')],
				search: 'nothing-matches-this'
			)
		);

	}//end testANonMatchingSearchReturnsNothing()

	/**
	 * The provider is always available: organisations are OpenRegister's own, so
	 * there is no backing app that can be uninstalled.
	 *
	 * @return void
	 */
	public function testTheProviderIsAlwaysEnabled(): void {
		$provider = new OrganisationObjectSourceProvider(
			organisationMapper: $this->createMock(\OCA\OpenRegister\Db\OrganisationMapper::class),
			userSession: $this->createMock(\OCP\IUserSession::class),
			groupManager: $this->createMock(\OCP\IGroupManager::class),
			logger: $this->createMock(\Psr\Log\LoggerInterface::class)
		);

		$this->assertTrue($provider->isEnabled());
		$this->assertSame('organisation-source', $provider->getId());

	}//end testTheProviderIsAlwaysEnabled()

	/**
	 * An anonymous caller sees nothing. Absent and denied must be
	 * indistinguishable, so the projection cannot enumerate the instance's
	 * tenants.
	 *
	 * @return void
	 */
	public function testAnAnonymousCallerSeesNothing(): void {
		$userSession = $this->createMock(\OCP\IUserSession::class);
		$userSession->method('getUser')->willReturn(null);

		$provider = new OrganisationObjectSourceProvider(
			organisationMapper: $this->createMock(\OCA\OpenRegister\Db\OrganisationMapper::class),
			userSession: $userSession,
			groupManager: $this->createMock(\OCP\IGroupManager::class),
			logger: $this->createMock(\Psr\Log\LoggerInterface::class)
		);

		$this->assertSame(
			[],
			$provider->findAll(
				register: new \OCA\OpenRegister\Db\Register(),
				schema: new \OCA\OpenRegister\Db\Schema()
			)
		);

	}//end testAnAnonymousCallerSeesNothing()
}//end class
