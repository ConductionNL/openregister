<?php

declare(strict_types=1);

/**
 * Draft lifecycle decisions golden (characterisation) test
 *
 * Pins, on the code as it stood before the D06 complexity refactor, the
 * allowed AND refused outcomes of the draft decisions: which status a write
 * resolves to (and which moves are refused), whether a write is a draft
 * write, a schema's draft lifetime, which drafts a list shows (mode and SQL)
 * and which draft an owner gets back.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/openregister
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Service\Object\OwnDraftSearch;
use OCA\OpenRegister\Service\Object\DraftAddress;
use OCA\OpenRegister\Service\Object\DraftListing;
use DateTime;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\DraftAlreadyLeftException;
use OCA\OpenRegister\Exception\ValidationException;
use OCA\OpenRegister\Service\Object\DraftFinder;
use OCA\OpenRegister\Service\Object\DraftListLens;
use OCA\OpenRegister\Service\Object\DraftOwner;
use OCA\OpenRegister\Service\Object\DraftStatusPolicy;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;

/**
 * Golden outcomes of DraftStatusPolicy, DraftListLens and DraftFinder.
 */
class DraftDecisionsGoldenTest extends TestCase {
	/**
	 * Reset the static promotion allowances between tests.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		DraftStatusPolicy::resetPromotions();
		parent::tearDown();
	}//end tearDown()

	/**
	 * An object with a status, optionally saved (with an id) and owned.
	 *
	 * @param string|null $status The status
	 * @param bool        $saved  Whether it has an id
	 * @param string      $uuid   The uuid
	 * @param string|null $owner  The owner
	 *
	 * @return ObjectEntity
	 */
	private static function entity(?string $status, bool $saved, string $uuid = 'u-1', ?string $owner = null): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setStatus($status);
		$entity->setOwner($owner);
		if ($saved === true) {
			$entity->setId(5);
		}

