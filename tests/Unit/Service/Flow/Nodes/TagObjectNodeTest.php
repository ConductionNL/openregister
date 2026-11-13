<?php

/**
 * The tag-object flow step: it adds or removes a tag on each item's object
 * as the run identity, refuses an object that identity may not update,
 * changes nothing that is already so, and passes the items through.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow\Nodes
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow\Nodes;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.

use Exception;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Kernel\Contracts\TaggingHandlerInterface;
use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\FlowNodeCall;
use OCA\OpenRegister\Service\Flow\IFlowNodeTaxonomy;
use OCA\OpenRegister\Service\Flow\Nodes\FlowNodePresentation;
use OCA\OpenRegister\Service\Flow\Nodes\TagObjectNode;
use OCA\OpenRegister\Service\Object\PermissionCheckInterface;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\WorkflowEngine\IManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use UnexpectedValueException;

/**
 * TagObjectNode.
 *
 * @covers \OCA\OpenRegister\Service\Flow\Nodes\TagObjectNode
 * @uses \OCA\OpenRegister\Service\Flow\Nodes\FlowNodePresentation
 * @uses \OCA\OpenRegister\Service\Flow\Nodes\TagStepConfig
 * @uses \OCA\OpenRegister\Service\Flow\FlowNodeCall
 * @uses \OCA\OpenRegister\Service\Flow\FlowItems
 * @uses \OCA\OpenRegister\Service\Flow\FlowValueTemplate
 * @uses \OCA\OpenRegister\Service\WriteCause
 *
 * @spec openspec/changes/flow-tag-object-step/specs/flow-engine/spec.md
 */
class TagObjectNodeTest extends TestCase {

	private ObjectOperationsInterface&MockObject $objects;

	private TaggingHandlerInterface&MockObject $tags;

	private SchemaMapperInterface&MockObject $schemas;

	private PermissionCheckInterface&MockObject $permissions;

	private IUserManager&MockObject $users;

	/**
	 * The uuids the run identity may update.
	 *
	 * @var list<string>
	 */
	private array $updatable = ['lead-1', 'lead-2'];

	/**
	 * Who the objects were loaded as.
	 *
	 * @var list<string>
	 */
	private array $actedAs = [];

	protected function setUp(): void {
		parent::setUp();
		$this->objects     = $this->createMock(ObjectOperationsInterface::class);
		$this->tags        = $this->createMock(TaggingHandlerInterface::class);
		$this->schemas     = $this->createMock(SchemaMapperInterface::class);
		$this->permissions = $this->createMock(PermissionCheckInterface::class);
		$this->users       = $this->createMock(IUserManager::class);

		$this->users->method('get')->willReturnCallback(fn (string $uid): ?IUser => $this->user(uid: $uid));
		$this->objects->method('runAs')->willReturnCallback(function (IUser $user, callable $operation) {
			$this->actedAs[] = $user->getUID();
			return $operation();
		});
		$this->objects->method('find')->willReturnCallback(static function (int|string $id): ObjectEntity {
			if ($id === 'gone') {
				throw new Exception('Object not found');
			}

			$object = new ObjectEntity();
			$object->setUuid((string) $id);
			$object->setSchema('12');
			$object->setOwner('owner');
			return $object;
		});
		$this->schemas->method('find')->with('12')->willReturn(new Schema());
		$this->permissions->method('hasPermission')->willReturnCallback(
			fn (Schema $schema, string $action, ?string $userId = null, ?string $objectOwner = null, bool $_rbac = true, ?ObjectEntity $object = null): bool =>
				$action === 'update' && in_array($userId, ['sales', 'trigger'], true) && $objectOwner === 'owner' && in_array($object?->getUuid(), $this->updatable, true)
		);
	}//end setUp()

	private function user(string $uid): ?IUser {
		if (in_array($uid, ['sales', 'trigger', 'disabled'], true) === false) {
			return null;
		}

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('isEnabled')->willReturn($uid !== 'disabled');
		return $user;
	}//end user()

	private function node(): TagObjectNode {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('imagePath')->willReturnCallback(static fn (string $app, string $image): string => "/{$app}/img/{$image}");

		return new TagObjectNode($this->objects, $this->tags, $this->schemas, $this->permissions, $this->users, new FlowNodePresentation($l10n, $urls));
	}//end node()

