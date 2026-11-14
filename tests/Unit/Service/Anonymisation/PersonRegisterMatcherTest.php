<?php

/**
 * A PERSON detection matched against the persons and place registers
 * (REQ-DKP-003): a match raises the confidence, a street name marks the
 * candidate uncertain and keeps it, no register records not-configured, and
 * nothing of the matched record is kept.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/detection-knows-the-person/tasks.md#task-3.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Anonymisation;

use OCA\OpenRegister\Service\Anonymisation\PersonRegisterMatcher;
use OCA\OpenRegister\Service\Anonymisation\PersonRegisterRun;
use OCA\OpenRegister\Service\Anonymisation\PersonRegisterSource;
use OCA\OpenRegister\Service\Anonymisation\RegisterNameReader;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Contacts\IManager as ContactsManager;
use OCP\IAddressBook;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The persons register matcher.
 */
#[CoversClass(PersonRegisterMatcher::class)]
#[CoversClass(PersonRegisterRun::class)]
#[CoversClass(RegisterNameReader::class)]
#[UsesClass(PersonRegisterSource::class)]
class PersonRegisterMatcherTest extends TestCase {

	private const PERSONS = ['type' => 'schema', 'register' => 'brp', 'schema' => 'personen', 'nameProperty' => 'naam', 'purpose' => 'Woo-verzoek anonimiseren'];

	private const PLACES = ['type' => 'schema', 'register' => 'bag', 'schema' => 'straten', 'nameProperty' => 'naam'];

	private ObjectService&MockObject $objects;

	private ContactsManager&MockObject $contacts;

	private LoggerInterface&MockObject $logger;

	/**
	 * The objects each schema returns.
	 *
	 * @var array<string, list<array<string, mixed>>>
	 */
	private array $bySchema = [];