		return $entity;
	}//end entity()

	/**
	 * Resolve a status, answering the exception class and message on a refusal.
	 *
	 * @param ObjectEntity $entity   The object
	 * @param array        $selfData The @self block
	 *
	 * @return string|null
	 */
	private static function resolve(ObjectEntity $entity, array $selfData): ?string {
		try {
			return (new DraftStatusPolicy())->resolveStatus(entity: $entity, selfData: $selfData);
		} catch (ValidationException | DraftAlreadyLeftException $e) {
			return get_class($e) . ': ' . $e->getMessage();
		}
	}//end resolve()

	/**
	 * The status a write resolves to, every allowed and refused move.
	 *
	 * @return void
	 */
	public function testResolveStatusTable(): void {
		$draft = ObjectEntity::STATUS_DRAFT;
		$active = ObjectEntity::STATUS_ACTIVE;
		$back = ValidationException::class . ': A saved object cannot go back to draft.';
		$gate = ValidationException::class . ': A draft leaves draft through the form submit or its owner\'s activate action, which check every required field first.';

		$this->assertSame(null, self::resolve(self::entity(null, false), []));
		$this->assertSame($active, self::resolve(self::entity($active, true), ['status' => null]));
		$this->assertSame($active, self::resolve(self::entity($active, true), ['status' => '']));
		$this->assertSame($active, self::resolve(self::entity($active, true), ['status' => $active]));
		$this->assertSame($draft, self::resolve(self::entity(null, false), ['status' => $draft]));
		$this->assertSame($back, self::resolve(self::entity($active, true), ['status' => $draft]));
		$this->assertSame($gate, self::resolve(self::entity($draft, true), ['status' => $active]));
		$this->assertSame(
			ValidationException::class . ': Status "0" is not one an object can be given here.',
			self::resolve(self::entity($active, true), ['status' => 0])
		);
		$this->assertSame(
			ValidationException::class . ': Status "active" is not one an object can be given here.',
			self::resolve(self::entity(null, false), ['status' => $active])
		);

		// With the allowance: promoted once, and the allowance is consumed.
		DraftStatusPolicy::allowPromotion(uuid: 'u-1');
		$this->assertSame($active, self::resolve(self::entity($draft, true), ['status' => $active]));
		$this->assertTrue(DraftStatusPolicy::promotionWasUsed(uuid: 'u-1'));
		$this->assertTrue(DraftStatusPolicy::consumeLeaving(uuid: 'u-1'));
		$this->assertFalse(DraftStatusPolicy::consumeLeaving(uuid: 'u-1'));

		// An open allowance on a saved non-draft is a parallel promotion: refused.
		DraftStatusPolicy::allowPromotion(uuid: 'u-1');
		$this->assertSame(
			DraftAlreadyLeftException::class . ': This draft has already left draft.',
			self::resolve(self::entity($active, true), [])
		);
		$this->assertSame($draft, self::resolve(self::entity($draft, true), []), 'A draft with an open allowance saves as itself.');
		$this->assertSame(null, self::resolve(self::entity(null, false), []), 'An unsaved object is never a parallel promotion.');
	}//end testResolveStatusTable()

	/**
	 * Draft writes and draft lifetimes.
	 *
	 * @return void
	 */
	public function testDraftWriteAndLifetimeTables(): void {
		$policy = new DraftStatusPolicy();
		$draft = self::entity(ObjectEntity::STATUS_DRAFT, true);
		$active = self::entity(ObjectEntity::STATUS_ACTIVE, true);

		$this->assertTrue($policy->isDraftWrite(object: ['@self' => ['status' => 'draft']], existing: null));
		$this->assertFalse($policy->isDraftWrite(object: ['@self' => ['status' => 'active']], existing: $draft));
		$this->assertTrue($policy->isDraftWrite(object: ['@self' => ['status' => 5]], existing: $draft));
		$this->assertFalse($policy->isDraftWrite(object: ['@self' => 'draft'], existing: $active));
		$this->assertFalse($policy->isDraftWrite(object: [], existing: null));
		$this->assertTrue($policy->isDraftWrite(object: [], existing: $draft));

		$lifetimes = [
			[null, 30],
			[[], 30],
			[['draftLifetimeDays' => null], 30],
			[['draftLifetimeDays' => 7], 7],
			[['draftLifetimeDays' => 0], null],
			[['draftLifetimeDays' => -3], null],
			[['draftLifetimeDays' => '7'], 7],
			[['draftLifetimeDays' => false], null],
		];
		foreach ($lifetimes as $i => [$configuration, $expected]) {
			$schema = new Schema();
			$schema->setConfiguration($configuration);
			$this->assertSame($expected, $policy->draftLifetimeDays(schema: $schema), "lifetime case $i");
		}
	}//end testDraftWriteAndLifetimeTables()

	/**
	 * Which drafts a list shows: mode and SQL.
	 *
	 * @return void
	 */
	public function testListLensTables(): void {
		$lens = new DraftListLens();
		$unlisted = new Schema();
		$unlisted->setConfiguration(['draftsListed' => false]);
		$listed = new Schema();
		$listed->setConfiguration(['draftsListed' => true]);

		$modes = [
			[[], null, 'exclude'],
			[[], $unlisted, 'none'],
			[[], $listed, 'exclude'],
			[['@self' => ['status' => 'draft']], $unlisted, 'any'],
			[['@self' => ['status' => '']], null, 'exclude'],
			[['@self' => ['status' => []]], null, 'exclude'],
			[['@self' => 'x'], null, 'exclude'],
			[['_drafts' => ' ANY '], null, 'any'],
			[['_drafts' => 'none'], $listed, 'none'],
			[['_drafts' => 'true'], null, 'only'],
			[['_drafts' => 1], null, 'only'],
			[['_drafts' => 'yes'], null, 'only'],
			[['_drafts' => 'false'], $unlisted, 'none'],
			[['_drafts' => ['any']], null, 'exclude'],
			[['_drafts' => null], null, 'exclude'],
			[['_drafts' => 'only'], null, 'exclude'],
		];
		foreach ($modes as $i => [$query, $schema, $expected]) {
			$this->assertSame($expected, $lens->modeFor(query: $query, schema: $schema), "mode case $i");
		}

		$notADraft = "(t._status IS NULL OR t._status <> 'draft')";
		$this->assertNull($lens->clauseFor(listing: new DraftListing(mode: 'any', owner: "'me'"), columnPrefix: 't.'));
		$this->assertSame("t._status = 'draft'", $lens->clauseFor(listing: new DraftListing(mode: 'only', owner: null), columnPrefix: 't.'));
		$this->assertSame($notADraft, $lens->clauseFor(listing: new DraftListing(mode: 'none', owner: "'me'"), columnPrefix: 't.'));
		$this->assertSame($notADraft, $lens->clauseFor(listing: new DraftListing(mode: 'exclude', owner: null), columnPrefix: 't.'));
		$this->assertSame($notADraft, $lens->clauseFor(listing: new DraftListing(mode: 'exclude', owner: ''), columnPrefix: 't.'));
		$this->assertSame('(' . $notADraft . " OR t._owner = 'me')", $lens->clauseFor(
			listing: new DraftListing(mode: 'exclude', owner: "'me'"),
			columnPrefix: 't.'
		));
		$this->assertSame('(' . $notADraft . " OR t._owner = 'me')", $lens->clauseFor(
			listing: new DraftListing(mode: 'weird', owner: "'me'"),
			columnPrefix: 't.'
		));
	}//end testListLensTables()

	/**
	 * Which draft an owner gets back: by id, and the newest matching one.
	 *
	 * @return void
	 */
	public function testFinderOutcomes(): void {
		$owner = DraftOwner::scoped(scope: 'portal', reference: 'p-1');
		$mine = self::entity(ObjectEntity::STATUS_DRAFT, true, 'd-1', 'portal:p-1');
		$theirs = self::entity(ObjectEntity::STATUS_DRAFT, true, 'd-2', 'portal:p-2');
		$finished = self::entity(ObjectEntity::STATUS_ACTIVE, true, 'd-3', 'portal:p-1');

		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willReturnCallback(
			fn (...$args): ?ObjectEntity => match ($args[0]) {
				'd-1' => $mine,
				'd-2' => $theirs,
				'd-3' => $finished,
				'boom' => throw new \RuntimeException('down'),
				default => null,
			}
		);
		$finder = new DraftFinder(objects: $objects);
		$register = new Register();
		$schema = new Schema();

		$this->assertSame($mine, $finder->own(address: new DraftAddress(register: $register, schema: $schema, draftId: 'd-1'), owner: $owner));
		$this->assertNull($finder->own(address: new DraftAddress(register: $register, schema: $schema, draftId: 'd-1'), owner: null));
		$this->assertNull($finder->own(address: new DraftAddress(register: $register, schema: $schema, draftId: 'd-2'), owner: $owner));
		$this->assertNull($finder->own(address: new DraftAddress(register: $register, schema: $schema, draftId: 'd-3'), owner: $owner));
		$this->assertNull($finder->own(address: new DraftAddress(register: $register, schema: $schema, draftId: 'nope'), owner: $owner));
		$this->assertNull($finder->own(address: new DraftAddress(register: $register, schema: $schema, draftId: 'boom'), owner: $owner));

		$older = self::entity(ObjectEntity::STATUS_DRAFT, true, 'n-1', 'portal:p-1');
		$older->setObject(['kind' => 'a']);
		$older->setUpdated(new DateTime('@1000'));
		$newer = self::entity(ObjectEntity::STATUS_DRAFT, true, 'n-2', 'portal:p-1');
		$newer->setObject(['kind' => 'a']);
		$newer->setUpdated(new DateTime('@2000'));
		$tie = self::entity(ObjectEntity::STATUS_DRAFT, true, 'n-3', 'portal:p-1');
		$tie->setObject(['kind' => 'a']);
		$tie->setUpdated(new DateTime('@2000'));
		$other = self::entity(ObjectEntity::STATUS_DRAFT, true, 'n-4', 'portal:p-1');
		$other->setObject(['kind' => 'b']);
		$other->setUpdated(new DateTime('@3000'));
		$foreign = self::entity(ObjectEntity::STATUS_DRAFT, true, 'n-5', 'portal:p-2');
		$foreign->setObject(['kind' => 'a']);
		$foreign->setUpdated(new DateTime('@4000'));

		$lists = [
			[[$older, $newer, $tie, $other, $foreign, 'junk'], ['kind' => 'a'], 'n-2'],
			[[$older, $newer, $tie, $other, $foreign], [], 'n-4'],
			[[$older, $other], ['kind' => 'c'], null],
			[[$older], ['missing' => null], null],
			[[], [], null],
		];
		foreach ($lists as $i => [$found, $match, $expected]) {
			$search = $this->createMock(ObjectService::class);
			$search->method('searchObjects')->willReturn($found);
			$newest = (new DraftFinder(objects: $search))->newest(
				search: new OwnDraftSearch(register: $register, schema: $schema, owner: $owner, match: $match)
			);
			$this->assertSame($expected, $newest?->getUuid(), "newest case $i");
		}

		$failing = $this->createMock(ObjectService::class);
		$failing->method('searchObjects')->willThrowException(new \RuntimeException('down'));
		$this->assertNull((new DraftFinder(objects: $failing))->newest(
			search: new OwnDraftSearch(register: $register, schema: $schema, owner: $owner)
		));
		$scalar = $this->createMock(ObjectService::class);
		$scalar->method('searchObjects')->willReturn(['results' => 'x']);
		$this->assertNull((new DraftFinder(objects: $scalar))->newest(
			search: new OwnDraftSearch(register: $register, schema: $schema, owner: $owner)
		));
	}//end testFinderOutcomes()
}//end class