	/**
	 * @param list<array<string, mixed>> $jsons The item payloads.
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function items(array ...$jsons): array {
		return array_map(static fn (array $json): array => FlowItems::item(json: $json), $jsons);
	}//end items()

	private function fire(array $items, array $config, array $context = ['runAs' => 'sales']): array {
		return $this->node()->execute(new FlowNodeCall(items: $items, config: $config, context: $context));
	}//end fire()

	public function testAddPutsTheColouredTagOnEachItemsObjectAndPassesTheItemsThrough(): void {
		$items = self::items(['uuid' => 'lead-1', 'value' => 25000], ['uuid' => 'lead-2', 'value' => 30000]);
		$this->tags->method('hasObjectTag')->willReturn(false);
		$added = [];
		$this->tags->expects($this->exactly(2))->method('addObjectTag')->willReturnCallback(
			static function (string $objectUuid, string $tagName, ?string $color = null) use (&$added): void {
				$added[] = [$objectUuid, $tagName, $color];
			}
		);

		$out = $this->fire(items: $items, config: ['operation' => 'add', 'tag' => 'Large deal', 'color' => '#D94C3D']);

		$this->assertSame([['lead-1', 'Large deal', 'd94c3d'], ['lead-2', 'Large deal', 'd94c3d']], $added);
		$this->assertSame($items, $out);
		$this->assertSame(['sales'], $this->actedAs);
	}//end testAddPutsTheColouredTagOnEachItemsObjectAndPassesTheItemsThrough()

	public function testTheTagNameAndTheTargetAreRenderedFromTheItem(): void {
		$this->tags->method('hasObjectTag')->willReturn(false);
		$this->tags->expects($this->once())->method('addObjectTag')->with('lead-2', 'Stage won', null);

		$this->fire(
			items: self::items(['uuid' => 'quote-9', 'lead' => 'lead-2', 'stage' => 'won']),
			config: ['operation' => 'add', 'tag' => 'Stage {{stage}}', 'uuid' => '{{lead}}']
		);
	}//end testTheTagNameAndTheTargetAreRenderedFromTheItem()

	public function testTwoItemsOnOneObjectTagItOnce(): void {
		$this->tags->method('hasObjectTag')->willReturn(false);
		$this->tags->expects($this->once())->method('addObjectTag');

		$this->fire(items: self::items(['uuid' => 'lead-1'], ['uuid' => 'lead-1']), config: ['operation' => 'add', 'tag' => 'Large deal']);
	}//end testTwoItemsOnOneObjectTagItOnce()

	public function testAddingATagTheObjectAlreadyHasAssignsNothing(): void {
		$this->tags->method('hasObjectTag')->with('lead-1', 'Large deal')->willReturn(true);
		$this->tags->expects($this->never())->method('addObjectTag');

		$this->fire(items: self::items(['uuid' => 'lead-1']), config: ['operation' => 'add', 'tag' => 'Large deal', 'color' => 'd94c3d']);
	}//end testAddingATagTheObjectAlreadyHasAssignsNothing()

	public function testRemoveTakesTheTagOffWhenItIsThereAndNeverAdds(): void {
		$this->tags->method('hasObjectTag')->with('lead-1', 'Large deal')->willReturn(true);
		$this->tags->expects($this->once())->method('removeObjectTag')->with('lead-1', 'Large deal');
		$this->tags->expects($this->never())->method('addObjectTag');

		$out = $this->fire(items: self::items(['uuid' => 'lead-1']), config: ['operation' => 'remove', 'tag' => 'Large deal', 'createIfMissing' => false]);

		$this->assertCount(1, $out);
	}//end testRemoveTakesTheTagOffWhenItIsThereAndNeverAdds()

	public function testRemovingATagTheObjectLacksUnassignsNothing(): void {
		$this->tags->method('hasObjectTag')->with('lead-1', 'Large deal')->willReturn(false);
		$this->tags->expects($this->never())->method('removeObjectTag');

		$this->fire(items: self::items(['uuid' => 'lead-1']), config: ['operation' => 'remove', 'tag' => 'Large deal']);
	}//end testRemovingATagTheObjectLacksUnassignsNothing()

	public function testWithoutCreateIfMissingAMissingTagFailsTheStep(): void {
		$this->tags->method('hasObjectTag')->willReturn(false);
		$this->tags->method('getAllTags')->willReturn(['Large deal team']);
		$this->tags->expects($this->never())->method('addObjectTag');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('The tag "Large deal" does not exist, and this step may not create it.');

		$this->fire(items: self::items(['uuid' => 'lead-1']), config: ['operation' => 'add', 'tag' => 'Large deal', 'createIfMissing' => false]);
	}//end testWithoutCreateIfMissingAMissingTagFailsTheStep()

	public function testWithoutCreateIfMissingAnExistingTagIsAdded(): void {
		$this->tags->method('hasObjectTag')->willReturn(false);
		$this->tags->method('getAllTags')->willReturn(['Hot', 'Large deal']);
		$this->tags->expects($this->once())->method('addObjectTag')->with('lead-1', 'Large deal', null);

		$this->fire(items: self::items(['uuid' => 'lead-1']), config: ['operation' => 'add', 'tag' => 'Large deal', 'createIfMissing' => 'false']);
	}//end testWithoutCreateIfMissingAnExistingTagIsAdded()

	public function testAnIdentityThatMayReadButNotUpdateIsRefusedNamingTheObject(): void {
		$this->updatable = ['lead-1'];
		$this->tags->method('hasObjectTag')->willReturn(false);
		$this->tags->expects($this->once())->method('addObjectTag')->with('lead-1', 'Large deal', null);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('This flow run may not change object lead-3, so it cannot tag it.');

		$this->fire(items: self::items(['uuid' => 'lead-1'], ['uuid' => 'lead-3']), config: ['operation' => 'add', 'tag' => 'Large deal']);
	}//end testAnIdentityThatMayReadButNotUpdateIsRefusedNamingTheObject()

	public function testAnObjectTheIdentityCannotLoadIsRefused(): void {
		$this->tags->expects($this->never())->method('removeObjectTag');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('This flow run may not change object gone, so it cannot tag it.');

		$this->fire(items: self::items(['uuid' => 'gone']), config: ['operation' => 'remove', 'tag' => 'Large deal']);
	}//end testAnObjectTheIdentityCannotLoadIsRefused()

	public function testARunWithoutIdentityTagsNothing(): void {
		$this->objects->expects($this->never())->method('find');
		$this->tags->expects($this->never())->method('addObjectTag');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('This flow run acts as nobody');

		$this->fire(items: self::items(['uuid' => 'lead-1']), config: ['operation' => 'add', 'tag' => 'Large deal'], context: []);
	}//end testARunWithoutIdentityTagsNothing()

	public function testTheTriggeringUserIsTheIdentityWhenTheRunNamesNoOwner(): void {
		$this->tags->method('hasObjectTag')->willReturn(true);

		$this->fire(items: self::items(['uuid' => 'lead-1']), config: ['operation' => 'add', 'tag' => 'Large deal'], context: ['runAs' => '', 'triggeredBy' => 'trigger']);

		$this->assertSame(['trigger'], $this->actedAs);
	}//end testTheTriggeringUserIsTheIdentityWhenTheRunNamesNoOwner()

	public function testADisabledIdentityIsRefused(): void {
		$this->objects->expects($this->never())->method('runAs');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('This flow run acts as "disabled", which is not an enabled user account.');

		$this->fire(items: self::items(['uuid' => 'lead-1']), config: ['operation' => 'add', 'tag' => 'Large deal'], context: ['runAs' => 'disabled']);
	}//end testADisabledIdentityIsRefused()

	public function testAnItemWithoutObjectFailsTheStep(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Item 0 names no object to tag');

		$this->fire(items: self::items(['name' => 'no uuid']), config: ['operation' => 'add', 'tag' => 'Large deal']);
	}//end testAnItemWithoutObjectFailsTheStep()

	public function testAnEmptyFiringNeedsNoIdentity(): void {
		$this->objects->expects($this->never())->method('runAs');

		$this->assertSame([], $this->fire(items: [], config: [], context: []));
	}//end testAnEmptyFiringNeedsNoIdentity()

	/**
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public static function badConfigs(): array {
		return [
			'no operation' => [['tag' => 'x'], 'action must be "add" or "remove"'],
			'unknown operation' => [['operation' => 'toggle', 'tag' => 'x'], 'action must be "add" or "remove"'],
			'no tag' => [['operation' => 'add', 'tag' => '  '], 'needs the name of a tag'],
			'object not text' => [['operation' => 'add', 'tag' => 'x', 'uuid' => ['a']], 'object must be text'],
			'colour by name' => [['operation' => 'add', 'tag' => 'x', 'color' => 'red'], 'colour must be six hex digits'],
			'short colour' => [['operation' => 'add', 'tag' => 'x', 'color' => '#abc'], 'colour must be six hex digits'],
		];
	}//end badConfigs()

	#[\PHPUnit\Framework\Attributes\DataProvider('badConfigs')]
	public function testABadConfigurationIsRefused(array $config, string $message): void {
		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessage($message);

		$this->node()->validateConfig($config);
	}//end testABadConfigurationIsRefused()

	public function testItIsAnObjectServiceTaskInBothPalettes(): void {
		$node = $this->node();

		$this->assertSame('openregister.tag-object', $node->getId());
		$this->assertTrue($node->isAvailableForScope(IManager::SCOPE_ADMIN));
		$this->assertTrue($node->isAvailableForScope(IManager::SCOPE_USER));
		$this->assertSame(IFlowNodeTaxonomy::KIND_SERVICE_TASK, $node->getKind());
		$this->assertSame(IFlowNodeTaxonomy::CATEGORY_OBJECTS, $node->getCategory());
		$this->assertSame('/openregister/img/tag.svg', $node->getIcon());
		$this->assertSame(['operation', 'tag', 'color', 'uuid', 'createIfMissing'], array_column($node->configForm(), 'key'));
		$this->assertSame($node->configKeys(), array_column($node->configForm(), 'key'));
	}//end testItIsAnObjectServiceTaskInBothPalettes()
}//end class
