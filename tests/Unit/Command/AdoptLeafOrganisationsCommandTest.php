<?php

/**
 * Unit tests for leaf-organisation adoption.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Command
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

namespace OCA\OpenRegister\Tests\Unit\Command;

use OCA\OpenRegister\Command\AdoptLeafOrganisationsCommand;
use PHPUnit\Framework\TestCase;

/**
 * Locks the rules that decide what an adoption keeps, merges and drops.
 */
class AdoptLeafOrganisationsCommandTest extends TestCase {

	/**
	 * Build a candidate organisation record.
	 *
	 * @param int         $id         The row id.
	 * @param string      $uuid       The uuid.
	 * @param string|null $oin        The OIN, if any.
	 * @param string|null $mergedInto The uuid this row was merged into, if any.
	 *
	 * @return array<string, mixed> The candidate.
	 */
	private function candidate(int $id, string $uuid, ?string $oin = null, ?string $mergedInto = null): array {
		return [
			'id' => $id,
			'uuid' => $uuid,
			'oin' => $oin,
			'rsin' => null,
			'kvk' => null,
			'mergedInto' => $mergedInto,
		];

	}//end candidate()

	/**
	 * The same OIN written with and without punctuation is the same body.
	 *
	 * @return void
	 */
	public function testPunctuationDoesNotHideAMatch(): void {
		$this->assertSame(
			AdoptLeafOrganisationsCommand::normaliseIdentifier(value: '0000 0001-0022.2064 7000'),
			AdoptLeafOrganisationsCommand::normaliseIdentifier(value: '00000001002220647000')
		);

	}//end testPunctuationDoesNotHideAMatch()

	/**
	 * A value that is not a scalar identifier compares as nothing, rather than
	 * as an empty string that would match every other empty one.
	 *
	 * @return void
	 */
	public function testANonScalarIdentifierIsNotComparable(): void {
		$this->assertSame('', AdoptLeafOrganisationsCommand::normaliseIdentifier(value: ['00000001']));
		$this->assertSame('', AdoptLeafOrganisationsCommand::normaliseIdentifier(value: null));

	}//end testANonScalarIdentifierIsNotComparable()

	/**
	 * A shared OIN identifies the same legal entity.
	 *
	 * @return void
	 */
	public function testASharedOinFindsTheMergeTarget(): void {
		$target = AdoptLeafOrganisationsCommand::findMergeTarget(
			row: ['oin' => '00000001002220647000'],
			existing: [$this->candidate(id: 7, uuid: 'existing', oin: '00000001002220647000')]
		);

		$this->assertSame('existing', $target['uuid']);

	}//end testASharedOinFindsTheMergeTarget()

	/**
	 * Two organisations sharing only a name are two organisations. Merging them
	 * would destroy data no later step could recover.
	 *
	 * @return void
	 */
	public function testASharedNameNeverMerges(): void {
		$this->assertNull(
			AdoptLeafOrganisationsCommand::findMergeTarget(
				row: ['name' => 'Gemeente Utrecht'],
				existing: [
					[
						'id' => 7,
						'uuid' => 'existing',
						'name' => 'Gemeente Utrecht',
						'oin' => null,
						'rsin' => null,
						'kvk' => null,
						'mergedInto' => null,
					],
				]
			)
		);

	}//end testASharedNameNeverMerges()

	/**
	 * An empty identifier on either side is not a match: otherwise every row
	 * carrying no OIN would merge into the first other row carrying none.
	 *
	 * @return void
	 */
	public function testAnEmptyIdentifierIsNotAMatch(): void {
		$this->assertNull(
			AdoptLeafOrganisationsCommand::findMergeTarget(
				row: ['oin' => ''],
				existing: [$this->candidate(id: 7, uuid: 'existing', oin: '')]
			)
		);

	}//end testAnEmptyIdentifierIsNotAMatch()

	/**
	 * Among several matches the lowest id is canonical, so a repeated run
	 * chooses the same survivor rather than whichever row came back first.
	 *
	 * @return void
	 */
	public function testTheLowestIdIsCanonical(): void {
		$target = AdoptLeafOrganisationsCommand::findMergeTarget(
			row: ['oin' => '123'],
			existing: [
				$this->candidate(id: 9, uuid: 'later', oin: '123'),
				$this->candidate(id: 4, uuid: 'earlier', oin: '123'),
			]
		);

		$this->assertSame('earlier', $target['uuid']);

	}//end testTheLowestIdIsCanonical()

	/**
	 * A candidate that was itself merged away loses to a live one, so the
	 * adoption points at a row that is still a usable tenant.
	 *
	 * @return void
	 */
	public function testALiveCandidateBeatsAMergedAwayOne(): void {
		$target = AdoptLeafOrganisationsCommand::findMergeTarget(
			row: ['oin' => '123'],
			existing: [
				$this->candidate(id: 2, uuid: 'merged-away', oin: '123', mergedInto: 'somewhere'),
				$this->candidate(id: 8, uuid: 'live', oin: '123'),
			]
		);

		$this->assertSame('live', $target['uuid']);

	}//end testALiveCandidateBeatsAMergedAwayOne()

