<?php

/**
 * DraftServiceTest — named draft versions short of promotion: the delta-only
 * store, the key rules, and who may see, change and discard a draft.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/content-versioning/spec.md#requirement-objects-must-support-a-draftpublished-lifecycle
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use InvalidArgumentException;
use OCA\OpenRegister\Db\DraftVersion;
use OCA\OpenRegister\Db\DraftVersionMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\Object\DraftService;
use OCA\OpenRegister\Service\Object\PermissionCheckInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Object\DraftService
 * @uses   \OCA\OpenRegister\Db\DraftVersion
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Exception\NotAuthorizedException
 */
final class DraftServiceTest extends TestCase {

	private MockObject&DraftVersionMapper $drafts;

	private MockObject&PermissionCheckInterface $permissions;

	private DraftService $service;

	private ObjectEntity $object;

	private Schema $schema;

	protected function setUp(): void {
		$this->drafts = $this->createMock(DraftVersionMapper::class);
		$this->drafts->method('insert')->willReturnArgument(0);
		$this->drafts->method('update')->willReturnArgument(0);
		$this->permissions = $this->createMock(PermissionCheckInterface::class);
		$this->service = new DraftService($this->drafts, $this->permissions);

		$this->object = new ObjectEntity();
		$this->object->setUuid('9a1e0f3c-5d6b-4c7a-8e9f-0a1b2c3d4e5f');
		$this->object->setRegister('3');
		$this->object->setSchema('12');
		$this->object->setVersion('1.0.3');
		$this->object->setOwner('olga');
		$this->object->setObject(['title' => 'Geluidsoverlast', 'status' => 'nieuw', 'contact' => 'contact-1']);
		$this->schema = new Schema();
	}

	/**
	 * A stored draft of the object.
	 *
	 * @param string               $key     The key.
	 * @param string               $creator The creator.
	 * @param array<string, mixed> $delta   The delta.
	 */
	private function draft(string $key, string $creator, array $delta = ['status' => 'in_behandeling']): DraftVersion {
		$draft = new DraftVersion();
		$draft->setObjectUuid((string)$this->object->getUuid());
		$draft->setDraftKey($key);
		$draft->setCreatedBy($creator);
		$draft->setDelta($delta);
		return $draft;
	}

	private function writers(string ...$uids): void {
		$this->permissions->method('hasPermission')->willReturnCallback(
			fn (Schema $schema, string $action, ?string $userId = null, ?string $objectOwner = null, bool $_rbac = true, ?ObjectEntity $object = null): bool => $action === 'update'
				&& $objectOwner === 'olga'
				&& $object === $this->object
				&& in_array($userId, $uids, true)
		);
	}

	public function testADraftStoresOnlyTheChangedFieldsAndLeavesTheObjectAlone(): void {
		$this->drafts->expects($this->once())->method('findByKey')->with('9a1e0f3c-5d6b-4c7a-8e9f-0a1b2c3d4e5f', 'status-update')->willReturn(null);

		$draft = $this->service->create(
			object: $this->object,
			key: 'status-update',
			name: ' Status update ',
			changes: ['title' => 'Geluidsoverlast', 'status' => 'in_behandeling', 'note' => 'Gebeld', 'id' => 5, '@self' => ['x' => 1]],
			actor: 'kees'
		);

		$this->assertSame(['status' => 'in_behandeling', 'note' => 'Gebeld'], $draft->getDelta());
		$this->assertSame('status-update', $draft->getDraftKey());
		$this->assertSame('Status update', $draft->getName());
		$this->assertSame('kees', $draft->getCreatedBy());
		$this->assertSame('1.0.3', $draft->getBaseVersion());
		$this->assertSame('3', $draft->getObjectRegister());
		$this->assertSame('12', $draft->getObjectSchema());
		$this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', (string)$draft->getUuid());
		$this->assertNotNull($draft->getCreated());
		$this->assertSame(
			['id' => '9a1e0f3c-5d6b-4c7a-8e9f-0a1b2c3d4e5f', 'title' => 'Geluidsoverlast', 'status' => 'nieuw', 'contact' => 'contact-1'],
			$this->object->getObject()
		);
	}

	public function testADraftWithoutANameIsNamedByItsKey(): void {
		$this->drafts->method('findByKey')->willReturn(null);

		$this->assertSame('locatie-correctie', $this->service->create($this->object, 'locatie-correctie', '  ', [], 'kees')->getName());
	}

	public function testAChangedRelationIsStoredAsTheReferenceOnly(): void {
		$this->drafts->method('findByKey')->willReturn(null);

		$draft = $this->service->create($this->object, 'nieuw-contact', '', ['contact' => 'contact-2'], 'kees');

		$this->assertSame(['contact' => 'contact-2'], $draft->getDelta());
		$this->assertSame(
			['id' => '9a1e0f3c-5d6b-4c7a-8e9f-0a1b2c3d4e5f', 'title' => 'Geluidsoverlast', 'status' => 'nieuw', 'contact' => 'contact-2'],
			$this->service->merged(object: $this->object, draft: $draft)
		);
	}

