<?php

/**
 * TaggingHandler for the tag-object flow step: whether an object carries a
 * tag, a colour set on a new tag through updateTag(), and a colour set on
 * an existing tag only when it has none.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\File
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\File;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.

use OCA\OpenRegister\Files\Service\File\TaggingHandler;
use OCP\IUserSession;
use OCP\SystemTag\ISystemTag;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * TaggingHandler tag checks and colours.
 *
 * @covers \OCA\OpenRegister\Files\Service\File\TaggingHandler
 * @covers \OCA\OpenRegister\Files\Service\File\TagColour
 *
 * @spec openspec/changes/flow-tag-object-step/specs/flow-engine/spec.md
 */
class TaggingHandlerTest extends TestCase {

	private const LEAD = 'lead-1';

	private ISystemTagManager&MockObject $tagManager;

	private ISystemTagObjectMapper&MockObject $tagMapper;

	private LoggerInterface&MockObject $logger;

	private TaggingHandler $handler;

	protected function setUp(): void {
		parent::setUp();
		$this->tagManager = $this->createMock(ISystemTagManager::class);
		$this->tagMapper  = $this->createMock(ISystemTagObjectMapper::class);
		$this->logger     = $this->createMock(LoggerInterface::class);
		// No session user: a background run that Nextcloud's assign rule does not apply to.
		$this->handler = new TaggingHandler($this->tagManager, $this->tagMapper, $this->logger, $this->createMock(IUserSession::class));
	}//end setUp()

	private function tag(string $id, string $name, ?string $color = null): ISystemTag&MockObject {
		$tag = $this->createMock(ISystemTag::class);
		$tag->method('getId')->willReturn($id);
		$tag->method('getName')->willReturn($name);
		$tag->method('getColor')->willReturn($color);
		$tag->method('isUserVisible')->willReturn(true);
		$tag->method('isUserAssignable')->willReturn(true);

		return $tag;
	}//end tag()

	private function leadCarries(ISystemTag ...$tags): void {
		$ids = array_map(static fn (ISystemTag $tag): string => $tag->getId(), $tags);
		$this->tagMapper->method('getTagIdsForObjects')->with([self::LEAD], 'openregister')->willReturn([self::LEAD => $ids]);
		$this->tagManager->method('getTagsByIds')->willReturn($tags);
	}//end leadCarries()

	public function testAnObjectCarryingTheTagHasIt(): void {
		$this->leadCarries($this->tag('7', 'Large deal'), $this->tag('8', 'Hot'));

		$this->assertTrue($this->handler->hasObjectTag(self::LEAD, 'Large deal'));
		$this->assertFalse($this->handler->hasObjectTag(self::LEAD, 'Large'));
	}//end testAnObjectCarryingTheTagHasIt()

	public function testAnObjectWithoutTagsHasNone(): void {
		$this->tagMapper->method('getTagIdsForObjects')->willReturn([self::LEAD => []]);
		$this->tagManager->expects($this->never())->method('getTagsByIds');

		$this->assertFalse($this->handler->hasObjectTag(self::LEAD, 'Large deal'));
	}//end testAnObjectWithoutTagsHasNone()

	public function testANewTagIsColouredThroughUpdateTag(): void {
		$created = $this->tag('9', 'Large deal');
		$this->tagManager->method('getAllTags')->willReturn([]);
		$this->tagManager->expects($this->once())->method('createTag')->with('Large deal', true, true)->willReturn($created);
		$this->tagManager->expects($this->once())->method('updateTag')->with('9', 'Large deal', true, true, 'd94c3d');
		$this->tagMapper->expects($this->once())->method('assignTags')->with(self::LEAD, 'openregister', ['9']);

		$this->handler->addObjectTag(self::LEAD, 'Large deal', 'd94c3d');
	}//end testANewTagIsColouredThroughUpdateTag()

	public function testAnExistingUncolouredTagIsColoured(): void {
		$this->tagManager->method('getAllTags')->willReturn([$this->tag('7', 'Large deal')]);
		$this->tagManager->expects($this->never())->method('createTag');
		$this->tagManager->expects($this->once())->method('updateTag')->with('7', 'Large deal', true, true, 'd94c3d');

		$this->handler->addObjectTag(self::LEAD, 'Large deal', 'd94c3d');
	}//end testAnExistingUncolouredTagIsColoured()

	public function testAnAdministratorsColourIsKept(): void {
		$this->tagManager->method('getAllTags')->willReturn([$this->tag('7', 'Large deal', '2d7b3f')]);
		$this->tagManager->expects($this->never())->method('updateTag');
		$this->tagMapper->expects($this->once())->method('assignTags')->with(self::LEAD, 'openregister', ['7']);

		$this->handler->addObjectTag(self::LEAD, 'Large deal', 'd94c3d');
	}//end testAnAdministratorsColourIsKept()

	public function testWithoutAColourNoTagIsUpdated(): void {
		$this->tagManager->method('getAllTags')->willReturn([$this->tag('7', 'Large deal')]);
		$this->tagManager->expects($this->never())->method('updateTag');

		$this->handler->addObjectTag(self::LEAD, 'Large deal');
	}//end testWithoutAColourNoTagIsUpdated()

	public function testARefusedColourIsLoggedAndTheTagStillAssigned(): void {
		$this->tagManager->method('getAllTags')->willReturn([$this->tag('7', 'Large deal')]);
		$this->tagManager->method('updateTag')->willThrowException(new RuntimeException('refused'));
		$this->logger->expects($this->once())->method('warning')
			->with('[TaggingHandler] Could not colour tag Large deal: refused', $this->anything());
		$this->tagMapper->expects($this->once())->method('assignTags');

		$this->handler->addObjectTag(self::LEAD, 'Large deal', 'd94c3d');
	}//end testARefusedColourIsLoggedAndTheTagStillAssigned()
}//end class
