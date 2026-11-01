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
	 * A reader over one folder, owned by the OpenRegister system user.
	 *
	 * @param callable $sharesBy Answers getSharesBy(sharer, type, reshares).
	 * @param bool     $folder   Whether the object has a folder.
	 *
	 * @return ObjectShareReader The reader.
	 */
	private function readerFor(callable $sharesBy, bool $folder = true): ObjectShareReader {
		$systemUser = $this->createMock(IUser::class);
		$systemUser->method('getUID')->willReturn('openregister');
		$node = $this->createMock(Folder::class);
		$node->method('getOwner')->willReturn($systemUser);

		$folders = $this->createMock(FolderManagementHandler::class);
		$folders->method('getObjectFolder')->willReturn($folder === true ? $node : null);

		$manager = $this->createMock(IManager::class);
		$manager->method('getSharesBy')->willReturnCallback(
			function (string $sharer, int $type, $path, bool $reshares) use ($sharesBy): array {
				$this->asked[] = [$sharer, $type, $reshares];
				return $sharesBy($sharer, $type, $reshares);
			}
		);

		return new ObjectShareReader($folders, $manager, new NullLogger());
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
	 * An object without a folder has no shares.
	 *
	 * @return void
	 */
	public function testAnObjectWithoutAFolderHasNoShares(): void {
		$reader = $this->readerFor(static fn (): array => [], folder: false);

		$this->assertSame([], $reader->sharesOn(object: $this->object(), sharers: ['bea'], complete: true));
	}//end testAnObjectWithoutAFolderHasNoShares()

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