	protected function setUp(): void {
		$this->bySchema = [
			'personen' => [
				['id' => 'a1b2c3d4-0000-4000-8000-000000000001', 'naam' => 'Fatima El-Amrani', 'bsn' => '999990019'],
				['id' => 'a1b2c3d4-0000-4000-8000-000000000002', 'naam' => 'Jan Jansen'],
			],
			'straten' => [['id' => 'b1', 'naam' => 'Willem de Zwijgerlaan'], ['id' => 'b2', 'naam' => 'Dorpsstraat']],
		];
		$this->objects = $this->createMock(ObjectService::class);
		$this->objects->method('findAll')->willReturnCallback(
			fn (array $config): array => $this->bySchema[$config['filters']['schema']] ?? []
		);
		$this->contacts = $this->createMock(ContactsManager::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * The matcher over the test's object service and contacts.
	 *
	 * @return PersonRegisterMatcher
	 */
	private function matcher(): PersonRegisterMatcher {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with(ObjectService::class)->willReturn($this->objects);

		return new PersonRegisterMatcher(reader: new RegisterNameReader(container: $container, contacts: $this->contacts), logger: $this->logger);
	}//end matcher()

	/**
	 * A detected name in the persons register, written differently, is raised by the boost.
	 *
	 * @return void
	 */
	public function testAMatchRaisesConfidence(): void {
		$run = $this->matcher()->run(personRegister: self::PERSONS, boost: 0.2, placeRegister: null);

		$detected = $run->apply(detected: ['type' => 'PERSON', 'value' => 'fatima el amrani', 'confidence' => 0.55]);

		$this->assertSame(0.75, $detected['confidence']);
		$this->assertSame(['source' => 'schema:brp/personen'], $detected['personMatch']);
		$this->assertArrayNotHasKey('uncertain', $detected);

		$capped = $run->apply(detected: ['type' => 'PERSON', 'value' => 'JAN JANSEN', 'confidence' => 0.9]);
		$this->assertSame(0.99, $capped['confidence']);

		$unknown = $run->apply(detected: ['type' => 'PERSON', 'value' => 'Piet Puk', 'confidence' => 0.55]);
		$this->assertSame(0.55, $unknown['confidence']);
		$this->assertArrayNotHasKey('personMatch', $unknown);

		$this->assertSame(['personRegister' => 'schema:brp/personen', 'purpose' => 'Woo-verzoek anonimiseren', 'lookups' => 3], $run->summary());
	}//end testAMatchRaisesConfidence()

	/**
	 * A candidate inside a street name, not in the persons register, is marked uncertain and kept.
	 *
	 * @return void
	 */
	public function testAStreetMatchMarksUncertainAndKeepsTheCandidate(): void {
		$run = $this->matcher()->run(personRegister: self::PERSONS, boost: 0.2, placeRegister: self::PLACES);

		$detected = $run->apply(detected: ['type' => 'PERSON', 'value' => 'Willem de Zwijger', 'confidence' => 0.8, 'position_start' => 10]);

		$this->assertSame(
			['type' => 'PERSON', 'value' => 'Willem de Zwijger', 'confidence' => 0.8, 'position_start' => 10, 'uncertain' => true],
			$detected
		);

		$resident = $run->apply(detected: ['type' => 'PERSON', 'value' => 'Fatima El-Amrani', 'confidence' => 0.6]);
		$this->assertArrayNotHasKey('uncertain', $resident);

		$elsewhere = $run->apply(detected: ['type' => 'PERSON', 'value' => 'Piet Puk', 'confidence' => 0.6]);
		$this->assertArrayNotHasKey('uncertain', $elsewhere);

		$midWord = $run->apply(detected: ['type' => 'PERSON', 'value' => 'Zwijgerlaan Willem', 'confidence' => 0.6]);
		$this->assertArrayNotHasKey('uncertain', $midWord);

		$location = ['type' => 'LOCATION', 'value' => 'Dorpsstraat', 'confidence' => 0.6];
		$this->assertSame($location, $run->apply(detected: $location));
	}//end testAStreetMatchMarksUncertainAndKeepsTheCandidate()

	/**
	 * Without a persons register the confidences are unchanged and the run says not-configured.
	 *
	 * @return void
	 */
	public function testWithoutARegisterTheRunSaysNotConfigured(): void {
		$this->objects->expects($this->never())->method('findAll');
		$run = $this->matcher()->run(personRegister: null, boost: 0.2, placeRegister: null);

		$detected = ['type' => 'PERSON', 'value' => 'Fatima El-Amrani', 'confidence' => 0.55];
		$this->assertSame($detected, $run->apply(detected: $detected));
		$this->assertSame(['personRegister' => 'not-configured', 'purpose' => null, 'lookups' => 0], $run->summary());
	}//end testWithoutARegisterTheRunSaysNotConfigured()

	/**
	 * A match keeps only the register's label: no id or value of the matched record.
	 *
	 * @return void
	 */
	public function testTheRelationHoldsNoRecordIdentifier(): void {
		$run = $this->matcher()->run(personRegister: self::PERSONS, boost: 0.2, placeRegister: null);

		$detected = $run->apply(detected: ['type' => 'PERSON', 'value' => 'Fatima El-Amrani', 'confidence' => 0.55]);

		$encoded = json_encode($detected);
		$this->assertStringNotContainsString('a1b2c3d4', $encoded);
		$this->assertStringNotContainsString('999990019', $encoded);
		$this->assertSame(['type', 'value', 'confidence', 'personMatch'], array_keys($detected));
		$this->assertStringNotContainsString('a1b2c3d4', json_encode($run->summary()));
	}//end testTheRelationHoldsNoRecordIdentifier()

	/**
	 * An address book is read by key, as full names; a missing one gives no matches and is logged.
	 *
	 * @return void
	 */
	public function testAnAddressBookIsReadByItsFullNames(): void {
		$book = $this->createMock(IAddressBook::class);
		$book->method('getKey')->willReturn('7');
		$book->method('getUri')->willReturn('inwoners');
		$book->expects($this->once())->method('search')->with('', ['FN'], ['limit' => RegisterNameReader::LIMIT])
			->willReturn([['FN' => 'Ana Müller', 'UID' => 'u-1'], ['FN' => '']]);
		$this->contacts->method('getUserAddressBooks')->willReturn([$book]);

		$run = $this->matcher()->run(
			personRegister: ['type' => 'contacts', 'addressBook' => 'inwoners', 'purpose' => 'anonimiseren'],
			boost: 0.1,
			placeRegister: null
		);
		$this->assertSame(0.6, $run->apply(detected: ['type' => 'PERSON', 'value' => 'ana muller', 'confidence' => 0.5])['confidence']);

		$this->logger->expects($this->once())->method('warning');
		$missing = $this->matcher()->run(
			personRegister: ['type' => 'contacts', 'addressBook' => 'gone', 'purpose' => 'anonimiseren'],
			boost: 0.1,
			placeRegister: null
		);
		$this->assertSame(0.5, $missing->apply(detected: ['type' => 'PERSON', 'value' => 'ana muller', 'confidence' => 0.5])['confidence']);
		$this->assertSame('contacts:gone', $missing->summary()['personRegister']);
	}//end testAnAddressBookIsReadByItsFullNames()

	/**
	 * Case, accents, hyphens and spaces do not change a name.
	 *
	 * @return void
	 */
	public function testTheNormalisedName(): void {
		$this->assertSame('fatima el amrani', PersonRegisterRun::normalise(name: '  Fátima  El-Amrani '));
		$this->assertSame('', PersonRegisterRun::normalise(name: ' - '));
		$this->assertSame('not-configured', PersonRegisterRun::notConfigured()->summary()['personRegister']);
	}//end testTheNormalisedName()
}//end class