	/**
	 * OIN is tried before RSIN, so a row carrying both matches on the stronger
	 * identifier.
	 *
	 * @return void
	 */
	public function testOinIsTriedBeforeRsin(): void {
		$target = AdoptLeafOrganisationsCommand::findMergeTarget(
			row: ['oin' => '111', 'rsin' => '222'],
			existing: [
				['id' => 3, 'uuid' => 'by-rsin', 'oin' => null, 'rsin' => '222', 'kvk' => null, 'mergedInto' => null],
				['id' => 9, 'uuid' => 'by-oin', 'oin' => '111', 'rsin' => null, 'kvk' => null, 'mergedInto' => null],
			]
		);

		$this->assertSame('by-oin', $target['uuid']);

	}//end testOinIsTriedBeforeRsin()

	/**
	 * The uuid is preserved: references to it are stored where no migration can
	 * reach them.
	 *
	 * @return void
	 */
	public function testTheUuidIsPreserved(): void {
		$organisation = AdoptLeafOrganisationsCommand::buildOrganisation(
			fields: ['uuid' => 'leaf-uuid-123', 'name' => 'Gemeente Utrecht']
		);

		$this->assertSame('leaf-uuid-123', $organisation->getUuid());
		$this->assertSame('Gemeente Utrecht', $organisation->getName());

	}//end testTheUuidIsPreserved()

	/**
	 * The derived slug comes from the uuid, not the name: two adopted rows can
	 * legitimately share a name and a name-derived slug would collide.
	 *
	 * @return void
	 */
	public function testTheSlugIsDerivedFromTheUuidNotTheName(): void {
		$first = AdoptLeafOrganisationsCommand::buildOrganisation(
			fields: ['uuid' => 'uuid-one', 'name' => 'Gemeente Utrecht']
		);
		$second = AdoptLeafOrganisationsCommand::buildOrganisation(
			fields: ['uuid' => 'uuid-two', 'name' => 'Gemeente Utrecht']
		);

		$this->assertNotSame($first->getSlug(), $second->getSlug());

	}//end testTheSlugIsDerivedFromTheUuidNotTheName()

	/**
	 * A merge target is recorded on the adopted row, so both uuids keep
	 * resolving.
	 *
	 * @return void
	 */
	public function testTheMergeIsRecordedOnTheAdoptedRow(): void {
		$organisation = AdoptLeafOrganisationsCommand::buildOrganisation(
			fields: ['uuid' => 'leaf-uuid', 'name' => 'Gemeente Utrecht'],
			mergeTarget: ['id' => 4, 'uuid' => 'survivor-uuid']
		);

		$this->assertSame('leaf-uuid', $organisation->getUuid());
		$this->assertSame('survivor-uuid', $organisation->getMergedInto());
		$this->assertNotNull($organisation->getMergedAt());

	}//end testTheMergeIsRecordedOnTheAdoptedRow()

	/**
	 * Properties the entity has no column for are named. OpenRegister discards
	 * an undeclared property and answers 200 with the object, so an adoption
	 * that loses fields is otherwise indistinguishable from one that did not.
	 *
	 * @return void
	 */
	public function testUndeclaredPropertiesAreNamed(): void {
		$this->assertSame(
			['contactpersonen', 'deelnames', 'xml'],
			AdoptLeafOrganisationsCommand::undeclaredProperties(
				row: [
					'uuid' => 'leaf',
					'@self' => ['id' => 1],
					'name' => 'Gemeente Utrecht',
					'oin' => '123',
					'xml' => '<x/>',
					'deelnames' => [],
					'contactpersonen' => [],
				]
			)
		);

	}//end testUndeclaredPropertiesAreNamed()

	/**
	 * The uuid is read from the `@self` metadata block, which is where the
	 * object reader puts it.
	 *
	 * @return void
	 */
	public function testTheUuidIsReadFromTheSelfBlock(): void {
		$fields = AdoptLeafOrganisationsCommand::toFields(
			row: ['@self' => ['uuid' => 'from-self'], 'name' => 'Gemeente Utrecht']
		);

		$this->assertSame('from-self', $fields['uuid']);

	}//end testTheUuidIsReadFromTheSelfBlock()

	/**
	 * A row with no identifier anywhere yields an empty uuid, which the command
	 * treats as a row it cannot key on rather than minting one.
	 *
	 * @return void
	 */
	public function testARowWithNoIdentifierYieldsNoUuid(): void {
		$this->assertSame('', AdoptLeafOrganisationsCommand::toFields(row: ['name' => 'Nameless'])['uuid']);

	}//end testARowWithNoIdentifierYieldsNoUuid()

	/**
	 * A non-scalar value is not forced into a string column: an array cast to
	 * string is the word "Array", which is worse than not carrying it.
	 *
	 * @return void
	 */
	public function testANonScalarValueIsNotWrittenToAStringColumn(): void {
		$organisation = AdoptLeafOrganisationsCommand::buildOrganisation(
			fields: ['uuid' => 'leaf', 'name' => ['nested' => 'value']]
		);

		$this->assertNull($organisation->getName());

	}//end testANonScalarValueIsNotWrittenToAStringColumn()
}//end class
