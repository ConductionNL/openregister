<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Repair;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Repair\RepairSharedPrincipalLists;
use OCA\OpenRegister\Service\Credential\CredentialBrokerService;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Sharing\SharePrincipalDeriver;
use OCA\OpenRegister\Service\Sharing\SharePrincipalDeriverInterface;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * The repair step reads every credential as the system and rewrites only stale ones.
 *
 * @covers \OCA\OpenRegister\Repair\RepairSharedPrincipalLists
 * @uses   \OCA\OpenRegister\Service\Sharing\SharePrincipalRepair
 * @uses   \OCA\OpenRegister\Service\Sharing\SharePrincipalDeriver
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget
 */
final class RepairSharedPrincipalListsTest extends TestCase {

	public function testStaleCredentialsAreRewrittenAsTheSystemInTheCredentialSchema(): void {
		$stale = new ObjectEntity();
		$stale->setUuid('c1');
		$stale->setObject(['sharedWith' => [['type' => 'user', 'id' => 'alice']], 'sharedUsers' => ['mallory']]);
		$current = new ObjectEntity();
		$current->setUuid('c2');
		$current->setObject(['sharedUsers' => [], 'sharedGroups' => []]);

		$objects = $this->getMockBuilder(ObjectService::class)->disableOriginalConstructor()
			->onlyMethods(['setRegister', 'setSchema', 'findAll', 'saveObject'])->getMock();
		$objects->expects($this->once())->method('setRegister')->with(CredentialBrokerService::REGISTER)->willReturnSelf();
		$objects->expects($this->once())->method('setSchema')->with(CredentialBrokerService::SCHEMA)->willReturnSelf();
		$objects->expects($this->once())->method('findAll')->with(['limit' => 200, 'offset' => 0], false, false)->willReturn([$stale, $current]);
		$objects->expects($this->once())->method('saveObject')->willReturnCallback(
			function (array $data, ObjectSaveOptions $options, bool $rbac, bool $multitenancy) use ($stale): ObjectEntity {
				$this->assertSame(['alice'], $data['sharedUsers']);
				$this->assertSame('c1', $options->uuid);
				$this->assertSame(CredentialBrokerService::SCHEMA, $options->target->schema);
				$this->assertFalse($rbac);
				$this->assertFalse($multitenancy);
				return $stale;
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnMap(
			[
				[ObjectOperationsInterface::class, $objects],
				[SharePrincipalDeriverInterface::class, new SharePrincipalDeriver()],
			]
		);
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info')->with('Shared credential lists: 2 checked, 1 recomputed.');
		$output->expects($this->never())->method('warning');

		(new RepairSharedPrincipalLists($container))->run($output);
	}

	public function testWithoutTheObjectServiceNothingIsChecked(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('not installed'));
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info')->with('Shared credential lists not checked: not installed');

		$step = new RepairSharedPrincipalLists($container);
		$this->assertSame('Recompute the derived share lists of brokered credentials', $step->getName());
		$step->run($output);
	}
}
