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
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

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

	/**
	 * Build a command over mocked collaborators.
	 *
	 * @param array<int, array<string, mixed>> $rows     The leaf rows the reader returns.
	 * @param array<int, Organisation>         $existing Organisations already on the instance.
	 *
	 * @return array{tester: CommandTester, mapper: OrganisationMapper&MockObject} The tester and the mapper.
	 */
	private function commandOver(array $rows, array $existing = []): array {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('searchObjectsBySlug')->willReturn($rows);

		$mapper = $this->createMock(OrganisationMapper::class);
		$mapper->method('findAll')->willReturn($existing);
		$mapper->method('insert')->willReturnArgument(0);

		$command = new AdoptLeafOrganisationsCommand(
			organisationMapper: $mapper,
			objectService: $objectService,
			logger: $this->createMock(LoggerInterface::class)
		);

		return ['tester' => new CommandTester($command), 'mapper' => $mapper];

	}//end commandOver()

	/**
	 * Build an existing organisation.
	 *
	 * @param string      $uuid The uuid.
	 * @param string|null $oin  The OIN, if any.
	 *
	 * @return Organisation The organisation.
	 */
	private function existing(string $uuid, ?string $oin = null): Organisation {
		$organisation = new Organisation();
		$organisation->setId(4);
		$organisation->setUuid($uuid);
		$organisation->setOin($oin);

		return $organisation;

	}//end existing()

	/**
	 * Without --register there is nothing to read from, and the command says so
	 * rather than reading whatever it can find.
	 *
	 * @return void
	 */
	public function testTheRegisterOptionIsRequired(): void {
		$run = $this->commandOver(rows: []);
		$this->assertSame(Command::FAILURE, $run['tester']->execute([]));
		$this->assertStringContainsString('--register is required', $run['tester']->getDisplay());

	}//end testTheRegisterOptionIsRequired()

	/**
	 * The default is a dry run, because the alternative default is a command
	 * that writes to every organisation on the instance the first time someone
	 * types its name to see what it does.
	 *
	 * @return void
	 */
	public function testItIsADryRunByDefault(): void {
		$run = $this->commandOver(rows: [['@self' => ['uuid' => 'leaf-1'], 'name' => 'Gemeente Utrecht']]);
		$run['mapper']->expects($this->never())->method('insert');

		$this->assertSame(Command::SUCCESS, $run['tester']->execute(['--register' => 'publication']));
		$display = $run['tester']->getDisplay();
		$this->assertStringContainsString('DRY-RUN', $display);
		$this->assertStringContainsString('WOULD ADOPT', $display);
		$this->assertStringContainsString('nothing written', $display);

	}//end testItIsADryRunByDefault()

	/**
	 * With --apply the row is inserted, keeping its uuid.
	 *
	 * @return void
	 */
	public function testApplyInsertsTheRowKeepingItsUuid(): void {
		$run = $this->commandOver(rows: [['@self' => ['uuid' => 'leaf-1'], 'name' => 'Gemeente Utrecht']]);
		$run['mapper']->expects($this->once())
			->method('insert')
			->with(
				$this->callback(
					static function ($organisation) {
						return ((string) $organisation->getUuid() === 'leaf-1');
					}
				)
			)
			->willReturnArgument(0);

		$this->assertSame(
			Command::SUCCESS,
			$run['tester']->execute(['--register' => 'publication', '--apply' => true])
		);
		$this->assertStringContainsString('Adopted=1', $run['tester']->getDisplay());

	}//end testApplyInsertsTheRowKeepingItsUuid()

	/**
	 * A row whose uuid is already an organisation is skipped, which is what
	 * makes a second run of the command a no-op.
	 *
	 * @return void
	 */
	public function testAnAlreadyAdoptedRowIsSkipped(): void {
		$run = $this->commandOver(
			rows: [['@self' => ['uuid' => 'leaf-1'], 'name' => 'Gemeente Utrecht']],
			existing: [$this->existing(uuid: 'leaf-1')]
		);
		$run['mapper']->expects($this->never())->method('insert');

		$run['tester']->execute(['--register' => 'publication', '--apply' => true]);
		$display = $run['tester']->getDisplay();
		$this->assertStringContainsString('already adopted', $display);
		$this->assertStringContainsString('skipped=1', $display);

	}//end testAnAlreadyAdoptedRowIsSkipped()

	/**
	 * A row with no uuid has no idempotency key, so adopting it would create a
	 * duplicate on every run. It is skipped and reported.
	 *
	 * @return void
	 */
	public function testARowWithNoUuidIsSkipped(): void {
		$run = $this->commandOver(rows: [['name' => 'Nameless']]);
		$run['mapper']->expects($this->never())->method('insert');

		$run['tester']->execute(['--register' => 'publication', '--apply' => true]);
		$this->assertStringContainsString('no uuid', $run['tester']->getDisplay());

	}//end testARowWithNoUuidIsSkipped()

	/**
	 * A matching legal identifier is reported as a merge and recorded on the
	 * adopted row, so both uuids keep resolving.
	 *
	 * @return void
	 */
	public function testAMatchingIdentifierRecordsAMerge(): void {
		$run = $this->commandOver(
			rows: [['@self' => ['uuid' => 'leaf-1'], 'name' => 'Gemeente Utrecht', 'oin' => '0000-0001']],
			existing: [$this->existing(uuid: 'survivor', oin: '00000001')]
		);

		$run['tester']->execute(['--register' => 'publication', '--apply' => true]);
		$display = $run['tester']->getDisplay();
		$this->assertStringContainsString('merges into survivor', $display);
		$this->assertStringContainsString('merged=1', $display);

	}//end testAMatchingIdentifierRecordsAMerge()

	/**
	 * Properties Organisation has no column for are named before the write.
	 * This is the whole reason the report exists: OpenRegister discards an
	 * undeclared property and answers 200, so a lossy adoption is otherwise
	 * indistinguishable from a clean one.
	 *
	 * @return void
	 */
	public function testUndeclaredPropertiesAreReportedBeforeTheWrite(): void {
		$run = $this->commandOver(
			rows: [
				[
					'@self' => ['uuid' => 'leaf-1'],
					'name' => 'Gemeente Utrecht',
					'xml' => '<x/>',
					'deelnames' => [],
				],
			]
		);

		$run['tester']->execute(['--register' => 'publication']);
		$display = $run['tester']->getDisplay();
		$this->assertStringContainsString('2 properties have no column', $display);
		$this->assertStringContainsString('deelnames, xml', $display);

	}//end testUndeclaredPropertiesAreReportedBeforeTheWrite()

	/**
	 * One undeclared property reads as one, not as "1 property have".
	 *
	 * @return void
	 */
	public function testTheSingularReportReadsAsASingular(): void {
		$run = $this->commandOver(
			rows: [['@self' => ['uuid' => 'leaf-1'], 'name' => 'Gemeente Utrecht', 'xml' => '<x/>']]
		);

		$run['tester']->execute(['--register' => 'publication']);
		$this->assertStringContainsString('1 property has no column', $run['tester']->getDisplay());

	}//end testTheSingularReportReadsAsASingular()

	/**
	 * A row that fails to insert is counted as failed and the command exits
	 * non-zero, so a partial adoption does not report success.
	 *
	 * @return void
	 */
	public function testAFailedInsertMakesTheCommandFail(): void {
		$run = $this->commandOver(rows: [['@self' => ['uuid' => 'leaf-1'], 'name' => 'Gemeente Utrecht']]);
		$run['mapper']->method('insert')->willThrowException(new \RuntimeException('constraint'));

		$this->assertSame(
			Command::FAILURE,
			$run['tester']->execute(['--register' => 'publication', '--apply' => true])
		);
		$display = $run['tester']->getDisplay();
		$this->assertStringContainsString('FAILED: constraint', $display);
		$this->assertStringContainsString('failed=1', $display);

	}//end testAFailedInsertMakesTheCommandFail()

	/**
	 * A second row sharing the first's legal identifier merges into it within
	 * the same run, because the candidate set grows as rows are adopted.
	 *
	 * @return void
	 */
	public function testARunMergesADuplicateItAdoptedItself(): void {
		$run = $this->commandOver(
			rows: [
				['@self' => ['uuid' => 'leaf-1'], 'name' => 'Gemeente Utrecht', 'oin' => '111'],
				['@self' => ['uuid' => 'leaf-2'], 'name' => 'Gemeente Utrecht', 'oin' => '111'],
			]
		);

		$run['tester']->execute(['--register' => 'publication', '--apply' => true]);
		$display = $run['tester']->getDisplay();
		$this->assertStringContainsString('merges into leaf-1', $display);
		$this->assertStringContainsString('Adopted=2 (of which merged=1)', $display);

	}//end testARunMergesADuplicateItAdoptedItself()
}//end class
