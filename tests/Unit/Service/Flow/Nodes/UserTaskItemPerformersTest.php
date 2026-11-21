<?php

/**
 * Unit tests for UserTaskItemPerformers.
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
 *
 * @spec openspec/changes/platform-hr-workflows
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow\Nodes;

use OCA\OpenRegister\Service\Flow\FlowItemOptions;
use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\FlowItemShape;
use OCA\OpenRegister\Service\Flow\FlowValueRenderer;
use OCA\OpenRegister\Service\Flow\FlowValueTemplate;
use OCA\OpenRegister\Service\Flow\Nodes\UserTaskItemPerformers;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * A performer can be named through a field of the item.
 */
#[CoversClass(UserTaskItemPerformers::class)]
#[UsesClass(FlowItems::class)]
#[UsesClass(FlowItemOptions::class)]
#[UsesClass(FlowItemShape::class)]
#[UsesClass(FlowValueTemplate::class)]
#[UsesClass(FlowValueRenderer::class)]
class UserTaskItemPerformersTest extends TestCase {

	/**
	 * One item carrying the case employee as a read gives it.
	 *
	 * @return array<int, mixed>
	 */
	private static function items(): array {
		return [FlowItems::item(json: ['id' => 'onb-1', 'employee' => [['managerUserId' => 'hanneke', 'deputyUserIds' => ['piet', '']]]])];
	}//end items()

	/**
	 * The assignee placeholder resolves to the manager on the item.
	 *
	 * @return void
	 */
	public function testAnAssigneeFieldResolvesToTheItemsValue(): void {
		$config = UserTaskItemPerformers::resolve(
			config: ['title' => 'Werkplek regelen voor {{ id }}', 'assignee' => '{{ employee.0.managerUserId }}', 'candidateGroups' => ['admin']],
			items: self::items()
		);

		$this->assertSame('hanneke', $config['assignee']);
		$this->assertSame(['admin'], $config['candidateGroups'], 'a fixed performer is left as it is');
		$this->assertSame('Werkplek regelen voor {{ id }}', $config['title'], 'only performer keys are touched');
	}//end testAnAssigneeFieldResolvesToTheItemsValue()

	/**
	 * A typed reference and a candidate list resolve too; an empty entry drops out of the list.
	 *
	 * @return void
	 */
	public function testTypedReferencesAndListsResolve(): void {
		$config = UserTaskItemPerformers::resolve(
			config: [
				'assignee' => ['type' => 'user', 'id' => '{{ employee.0.managerUserId }}'],
				'candidateUsers' => ['{{ employee.0.deputyUserIds.0 }}', '{{ employee.0.deputyUserIds.1 }}', '{{ missing }}'],
			],
			items: self::items()
		);

		$this->assertSame(['type' => 'user', 'id' => 'hanneke'], $config['assignee']);
		$this->assertSame(['piet'], $config['candidateUsers']);
	}//end testTypedReferencesAndListsResolve()

	/**
	 * A placeholder the item does not carry leaves that source empty.
	 *
	 * @return void
	 */
	public function testAMissingFieldLeavesTheSourceEmpty(): void {
		$config = UserTaskItemPerformers::resolve(
			config: ['assignee' => '{{ employee.0.nobody }}', 'routingFallback' => 'group:hr'],
			items: self::items()
		);

		$this->assertSame('', $config['assignee']);
		$this->assertSame('group:hr', $config['routingFallback']);
	}//end testAMissingFieldLeavesTheSourceEmpty()

	/**
	 * A configuration without placeholders, or without items, is returned unchanged.
	 *
	 * @return void
	 */
	public function testAConfigWithoutPlaceholdersIsUnchanged(): void {
		$config = ['title' => 'Approve', 'assignee' => 'alice', 'candidateGroups' => ['admin'], 'candidateRole' => 'hr'];

		$this->assertSame($config, UserTaskItemPerformers::resolve(config: $config, items: self::items()));
		$this->assertSame(['assignee' => ''], UserTaskItemPerformers::resolve(config: ['assignee' => '{{ x }}'], items: []));
	}//end testAConfigWithoutPlaceholdersIsUnchanged()
}//end class
