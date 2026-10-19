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
}//end class
