<?php

/**
 * ObjectWriteBulk: a rejected bulk page fails naming the first reason a rejection gives, or none when no rejection says why.
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

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\FlowNodeCall;
use OCA\OpenRegister\Service\Flow\Nodes\FlowNodePresentation;
use OCA\OpenRegister\Service\Flow\Nodes\ObjectWriteNode;
use OCA\OpenRegister\Service\Flow\Nodes\ObjectWritePlatform;
use OCA\OpenRegister\Service\Flow\Nodes\ObjectWriteStores;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\Flow\Nodes\ObjectWriteBulk
 * @uses \OCA\OpenRegister\Db\Register
 * @uses \OCA\OpenRegister\Db\Register\RegisterSchemaIds
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Service\Flow\FlowItemShape
 * @uses \OCA\OpenRegister\Service\Flow\FlowItems
 * @uses \OCA\OpenRegister\Service\Flow\Nodes\FlowNodePresentation
 * @uses \OCA\OpenRegister\Service\Flow\Nodes\ObjectWriteBulkPage
 * @uses \OCA\OpenRegister\Service\Flow\Nodes\ObjectWriteConfigValidator
 * @uses \OCA\OpenRegister\Service\Flow\Nodes\ObjectWriteNode
 * @uses \OCA\OpenRegister\Service\Flow\Nodes\ObjectWriteOptions
 * @uses \OCA\OpenRegister\Service\Flow\Nodes\ObjectWritePayload
 * @uses \OCA\OpenRegister\Service\Flow\Nodes\ObjectWritePlan
 * @uses \OCA\OpenRegister\Service\Flow\Nodes\ObjectWritePlatform
 * @uses \OCA\OpenRegister\Service\Flow\Nodes\ObjectWriteStores
 * @uses \OCA\OpenRegister\Service\Flow\Nodes\ObjectWriteTemplate
 */
class ObjectWriteBulkRejectionBehaviourTest extends TestCase {

	/**
	 * @param array<string, mixed> $answer What saveObjects() answers.
	 */
	private function node(array $answer): ObjectWriteNode {
		$register = new Register();
		$register->setId(1);
		$register->setSlug('cases');
		$register->setSchemas([2]);
		$schema = new Schema();
		$schema->setId(2);
		$schema->setSlug('case');
		$registers = $this->createMock(RegisterMapperInterface::class);
		$registers->method('find')->willReturn($register);
		$schemas = $this->createMock(SchemaMapperInterface::class);
		$schemas->method('findBySlugInIds')->willReturn($schema);

		$objects = $this->createMock(ObjectOperationsInterface::class);
		$objects->method('runAs')->willReturnCallback(static fn (IUser $user, callable $operation) => $operation());
		$objects->expects(self::once())->method('saveObjects')->willReturn($answer);

		$alice = $this->createMock(IUser::class);
		$alice->method('getUID')->willReturn('alice');
		$alice->method('isEnabled')->willReturn(true);
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn($alice);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')->willReturnCallback(static fn (string $app, string $key, int $default = 0): int => $default);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));

		return new ObjectWriteNode(
			stores: new ObjectWriteStores(objects: $objects, registers: $registers, schemas: $schemas),
			platform: new ObjectWritePlatform(userManager: $users, appConfig: $appConfig),
			presentation: new FlowNodePresentation(l10n: $l10n, urls: $this->createMock(IURLGenerator::class))
		);
	}//end node()

	private function write(ObjectWriteNode $node): void {
		$node->execute(new FlowNodeCall(
			items: [FlowItems::item(json: ['name' => 'a']), FlowItems::item(json: ['name' => 'b'])],
			config: ['register' => 'cases', 'schema' => 'case', 'operation' => 'create', 'bulk' => true, 'fields' => ['title' => '{{ name }}']],
			context: ['runAs' => 'alice']
		));
	}//end write()

	public function testTheFirstRejectionThatSaysWhyIsNamed(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('The bulk write rejected 3 of its rows (first reason: title is required); accepted rows were written and were not rolled back.');

		$this->write($this->node(['invalid' => ['not-an-entry', ['error' => '  '], ['error' => ' title is required ']], 'errors' => [['error' => 'later']]]));
	}//end testTheFirstRejectionThatSaysWhyIsNamed()

	public function testRejectionsThatSayNothingLeaveTheReasonEmpty(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('The bulk write rejected 1 of its rows (first reason: ); accepted rows were written and were not rolled back.');

		$this->write($this->node(['invalid' => [['object' => ['title' => 'a']]]]));
	}//end testRejectionsThatSayNothingLeaveTheReasonEmpty()
}//end class
