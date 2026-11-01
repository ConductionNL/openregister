<?php

/**
 * The guard-free share reader, and the inherited lister built on it.
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
 *
 * @spec openspec/changes/an-object-lists-its-grants-with-their-origin/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\File\FolderManagementHandler;
use OCA\OpenRegister\Service\Rbac\HierarchyDescender;
use OCA\OpenRegister\Service\Rbac\InheritedGrantLister;
use OCA\OpenRegister\Service\Rbac\ObjectShareReader;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IUser;
use OCP\Share\IManager;
use OCP\Share\IShare;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Task 2.1.
 *
 * @covers \OCA\OpenRegister\Service\Rbac\ObjectShareReader
 * @covers \OCA\OpenRegister\Service\Rbac\InheritedGrantLister
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 */
class ObjectShareReaderTest extends TestCase {

	/**
	 * Every `getSharesBy()` call the share manager received: sharer, type, re-shares.
	 *
	 * @var array<int, array{0: string, 1: int, 2: bool}>
	 */
	private array $asked = [];

	/**
	 * Whether the creating folder lookup was used.
	 *
	 * @var bool
	 */
	private bool $created = false;

	/**
	 * A reader over one folder, owned by the OpenRegister system user.
	 *
	 * @param callable    $sharesBy Answers getSharesBy(sharer, type, reshares).
	 * @param string      $folder   `found`, `gone` (the stored id finds nothing), `throws` (the lookup fails)
	 *                              or `ownerless` (the folder has no owner).
	 *
	 * @return ObjectShareReader The reader.
	 */
	private function readerFor(callable $sharesBy, string $folder = 'found'): ObjectShareReader {
		$systemUser = $this->createMock(IUser::class);
		$systemUser->method('getUID')->willReturn('openregister');
		$node = $this->createMock(Folder::class);
		$node->method('getOwner')->willReturn($folder === 'ownerless' ? null : $systemUser);

		$folders = $this->createMock(FolderManagementHandler::class);
		$folders->method('getObjectFolder')->willReturnCallback(
			function () use ($node): Folder {
				$this->created = true;
				return $node;
			}
		);

		$root = $this->createMock(IRootFolder::class);
		$root->method('getById')->willReturnCallback(
			static function () use ($folder, $node): array {
				if ($folder === 'throws') {
					throw new RuntimeException('storage unavailable');
				}

				return $folder === 'gone' ? [] : [$node];
			}
		);

		$manager = $this->createMock(IManager::class);
		$manager->method('getSharesBy')->willReturnCallback(
			function (string $sharer, int $type, $path, bool $reshares) use ($sharesBy): array {
				$this->asked[] = [$sharer, $type, $reshares];
				return $sharesBy($sharer, $type, $reshares);
			}
		);

		return new ObjectShareReader($folders, $manager, new NullLogger(), $root);
	}//end readerFor()

	/**
	 * A share with an id and a principal.
	 *
	 * @param string $fullId     The provider-prefixed id.
	 * @param string $sharedWith The principal.
	 *
	 * @return IShare The share.
	 */
	private function share(string $fullId, string $sharedWith = 'x'): IShare {
		$share = $this->createMock(IShare::class);
		$share->method('getFullId')->willReturn($fullId);
		$share->method('getSharedWith')->willReturn($sharedWith);
		$share->method('getPermissions')->willReturn(1);
		return $share;
	}//end share()

