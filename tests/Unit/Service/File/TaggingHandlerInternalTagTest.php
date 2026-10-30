<?php

/**
 * TaggingHandler internal object tag tests.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\File
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\File;

use OCA\OpenRegister\Service\File\TaggingHandler;
use OCP\IUserSession;
use OCP\SystemTag\ISystemTag;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The internal `object:<uuid>` tag is created hidden and not assignable; a
 * user's own tag keeps being created visible and assignable.
 *
 * @covers \OCA\OpenRegister\Service\File\TaggingHandler
 */
class TaggingHandlerInternalTagTest extends TestCase {

	/**
	 * Attach two new tags to a file and record how each was created.
	 *
	 * @return array<string, array{visible: bool, assignable: bool}> Per tag name.
	 */
	private function createdTags(): array {
		$created = [];
		$manager = $this->createMock(ISystemTagManager::class);
		$manager->method('getAllTags')->willReturn([]);
		$manager->method('createTag')->willReturnCallback(
			function (string $tagName, bool $userVisible, bool $userAssignable) use (&$created): ISystemTag {
				$created[$tagName] = ['visible' => $userVisible, 'assignable' => $userAssignable];
				$tag = $this->createMock(ISystemTag::class);
				$tag->method('getId')->willReturn((string)count($created));
				$tag->method('getName')->willReturn($tagName);

				return $tag;
			}
		);

		$mapper = $this->createMock(ISystemTagObjectMapper::class);
		$mapper->method('getTagIdsForObjects')->willReturn([]);

		$handler = new TaggingHandler(
			$manager,
			$mapper,
			$this->createMock(LoggerInterface::class),
			$this->createMock(IUserSession::class)
		);
		$handler->attachTagsToFile(fileId: '12', tags: [$handler->generateObjectTag('3f2c-uuid'), 'invoice']);

		return $created;
	}//end createdTags()

	/**
	 * The object link tag is hidden from users and cannot be assigned by them.
	 *
	 * @return void
	 */
	public function testTheObjectTagIsCreatedHiddenAndNotAssignable(): void {
		$created = $this->createdTags();

		$this->assertSame(['visible' => false, 'assignable' => false], $created['object:3f2c-uuid'] ?? null);
	}//end testTheObjectTagIsCreatedHiddenAndNotAssignable()

	/**
	 * A user's own tag is still created visible and assignable.
	 *
	 * @return void
	 */
	public function testAUserTagStaysVisibleAndAssignable(): void {
		$created = $this->createdTags();

		$this->assertSame(['visible' => true, 'assignable' => true], $created['invoice'] ?? null);
	}//end testAUserTagStaysVisibleAndAssignable()
	/**
	 * Attach one existing tag to a file and record how the manager was asked to update it.
	 *
	 * @param string $name       The existing tag's name.
	 * @param bool   $visible    Whether it is visible now.
	 * @param bool   $assignable Whether it is assignable now.
	 * @param bool   $updateFails Whether the manager refuses the update.
	 *
	 * @return array<int, array<string, mixed>> The updateTag() calls.
	 */
	private function updatesForExisting(string $name, bool $visible, bool $assignable, bool $updateFails=false): array {
		$updates = [];
		$tag = $this->createMock(ISystemTag::class);
		$tag->method('getId')->willReturn('7');
		$tag->method('getName')->willReturn($name);
		$tag->method('isUserVisible')->willReturn($visible);
		$tag->method('isUserAssignable')->willReturn($assignable);
		$tag->method('getColor')->willReturn('00ff00');

		$manager = $this->createMock(ISystemTagManager::class);
		$manager->method('getAllTags')->willReturn([$tag]);
		$manager->expects($this->never())->method('createTag');
		$manager->method('updateTag')->willReturnCallback(
			function (string $tagId, string $newName, bool $userVisible, bool $userAssignable, ?string $color) use (&$updates, $updateFails): void {
				$updates[] = compact('tagId', 'newName', 'userVisible', 'userAssignable', 'color');
				if ($updateFails === true) {
					throw new \Exception('refused');
				}
			}
		);

		$mapper = $this->createMock(ISystemTagObjectMapper::class);
		$mapper->method('getTagIdsForObjects')->willReturn([]);
		$mapper->expects($this->once())->method('assignTags');

		$handler = new TaggingHandler($manager, $mapper, $this->createMock(LoggerInterface::class), $this->createMock(IUserSession::class));
		$handler->attachTagsToFile(fileId: '12', tags: [$name]);

		return $updates;
	}//end updatesForExisting()

	/**
	 * 🔴 AN EXISTING OBJECT TAG THAT IS STILL VISIBLE OR ASSIGNABLE IS HIDDEN WHEN IT IS NEXT USED.
	 *
	 * A tag created before the hidden default, or by a user in the Files tag UI,
	 * kept both flags true, so the outcome held for new installs only.
	 *
	 * @return void
	 */
	public function testAnExistingVisibleObjectTagIsHiddenWhenItIsNextUsed(): void {
		$updates = $this->updatesForExisting(name: 'object:3f2c-uuid', visible: true, assignable: true);

		$this->assertSame(
			[['tagId' => '7', 'newName' => 'object:3f2c-uuid', 'userVisible' => false, 'userAssignable' => false, 'color' => '00ff00']],
			$updates
		);

		$this->assertCount(1, $this->updatesForExisting(name: 'object:3f2c-uuid', visible: false, assignable: true));
	}//end testAnExistingVisibleObjectTagIsHiddenWhenItIsNextUsed()

	/**
	 * Controls: an already hidden object tag and a user's own tag are not updated, and a refused update does not lose the link.
	 *
	 * @return void
	 */
	public function testOtherExistingTagsAreLeftAlone(): void {
		$this->assertSame([], $this->updatesForExisting(name: 'object:3f2c-uuid', visible: false, assignable: false));
		$this->assertSame([], $this->updatesForExisting(name: 'invoice', visible: true, assignable: true));

		// The assignTags() expectation inside the helper proves the file is still linked.
		$this->assertCount(1, $this->updatesForExisting(name: 'object:3f2c-uuid', visible: true, assignable: true, updateFails: true));
	}//end testOtherExistingTagsAreLeftAlone()
}//end class
