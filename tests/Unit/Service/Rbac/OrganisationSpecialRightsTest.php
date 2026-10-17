<?php

/**
 * Tests for the reader of an organisation's special rights.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Rbac\OrganisationSpecialRights;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @spec openspec/changes/special-right-to-manage-grants/specs/object-ownership/spec.md
 */
class OrganisationSpecialRightsTest extends TestCase {

	private const RIGHT = OrganisationSpecialRights::GRANT_MANAGE;

	private OrganisationMapper&MockObject $organisations;

	private RegisterMapper&MockObject $registers;

	private SchemaMapper&MockObject $schemas;

	private OrganisationSpecialRights $rights;

	protected function setUp(): void {
		parent::setUp();

		$this->organisations = $this->createMock(OrganisationMapper::class);
		$this->registers = $this->createMock(RegisterMapper::class);
		$this->schemas = $this->createMock(SchemaMapper::class);

		$register = new Register();
		$register->setSlug('dossiq');
		$register->setUuid('register-uuid');
		$this->registers->method('find')->willReturn($register);

		$schema = new Schema();
		$schema->setSlug('reviewBatch');
		$schema->setUuid('schema-uuid');
		$this->schemas->method('find')->willReturn($schema);

		$this->rights = new OrganisationSpecialRights(
			$this->organisations,
			$this->registers,
			$this->schemas,
			new NullLogger()
		);
	}//end setUp()

	/**
	 * A record of register 7, schema 12, in organisation `org-1`.
	 *
	 * @param string|null $organisation The organisation the record names.
	 *
	 * @return ObjectEntity The record.
	 */
	private function record(?string $organisation = 'org-1'): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('record-1');
		$object->setOwner('handler');
		$object->setRegister('7');
		$object->setSchema('12');
		$object->setOrganisation($organisation);

