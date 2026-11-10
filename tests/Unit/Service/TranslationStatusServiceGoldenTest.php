<?php

/**
 * Golden tests for TranslationStatusService: status updates, completeness and search.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/specs/register-i18n/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\Translation\LanguageCoverageCheck;
use OCA\OpenRegister\Db\TranslationEdit;
use OCA\OpenRegister\Db\TranslationFilter;
use OCA\OpenRegister\Db\TranslationSlot;
use OCA\OpenRegister\Db\Translation;
use OCA\OpenRegister\Db\TranslationMapperInterface;
use OCA\OpenRegister\Service\LanguageGapScan;
use OCA\OpenRegister\Service\Object\TranslationHandler;
use OCA\OpenRegister\Service\TranslationSearch;
use OCA\OpenRegister\Service\TranslationStatusService;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Pins the current observable behaviour of TranslationStatusService.
 */
class TranslationStatusServiceGoldenTest extends TestCase {

	/** @var TranslationMapperInterface&MockObject */
	private TranslationMapperInterface $mapper;

	/** @var TranslationHandler&MockObject */
	private TranslationHandler $handler;

	/** @var IUserSession&MockObject */
	private IUserSession $session;

	private TranslationStatusService $service;

	protected function setUp(): void {
		$this->mapper = $this->createMock(TranslationMapperInterface::class);
		$this->handler = $this->createMock(TranslationHandler::class);
		$this->session = $this->createMock(IUserSession::class);
		$this->service = new TranslationStatusService($this->mapper, $this->handler, $this->session);
	}//end setUp()

	/**
	 * Build a translation row.
	 *
	 * @param string      $uuid   Object uuid.
	 * @param string      $prop   Property.
	 * @param string      $lang   Language.
	 * @param string|null $value  Value.
	 * @param string|null $source Source language.
	 *
	 * @return Translation
	 */
	private function row(string $uuid, string $prop, string $lang, ?string $value, ?string $source = null): Translation {
		$row = new Translation();
		$row->setObjectUuid($uuid);
		$row->setProperty($prop);
		$row->setLanguage($lang);
		$row->setValue($value);
		$row->setSourceLanguage($source);
		return $row;
	}//end row()

	public function testSetStatusRejectsUnknownStatusBeforeLookup(): void {
		$this->mapper->expects($this->never())->method('findOne');
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage(
			'Invalid translation status "bogus"; expected one of: ' . implode(', ', Translation::ALL_STATUSES)
		);
		$this->service->setStatus(new TranslationSlot('u1', 'title', 'en'), 'bogus');
	}//end testSetStatusRejectsUnknownStatusBeforeLookup()

	public function testSetStatusRejectsMissingSlot(): void {
		$this->mapper->method('findOne')->willReturn(null);
		$this->mapper->expects($this->never())->method('upsert');
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage(
			'No translation slot for object=u1 property=title language=en — set a value before promoting status.'
		);
		$this->service->setStatus(new TranslationSlot('u1', 'title', 'en'), Translation::STATUS_APPROVED);
	}//end testSetStatusRejectsMissingSlot()

	public function testSetStatusUpsertsExistingValueWithSessionTranslator(): void {
		$existing = $this->row('u1', 'title', 'en', 'Hello');
		$saved = $this->row('u1', 'title', 'en', 'Hello');
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->session->method('getUser')->willReturn($user);
		$this->mapper->expects($this->once())->method('findOne')->with($this->equalTo(new TranslationSlot('u1', 'title', 'en')))->willReturn($existing);
		$this->mapper->expects($this->once())->method('upsert')
			->with($this->equalTo(new TranslationSlot('u1', 'title', 'en')), $this->equalTo(new TranslationEdit('Hello', Translation::STATUS_APPROVED, 'alice')))
			->willReturn($saved);

		$this->assertSame($saved, $this->service->setStatus(new TranslationSlot('u1', 'title', 'en'), Translation::STATUS_APPROVED));
	}//end testSetStatusUpsertsExistingValueWithSessionTranslator()

	public function testSetStatusWithoutUserPassesNullTranslator(): void {
		$existing = $this->row('u1', 'title', 'en', 'Hello');
		$this->session->method('getUser')->willReturn(null);
		$this->mapper->method('findOne')->willReturn($existing);
		$this->mapper->expects($this->once())->method('upsert')
			->with($this->equalTo(new TranslationSlot('u1', 'title', 'en')), $this->equalTo(new TranslationEdit('Hello', Translation::STATUS_DRAFT, null)))
			->willReturn($existing);

		$this->service->setStatus(new TranslationSlot('u1', 'title', 'en'), Translation::STATUS_DRAFT);
	}//end testSetStatusWithoutUserPassesNullTranslator()

	public function testCompletenessEmptyWhenNoTranslatableProperties(): void {
		$this->handler->method('getTranslatableProperties')->willReturn([]);
		$this->mapper->expects($this->never())->method('getCompletenessByObject');
		$this->assertSame([], $this->service->completenessForObject('u1', new Schema()));
	}//end testCompletenessEmptyWhenNoTranslatableProperties()

