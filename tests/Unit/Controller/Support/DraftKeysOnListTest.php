<?php

/**
 * Draft keys on the rows of a list (`_drafts=true`).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/changes/records-draft-versions/specs/content-versioning/spec.md#requirement-drafts-never-appear-as-objects-in-lists-or-search
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\Support\DraftKeysOnList;
use OCA\OpenRegister\Db\DraftVersion;
use OCA\OpenRegister\Db\DraftVersionMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\DraftService;
use OCA\OpenRegister\Service\Object\PermissionAsk;
use OCA\OpenRegister\Service\Object\PermissionCheckInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Controller\Support\DraftKeysOnList
 * @uses   \OCA\OpenRegister\Service\Object\DraftService
 * @uses   \OCA\OpenRegister\Db\DraftVersion
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\Schema
 */
final class DraftKeysOnListTest extends TestCase {

	private MockObject&DraftVersionMapper $mapper;

	private MockObject&PermissionCheckInterface $permissions;

	private DraftService $drafts;

	private Schema $schema;

	protected function setUp(): void {
		$this->mapper      = $this->createMock(DraftVersionMapper::class);
		$this->permissions = $this->createMock(PermissionCheckInterface::class);
		$this->drafts      = new DraftService($this->mapper, $this->permissions);
		$this->schema      = new Schema();
	}

	private static function draft(string $object, string $key, string $creator): DraftVersion {
		$draft = new DraftVersion();
		$draft->setObjectUuid($object);
		$draft->setDraftKey($key);
		$draft->setCreatedBy($creator);
		return $draft;
	}

	private static function rows(): array {
		return [
			['title' => 'A', '@self' => ['id' => 'uuid-a', 'owner' => 'olga']],
			['title' => 'B', '@self' => ['id' => 'uuid-b']],
			['title' => 'C', '@self' => ['id' => 'uuid-c']],
		];
	}

	public function testTheParameterIsReadAsABoolean(): void {
		self::assertTrue(DraftKeysOnList::asked(params: ['_drafts' => 'true']));
		self::assertTrue(DraftKeysOnList::asked(params: ['_drafts' => '1']));
		self::assertFalse(DraftKeysOnList::asked(params: ['_drafts' => 'false']));
		self::assertFalse(DraftKeysOnList::asked(params: []));
	}

	public function testRowsCarryTheirVisibleKeysAndTheListIsUnchanged(): void {
		$this->mapper->expects(self::once())->method('findForObjects')->with(['uuid-a', 'uuid-b', 'uuid-c'])->willReturn(
			[
				self::draft(object: 'uuid-a', key: 'eigen', creator: 'kees'),
				self::draft(object: 'uuid-c', key: 'van-piet', creator: 'piet'),
				self::draft(object: 'uuid-c', key: 'ook-eigen', creator: 'kees'),
			]
		);
		$live = new ObjectEntity();
		$live->setOwner('olga');
		$loaded = [];
		$this->permissions->expects(self::once())->method('hasPermission')
			->with(
				$this->callback(
					fn (PermissionAsk $ask): bool => $ask->schema === $this->schema
						&& $ask->action === 'update'
						&& $ask->userId === 'kees'
						&& $ask->objectOwner === 'olga'
						&& $ask->object === $live
				),
				true
			)
			->willReturn(false);

		$out = DraftKeysOnList::annotate(
			results: self::rows(),
			drafts: $this->drafts,
			schema: $this->schema,
			caller: 'kees',
			load: function (string $uuid) use (&$loaded, $live): ObjectEntity {
				$loaded[] = $uuid;
				return $live;
			}
		);

		self::assertSame(['uuid-c'], $loaded, 'Only an object with someone else\'s draft is loaded.');
		self::assertSame(['A', 'B', 'C'], array_column($out, 'title'));
		self::assertSame(['eigen'], $out[0]['@self']['drafts']);
		self::assertSame('olga', $out[0]['@self']['owner']);
		self::assertSame([], $out[1]['@self']['drafts']);
		self::assertSame(['ook-eigen'], $out[2]['@self']['drafts']);
	}

	public function testAWriterSeesEveryKey(): void {
		$this->mapper->method('findForObjects')->willReturn(
			[self::draft(object: 'uuid-c', key: 'van-piet', creator: 'piet'), self::draft(object: 'uuid-c', key: 'van-kees', creator: 'kees')]
		);
		$this->permissions->method('hasPermission')->willReturn(true);

		$out = DraftKeysOnList::annotate(
			results: self::rows(),
			drafts: $this->drafts,
			schema: $this->schema,
			caller: 'wendy',
			load: static fn (string $uuid): ObjectEntity => new ObjectEntity()
		);

		self::assertSame(['van-piet', 'van-kees'], $out[2]['@self']['drafts']);
	}

	public function testAFailingLookupHidesOtherPeoplesDrafts(): void {
		$this->mapper->method('findForObjects')->willReturn([self::draft(object: 'uuid-b', key: 'van-piet', creator: 'piet')]);
		$this->permissions->expects(self::never())->method('hasPermission');

		$out = DraftKeysOnList::annotate(
			results: self::rows(),
			drafts: $this->drafts,
			schema: $this->schema,
			caller: 'kees',
			load: static function (string $uuid): never {
				throw new RuntimeException('gone');
			}
		);

		self::assertSame([], $out[1]['@self']['drafts']);
	}

	public function testAnAnonymousCallerSeesNoKeysAndNothingIsRead(): void {
		$this->mapper->expects(self::never())->method('findForObjects');

		$out = DraftKeysOnList::annotate(
			results: self::rows(),
			drafts: $this->drafts,
			schema: $this->schema,
			caller: null,
			load: static fn (string $uuid): ?ObjectEntity => null
		);

		self::assertSame([[], [], []], array_map(static fn (array $row): array => $row['@self']['drafts'], $out));
	}

	public function testRowsThatAreNotObjectsAreLeftAlone(): void {
		$this->mapper->method('findForObjects')->willReturn([]);

		$out = DraftKeysOnList::annotate(
			results: ['plain', ['title' => 'geen id'], ['id' => 'uuid-x']],
			drafts: $this->drafts,
			schema: $this->schema,
			caller: 'kees',
			load: static fn (string $uuid): ?ObjectEntity => null
		);

		self::assertSame('plain', $out[0]);
		self::assertSame(['title' => 'geen id'], $out[1]);
		self::assertSame(['id' => 'uuid-x', '@self' => ['drafts' => []]], $out[2]);
	}
}