		return $object;
	}//end record()

	/**
	 * Store one list under the right in organisation `org-1`.
	 *
	 * @param mixed $entries What is stored under the right.
	 *
	 * @return Organisation The organisation.
	 */
	private function organisationWith(mixed $entries): Organisation {
		$organisation = new Organisation();
		$organisation->setUuid('org-1');
		$organisation->setAuthorization([self::RIGHT => $entries]);
		$this->organisations->method('findByUuid')->with('org-1')->willReturn($organisation);

		return $organisation;
	}//end organisationWith()

	public function testAGroupNamedAsAStringHoldsTheRightOnEveryRecordOfTheOrganisation(): void {
		$this->organisationWith(['grant-keepers']);

		$this->assertTrue($this->rights->holdsOn(self::RIGHT, ['staff', 'grant-keepers'], $this->record()));
		$this->assertFalse($this->rights->holdsOn(self::RIGHT, ['staff'], $this->record()));
	}//end testAGroupNamedAsAStringHoldsTheRightOnEveryRecordOfTheOrganisation()

	public function testACallerWithoutGroupsHoldsNothing(): void {
		$this->organisationWith(['grant-keepers']);

		$this->assertFalse($this->rights->holdsOn(self::RIGHT, [], $this->record()));
	}//end testACallerWithoutGroupsHoldsNothing()

	public function testAnotherRightDoesNotCount(): void {
		$organisation = new Organisation();
		$organisation->setAuthorization(['object_publish' => ['grant-keepers']]);
		$this->organisations->method('findByUuid')->willReturn($organisation);

		$this->assertFalse($this->rights->holdsOn(self::RIGHT, ['grant-keepers'], $this->record()));
	}//end testAnotherRightDoesNotCount()

	/**
	 * @dataProvider narrowedEntries
	 *
	 * @param array<string, mixed> $entry The stored entry.
	 * @param bool $expected Whether it admits the record of schema `reviewBatch` in register `dossiq`.
	 */
	public function testANarrowedEntryHoldsOnlyForTheRegisterAndSchemasItNames(array $entry, bool $expected): void {
		$this->organisationWith([$entry]);

		$this->assertSame($expected, $this->rights->holdsOn(self::RIGHT, ['svc'], $this->record()));
	}//end testANarrowedEntryHoldsOnlyForTheRegisterAndSchemasItNames()

	/**
	 * @return array<string, array{0: array<string, mixed>, 1: bool}>
	 */
	public static function narrowedEntries(): array {
		return [
			'schema by slug' => [['group' => 'svc', 'schemas' => ['reviewBatch']], true],
			'schema by uuid' => [['group' => 'svc', 'schemas' => ['schema-uuid']], true],
			'schema by id' => [['group' => 'svc', 'schemas' => [12]], true],
			'another schema' => [['group' => 'svc', 'schemas' => ['case']], false],
			'register and schema' => [['group' => 'svc', 'register' => 'dossiq', 'schemas' => ['reviewBatch']], true],
			'register only' => [['group' => 'svc', 'register' => 'dossiq'], true],
			'another register' => [['group' => 'svc', 'register' => 'pipelinq', 'schemas' => ['reviewBatch']], false],
			'an empty schema list admits nothing' => [['group' => 'svc', 'schemas' => []], false],
			'a schema list that is not a list admits nothing' => [['group' => 'svc', 'schemas' => 'reviewBatch'], false],
			'a register that is not a name admits nothing' => [['group' => 'svc', 'register' => null], false],
			'another group' => [['group' => 'others', 'schemas' => ['reviewBatch']], false],
			'no group' => [['schemas' => ['reviewBatch']], false],
		];
	}//end narrowedEntries()

	public function testAWildcardIsNotAGroup(): void {
		$this->organisationWith(['*', ['group' => '*']]);

		$this->assertFalse($this->rights->holdsOn(self::RIGHT, ['*', 'svc'], $this->record()));
	}//end testAWildcardIsNotAGroup()

	public function testAValueThatIsNotAListAdmitsNobody(): void {
		$this->organisationWith('svc');

		$this->assertFalse($this->rights->holdsOn(self::RIGHT, ['svc'], $this->record()));
	}//end testAValueThatIsNotAListAdmitsNobody()

	public function testASchemaThatCannotBeLoadedAdmitsNobodyThroughANarrowedEntry(): void {
		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('find')->willThrowException(new DoesNotExistException('gone'));
		$rights = new OrganisationSpecialRights($this->organisations, $this->registers, $schemas, new NullLogger());
		$this->organisationWith([['group' => 'svc', 'schemas' => ['reviewBatch']]]);

		$this->assertFalse($rights->holdsOn(self::RIGHT, ['svc'], $this->record()));
	}//end testASchemaThatCannotBeLoadedAdmitsNobodyThroughANarrowedEntry()

	public function testARecordWithoutAnOrganisationIsReadAgainstTheDefaultOrganisation(): void {
		$this->organisations->method('getDefaultOrganisationFromConfig')->willReturn('org-1');
		$this->organisationWith(['svc']);

		$this->assertTrue($this->rights->holdsOn(self::RIGHT, ['svc'], $this->record(organisation: null)));
	}//end testARecordWithoutAnOrganisationIsReadAgainstTheDefaultOrganisation()

	public function testNoOrganisationAtAllAdmitsNobody(): void {
		$this->organisations->method('getDefaultOrganisationFromConfig')->willReturn(null);
		$this->organisations->expects($this->never())->method('findByUuid');

		$this->assertFalse($this->rights->holdsOn(self::RIGHT, ['svc'], $this->record(organisation: null)));
	}//end testNoOrganisationAtAllAdmitsNobody()

	public function testAnOrganisationThatCannotBeLoadedAdmitsNobody(): void {
		$this->organisations->method('findByUuid')->willThrowException(new DoesNotExistException('gone'));

		$this->assertFalse($this->rights->holdsOn(self::RIGHT, ['svc'], $this->record()));
	}//end testAnOrganisationThatCannotBeLoadedAdmitsNobody()

	public function testGiveStoresOneEntryPerGroupAndKeepsTheOthers(): void {
		$organisation = $this->organisationWith(['grant-keepers', ['group' => 'svc', 'schemas' => ['old']]]);
		$this->organisations->expects($this->once())->method('update')->with($organisation)->willReturnArgument(0);

		$changed = $this->rights->give('org-1', self::RIGHT, 'svc', 'dossiq', ['reviewBatch', 'documentReview']);

		$this->assertTrue($changed);
		$this->assertSame(
			['grant-keepers', ['group' => 'svc', 'register' => 'dossiq', 'schemas' => ['reviewBatch', 'documentReview']]],
			$organisation->getAuthorization()[self::RIGHT]
		);
		$this->assertTrue($this->rights->holdsOn(self::RIGHT, ['svc'], $this->record()));
	}//end testGiveStoresOneEntryPerGroupAndKeepsTheOthers()

	public function testGivingWhatIsAlreadyHeldWritesNothing(): void {
		$this->organisationWith([['group' => 'svc', 'register' => 'dossiq', 'schemas' => ['reviewBatch']]]);
		$this->organisations->expects($this->never())->method('update');

		$this->assertFalse($this->rights->give('org-1', self::RIGHT, 'svc', 'dossiq', ['reviewBatch']));
	}//end testGivingWhatIsAlreadyHeldWritesNothing()

	public function testGiveInEveryOrganisationWritesOnlyWhereTheEntryIsMissing(): void {
		$has = new Organisation();
		$has->setUuid('org-has');
		$has->setAuthorization([self::RIGHT => [['group' => 'svc', 'register' => 'dossiq', 'schemas' => ['reviewBatch']]]]);
		$lacks = new Organisation();
		$lacks->setUuid('org-lacks');
		$this->organisations->method('findAll')->willReturn([$has, $lacks]);
		$this->organisations->method('findByUuid')->willReturnCallback(
			static fn (string $uuid): Organisation => ['org-has' => $has, 'org-lacks' => $lacks][$uuid]
		);
		$this->organisations->expects($this->once())->method('update')->with($lacks)->willReturnArgument(0);

		$answer = $this->rights->giveInEveryOrganisation(self::RIGHT, 'svc', 'dossiq', ['reviewBatch']);

		$this->assertSame(['organisations' => 2, 'changed' => 1], $answer);
		$this->assertSame(
			[['group' => 'svc', 'register' => 'dossiq', 'schemas' => ['reviewBatch']]],
			$lacks->getAuthorization()[self::RIGHT]
		);
	}//end testGiveInEveryOrganisationWritesOnlyWhereTheEntryIsMissing()

	public function testANewOrganisationDeclaresTheRightWithNobodyHoldingIt(): void {
		$this->assertSame([], (new Organisation())->getAuthorization()[self::RIGHT]);
	}//end testANewOrganisationDeclaresTheRightWithNobodyHoldingIt()
}//end class