	public function testCompletenessRatiosCapAndRound(): void {
		$this->handler->method('getTranslatableProperties')->willReturn(['a', 'b', 'c']);
		$this->mapper->method('getCompletenessByObject')->with('u1')->willReturn(['nl' => 3, 'en' => 1, 'de' => 5]);

		$this->assertSame(
			[
				'nl' => ['translated' => 3, 'total' => 3, 'ratio' => 1.0],
				'en' => ['translated' => 1, 'total' => 3, 'ratio' => 0.33],
				'de' => ['translated' => 5, 'total' => 3, 'ratio' => 1.0],
			],
			$this->service->completenessForObject('u1', new Schema())
		);
	}//end testCompletenessRatiosCapAndRound()

	public function testSearchForwardsEveryFilterAndSerialises(): void {
		$rows = [$this->row('u1', 'title', 'en', 'Hi', 'nl')];
		$this->mapper->expects($this->once())->method('search')
			->with($this->equalTo(new TranslationFilter('en', 'draft', 'u1', 'nl')), 'q', 5, true)
			->willReturn($rows);

		$this->assertSame(
			[$rows[0]->jsonSerialize()],
			$this->service->search(new TranslationSearch(new TranslationFilter('en', 'draft', 'u1', 'nl'), 'q', 5, true))
		);
	}//end testSearchForwardsEveryFilterAndSerialises()

	public function testSearchDefaults(): void {
		$this->mapper->expects($this->once())->method('search')
			->with($this->equalTo(new TranslationFilter()), null, 100, false)
			->willReturn([]);
		$this->assertSame([], $this->service->search(new TranslationSearch()));
	}//end testSearchDefaults()

	public function testSearchWithSourceValuesResolvesAndCaches(): void {
		$a = $this->row('u1', 'title', 'en', 'Hi', 'nl');
		$b = $this->row('u1', 'title', 'de', 'Hallo', 'nl');
		$c = $this->row('u2', 'body', 'en', 'Text', '');
		$d = $this->row('u3', 'body', 'en', 'Gone', 'fr');
		$this->mapper->expects($this->once())->method('search')
			->with($this->equalTo(new TranslationFilter('en', null, null, 'nl')), 'q', 7, true)
			->willReturn([$a, $b, $c, $d]);
		$this->mapper->expects($this->exactly(2))->method('findOne')
			->willReturnCallback(
				function (TranslationSlot $slot) {
					[$uuid, $prop, $lang] = [$slot->objectUuid, $slot->property, $slot->language];
					if ($uuid === 'u1') {
						$this->assertSame(['title', 'nl'], [$prop, $lang]);
						return $this->row('u1', 'title', 'nl', 'Hoi');
					}

					$this->assertSame(['u3', 'body', 'fr'], [$uuid, $prop, $lang]);
					return null;
				}
			);

		$result = $this->service->searchWithSourceValues(new TranslationSearch(new TranslationFilter('en', null, null, 'nl'), 'q', 7, true));

		$expected = [
			$a->jsonSerialize() + ['sourceValue' => 'Hoi'],
			$b->jsonSerialize() + ['sourceValue' => 'Hoi'],
			$c->jsonSerialize() + ['sourceValue' => null],
			$d->jsonSerialize() + ['sourceValue' => null],
		];
		$this->assertSame($expected, $result);
	}//end testSearchWithSourceValuesResolvesAndCaches()

	public function testSearchWithSourceValuesRetriesNullLookups(): void {
		$a = $this->row('u3', 'body', 'en', 'One', 'fr');
		$b = $this->row('u3', 'body', 'de', 'Two', 'fr');
		$this->mapper->method('search')->willReturn([$a, $b]);
		// A null source value is not cached (isset), so the lookup repeats.
		$this->mapper->expects($this->exactly(2))->method('findOne')->willReturn(null);

		$result = $this->service->searchWithSourceValues(new TranslationSearch());
		$this->assertNull($result[0]['sourceValue']);
		$this->assertNull($result[1]['sourceValue']);
	}//end testSearchWithSourceValuesRetriesNullLookups()

	public function testFindObjectsMissingLanguageUsesTranslatableProperties(): void {
		$schema = new Schema();
		$this->handler->method('getTranslatableProperties')->with($schema)->willReturn(['title']);
		$this->mapper->expects($this->once())->method('findObjectsMissingLanguage')
			->with($this->equalTo(new LanguageCoverageCheck('en', ['title'], ['u1', 'u2'])))
			->willReturn(['u2']);
		$this->assertSame(['u2'], $this->service->findObjectsMissingLanguage($schema, new LanguageGapScan('en', ['u1', 'u2'])));
	}//end testFindObjectsMissingLanguageUsesTranslatableProperties()
}//end class