	/**
	 * An object owned by bea.
	 *
	 * @param string $uuid The uuid.
	 *
	 * @return ObjectEntity The object.
	 */
	private function object(string $uuid = 'case-1'): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setOwner('bea');
		$object->setRegister('1');
		$object->setSchema('7');
		$object->setFolder('55');
		return $object;
	}//end object()

	/**
	 * The panel's reading: the named sharers only, no re-shares, a failing type skipped.
	 *
	 * @return void
	 */
	public function testThePanelReadingAsksTheNamedSharersAndSkipsFailures(): void {
		$reader = $this->readerFor(
			function (string $sharer, int $type): array {
				if ($type === IShare::TYPE_REMOTE) {
					throw new RuntimeException('federation off');
				}

				return ($sharer === 'bea' && $type === IShare::TYPE_GROUP) ? [$this->share('ocinternal:1', 'juridische-zaken')] : [];
			}
		);

		$found = $reader->sharesOn(object: $this->object(), sharers: ['bea', '', 'bea']);

		$this->assertSame(['ocinternal:1'], array_keys($found));
		$this->assertSame('group', $found['ocinternal:1']['type']);
		$this->assertSame(['bea'], array_values(array_unique(array_column($this->asked, 0))));
		$this->assertNotContains(true, array_column($this->asked, 2));
	}//end testThePanelReadingAsksTheNamedSharersAndSkipsFailures()

	/**
	 * The complete reading also asks as the folder owner with re-shares, so a colleague's share is found.
	 *
	 * @return void
	 */
	public function testTheCompleteReadingFindsAShareSomebodyElseMade(): void {
		$reader = $this->readerFor(
			fn (string $sharer, int $type, bool $reshares): array => ($sharer === 'openregister' && $reshares === true && $type === IShare::TYPE_GROUP)
				? [$this->share('ocinternal:41', 'juridische-zaken')]
				: []
		);

		$found = $reader->sharesOn(object: $this->object(), sharers: ['bea'], complete: true);

		$this->assertSame(['ocinternal:41'], array_keys($found));
		$this->assertContains('openregister', array_column($this->asked, 0));
		$this->assertFalse($this->created);
	}//end testTheCompleteReadingFindsAShareSomebodyElseMade()

	/**
	 * The complete reading fails loudly rather than shrinking the answer.
	 *
	 * @return void
	 */
	public function testTheCompleteReadingThrowsOnAnUnreadableType(): void {
		$reader = $this->readerFor(static fn (): array => throw new RuntimeException('share table gone'));

		$this->expectException(RuntimeException::class);
		$reader->sharesOn(object: $this->object(), sharers: ['bea'], complete: true);
	}//end testTheCompleteReadingThrowsOnAnUnreadableType()

	/**
	 * The complete reading never creates a folder: an object without one, or whose folder is gone, has no shares.
	 *
	 * @return void
	 */
	public function testAnObjectWithoutAFolderHasNoShares(): void {
		$object = $this->object();
		$object->setFolder(null);

		$this->assertSame([], $this->readerFor(static fn (): array => [])->sharesOn(object: $object, sharers: ['bea'], complete: true));
		$this->assertSame([], $this->readerFor(static fn (): array => [], folder: 'gone')->sharesOn(object: $this->object(), sharers: ['bea'], complete: true));
		$this->assertFalse($this->created, 'a read must not create or recreate a folder');
	}//end testAnObjectWithoutAFolderHasNoShares()

	/**
	 * The panel reading keeps creating the folder, as `/shares` always has.
	 *
	 * @return void
	 */
	public function testThePanelReadingStillResolvesTheFolderAsBefore(): void {
		$this->readerFor(static fn (): array => [])->sharesOn(object: $this->object(), sharers: ['bea']);

		$this->assertTrue($this->created);
	}//end testThePanelReadingStillResolvesTheFolderAsBefore()

	/**
	 * A folder lookup that fails is an error, not "no shares".
	 *
	 * @return void
	 */
	public function testTheCompleteReadingThrowsWhenTheFolderCannotBeLookedUp(): void {
		$this->expectException(RuntimeException::class);
		$this->readerFor(static fn (): array => [], folder: 'throws')->sharesOn(object: $this->object(), sharers: ['bea'], complete: true);
	}//end testTheCompleteReadingThrowsWhenTheFolderCannotBeLookedUp()

	/**
	 * A folder without an owner cannot be read completely, so it throws rather than reading less.
	 *
	 * @return void
	 */
	public function testTheCompleteReadingThrowsWhenTheFolderHasNoOwner(): void {
		$this->expectException(RuntimeException::class);
		$this->readerFor(static fn (): array => [], folder: 'ownerless')->sharesOn(object: $this->object(), sharers: ['bea'], complete: true);
	}//end testTheCompleteReadingThrowsWhenTheFolderHasNoOwner()

	/**
	 * The inherited lister keeps its row shape and names the ancestor; the complete reading throws on a failed walk.
	 *
	 * @return void
	 */
	public function testTheInheritedListerOverTheReader(): void {
		$parent = $this->object(uuid: 'main-case');
		$mapper = $this->createMock(MagicMapper::class);
		$mapper->method('find')->willReturn($parent);

		$hierarchy = $this->createMock(HierarchyDescender::class);
		$hierarchy->method('ancestorsOf')->willReturn(['main-case']);

		$reader = $this->readerFor(
			fn (string $sharer, int $type): array => $type === IShare::TYPE_USER ? [$this->share('ocinternal:60', 'tvisser')] : []
		);
		$lister = new InheritedGrantLister($hierarchy, $mapper, $reader, new NullLogger());

		$rows = $lister->inheritedGrantsFor(object: $this->object());
		$this->assertSame(
			[['id' => 'ocinternal:60', 'type' => 'user', 'sharedWith' => 'tvisser', 'permissions' => 1, 'expiration' => null, 'inherited' => true, 'inheritedFrom' => 'main-case']],
			$rows
		);

		$shares = $lister->inheritedSharesFor(object: $this->object(), complete: true);
		$this->assertSame($parent, $shares[0]['ancestor']);

		$broken = $this->createMock(HierarchyDescender::class);
		$broken->method('ancestorsOf')->willThrowException(new RuntimeException('no hierarchy'));
		$lister = new InheritedGrantLister($broken, $mapper, $reader, new NullLogger());
		$this->assertSame([], $lister->inheritedGrantsFor(object: $this->object()), 'the panel reading answers none');

		$this->expectException(RuntimeException::class);
		$lister->inheritedSharesFor(object: $this->object(), complete: true);
	}//end testTheInheritedListerOverTheReader()
}//end class
