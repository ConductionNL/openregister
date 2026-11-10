<?php

/**
 * The system-tags leaf on an object: its fixed metadata, the tags assigned to
 * the object read back with their visibility, nothing for an untagged object
 * or a failing tag mapper, and the write path that is not implemented here.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Integration\BuiltinProviders
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Integration\BuiltinProviders;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.

use OCA\OpenRegister\Exception\NotImplementedException;
use OCA\OpenRegister\Service\Integration\BuiltinProviders\TagsProvider;
use OCP\IL10N;
use OCP\SystemTag\ISystemTag;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * TagsProvider.
 */
class TagsProviderBehaviourTest extends TestCase {

	private ISystemTagManager&MockObject $tags;

	private ISystemTagObjectMapper&MockObject $objects;

	protected function setUp(): void {
		$this->tags = $this->createMock(ISystemTagManager::class);
		$this->objects = $this->createMock(ISystemTagObjectMapper::class);
	}//end setUp()

	private function provider(): TagsProvider {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		return new TagsProvider($this->tags, $this->objects, $l10n);
	}//end provider()

	private function tag(string $id, string $name, bool $visible, bool $assignable): ISystemTag {
		$tag = $this->createMock(ISystemTag::class);
		$tag->method('getId')->willReturn($id);
		$tag->method('getName')->willReturn($name);
		$tag->method('isUserVisible')->willReturn($visible);
		$tag->method('isUserAssignable')->willReturn($assignable);
		return $tag;
	}//end tag()

	public function testItIsACoreAlwaysOnLinkTableProvider(): void {
		$provider = $this->provider();

		$this->assertSame(
			['tags', 'Tags', 'TagOutline', 'core', null, 'link-table', true],
			[
				$provider->getId(),
				$provider->getLabel(),
				$provider->getIcon(),
				$provider->getGroup(),
				$provider->getRequiredApp(),
				$provider->getStorageStrategy(),
				$provider->isEnabled(),
			]
		);
	}//end testItIsACoreAlwaysOnLinkTableProvider()

	public function testTheObjectsTagsAreListedWithTheirVisibility(): void {
		$this->objects->expects($this->once())->method('getTagIdsForObjects')->with(['obj-1'], 'openregister')
			->willReturn(['obj-1' => ['3', '4']]);
		$this->tags->expects($this->once())->method('getTagsByIds')->with(['3', '4'])
			->willReturn([$this->tag('3', 'urgent', true, true), $this->tag('4', 'internal', false, false)]);

		$this->assertSame(
			[
				['id' => '3', 'name' => 'urgent', 'visibility' => true, 'assignable' => true],
				['id' => '4', 'name' => 'internal', 'visibility' => false, 'assignable' => false],
			],
			$this->provider()->list('r', 's', 'obj-1')
		);
	}//end testTheObjectsTagsAreListedWithTheirVisibility()

	public function testAnUntaggedObjectListsNothingWithoutLoadingTags(): void {
		$this->objects->method('getTagIdsForObjects')->willReturn(['other' => ['1']]);
		$this->tags->expects($this->never())->method('getTagsByIds');

		$this->assertSame([], $this->provider()->list('r', 's', 'obj-1'));
	}//end testAnUntaggedObjectListsNothingWithoutLoadingTags()

	public function testAnEmptyTagListAlsoListsNothing(): void {
		$this->objects->method('getTagIdsForObjects')->willReturn(['obj-1' => []]);
		$this->tags->expects($this->never())->method('getTagsByIds');

		$this->assertSame([], $this->provider()->list('r', 's', 'obj-1'));
	}//end testAnEmptyTagListAlsoListsNothing()

	public function testAFailingTagLookupListsNothing(): void {
		$this->objects->method('getTagIdsForObjects')->willReturn(['obj-1' => ['3']]);
		$this->tags->method('getTagsByIds')->willThrowException(new RuntimeException('tag gone'));

		$this->assertSame([], $this->provider()->list('r', 's', 'obj-1'));
	}//end testAFailingTagLookupListsNothing()

	public function testCreatingATagHereIsNotImplemented(): void {
		$this->expectException(NotImplementedException::class);
		$this->expectExceptionMessage('TagsProvider write path delegates to TagsController');

		$this->provider()->create('r', 's', 'obj-1', ['name' => 'x']);
	}//end testCreatingATagHereIsNotImplemented()
}//end class