	/**
	 * @dataProvider refusedKeys
	 */
	public function testAMalformedOrReservedKeyIsRefusedBeforeAnythingIsStored(string $key, string $message): void {
		$this->drafts->expects($this->never())->method('insert');

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage($message);
		$this->service->create($this->object, $key, '', ['status' => 'x'], 'kees');
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function refusedKeys(): array {
		$shape = 'A draft key is lower-case letters and digits joined by single hyphens, at most 64 characters.';
		return [
			'main' => ['main', 'The key "main" is reserved for the published version.'],
			'upper case' => ['Update-1', $shape],
			'underscore' => ['update_1', $shape],
			'double hyphen' => ['update--1', $shape],
			'leading hyphen' => ['-update', $shape],
			'empty' => ['', $shape],
			'too long' => [str_repeat('a', 65), $shape],
		];
	}

	public function testAKeyAlreadyUsedOnTheObjectIsRefused(): void {
		$this->drafts->method('findByKey')->willReturn($this->draft('update-1', 'anna'));
		$this->drafts->expects($this->never())->method('insert');

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('This object already has a draft with the key "update-1".');
		$this->service->create($this->object, 'update-1', '', ['status' => 'x'], 'kees');
	}

	public function testUpdatingKeepsOnlyFieldsThatStillDiffer(): void {
		$this->writers();
		$draft = $this->draft('status-update', 'kees', ['status' => 'in_behandeling', 'title' => 'Geluidsoverlast centrum']);

		$updated = $this->service->update($draft, $this->object, $this->schema, ['note' => 'Gebeld', 'title' => 'Geluidsoverlast'], 'kees');

		$this->assertSame(['status' => 'in_behandeling', 'note' => 'Gebeld'], $updated->getDelta());
		$this->assertNotNull($updated->getUpdated());
	}

	public function testAReaderWhoDidNotMakeTheDraftCannotChangeOrDiscardIt(): void {
		$this->writers('editor');
		$draft = $this->draft('status-update', 'kees');
		$this->drafts->expects($this->never())->method('update');
		$this->drafts->expects($this->never())->method('delete');

		try {
			$this->service->update($draft, $this->object, $this->schema, ['status' => 'x'], 'reader');
			$this->fail('A reader must not change another user\'s draft.');
		} catch (NotAuthorizedException $e) {
			$this->assertSame('Only the draft\'s creator or a user who may change the object may do this.', $e->getMessage());
		}

		$this->expectException(NotAuthorizedException::class);
		$this->service->discard($draft, $this->object, $this->schema, 'reader');
	}

	public function testTheCreatorAndAWriterMayDiscard(): void {
		$this->writers('editor');
		$this->drafts->expects($this->exactly(2))->method('delete');

		$this->service->discard($this->draft('a', 'kees'), $this->object, $this->schema, 'kees');
		$this->service->discard($this->draft('b', 'kees'), $this->object, $this->schema, 'editor');
	}

	public function testAReaderSeesOnlyTheirOwnDraftsAndAWriterSeesAll(): void {
		$this->writers('editor');
		$mine = $this->draft('mijn-wijziging', 'reader');
		$theirs = $this->draft('status-update', 'kees');
		$this->drafts->method('findForObject')->with('9a1e0f3c-5d6b-4c7a-8e9f-0a1b2c3d4e5f')->willReturn([$theirs, $mine]);

		$this->assertSame([$mine], $this->service->visibleDrafts($this->object, $this->schema, 'reader'));
		$this->assertSame([$theirs, $mine], $this->service->visibleDrafts($this->object, $this->schema, 'editor'));
		$this->assertSame([], $this->service->visibleDrafts($this->object, $this->schema, null));
	}

	public function testAnAnonymousCallerMayNeverAccessADraft(): void {
		$this->permissions->expects($this->never())->method('hasPermission');

		$this->assertFalse($this->service->mayAccess($this->draft('a', 'kees'), $this->object, $this->schema, null));
	}

	public function testTheDraftListsItsChangedFields(): void {
		$draft = $this->draft('status-update', 'kees', ['status' => 'in_behandeling', 'note' => 'Gebeld']);
		$draft->setName('Status update');

		$json = $draft->jsonSerialize();

		$this->assertSame('status-update', $json['key']);
		$this->assertSame('Status update', $json['name']);
		$this->assertSame('kees', $json['createdBy']);
		$this->assertSame(['status', 'note'], $json['changedFields']);
	}
}
