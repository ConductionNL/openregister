<?php

/**
 * AuditTrailNode: the config refusals, the trail and single-entry shapes, the
 * access check that runs before the trail is read, and the failures that must
 * fail the step rather than complete it empty.
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
 * @spec openspec/changes/flow-parity-mapping-and-webhooks/specs/flow-rule-parity/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow\Nodes;

use JsonSerializable;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Flow\FlowItemOptions;
use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\FlowNodeCall;
use OCA\OpenRegister\Service\Flow\IFlowNodeTaxonomy;
use OCA\OpenRegister\Service\Flow\Nodes\AuditTrailNode;
use OCA\OpenRegister\Service\Flow\Nodes\FlowNodePresentation;
use OCP\AppFramework\Db\DoesNotExistException;
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
 * @covers \OCA\OpenRegister\Service\Flow\Nodes\AuditTrailNode
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Service\Flow\FlowItemShape
 * @uses \OCA\OpenRegister\Service\Flow\FlowItems
 * @uses \OCA\OpenRegister\Service\Flow\FlowItemOptions
 * @uses \OCA\OpenRegister\Service\Flow\FlowNodeCall
 * @uses \OCA\OpenRegister\Service\Flow\FlowValueRenderer
 * @uses \OCA\OpenRegister\Service\Flow\FlowValueTemplate
 * @uses \OCA\OpenRegister\Service\Flow\Nodes\FlowNodePresentation
 * @uses \OCA\OpenRegister\Service\Flow\Nodes\ObjectNodeBase
 */
class AuditTrailNodeTest extends TestCase {

	private const UUID = '0a1b2c3d-0000-4000-8000-000000000001';

	private ObjectOperationsInterface&MockObject $objects;

	private AuditTrailNode $node;

	/**
	 * Who each runAs() call ran as, in order.
	 *
	 * @var list<string>
	 */
	private array $actedAs = [];

	protected function setUp(): void {
		parent::setUp();
		$this->objects = $this->createMock(ObjectOperationsInterface::class);
		$this->objects->method('runAs')->willReturnCallback(
			function (IUser $user, callable $operation) {
				$this->actedAs[] = $user->getUID();
				return $operation();
			}
		);

		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(
			function (string $uid): ?IUser {
				if ($uid === 'nobody') {
					return null;
				}

				$user = $this->createMock(IUser::class);
				$user->method('getUID')->willReturn($uid);
				$user->method('isEnabled')->willReturn($uid !== 'gone');
				return $user;
			}
		);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('imagePath')->willReturnCallback(static fn (string $app, string $image): string => '/' . $app . '/img/' . $image);

		$this->node = new AuditTrailNode(
			objects: $this->objects,
			userManager: $users,
			presentation: new FlowNodePresentation(l10n: $l10n, urls: $urls)
		);
	}//end setUp()

	private function entity(): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid(self::UUID);
		return $entity;
	}//end entity()

	private function log(array $data): JsonSerializable {
		return new class($data) implements JsonSerializable {
			public function __construct(private array $data) {
			}

			public function jsonSerialize(): array {
				return $this->data;
			}
		};
	}//end log()

	private function call(array $config, array $json = ['id' => self::UUID], string $runAs = 'alice'): FlowNodeCall {
		return new FlowNodeCall(
			items: [FlowItems::item(json: $json, options: new FlowItemOptions(binary: ['file' => 'x']))],
			config: $config,
			context: ['runAs' => $runAs]
		);
	}//end call()

	public function testMetadataNamesTheParityNode(): void {
		$this->assertSame('openregister.audit-trail', $this->node->getId());
		$this->assertSame('Read audit trail', $this->node->getDisplayName());
		$this->assertSame('/core/img/actions/history.svg', $this->node->getIcon());
		$this->assertSame(['object', 'entry', 'output'], $this->node->configKeys());
		$this->assertSame(IFlowNodeTaxonomy::KIND_SERVICE_TASK, $this->node->getKind());
		$this->assertTrue($this->node->isAvailableForScope(IManager::SCOPE_USER));
	}

	public function testAStepNamingNoObjectIsRefusedAtSave(): void {
		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessage('An audit-trail step needs an object.');
		$this->node->validateConfig(['object' => '  ']);
	}

	public function testANonTextEntryIsRefusedAtSave(): void {
		$this->expectException(UnexpectedValueException::class);
		$this->node->validateConfig(['object' => '{{id}}', 'entry' => ['x']]);
	}

	public function testTheTrailLandsOnTheItemReadAsTheActingIdentity(): void {
		$this->objects->expects($this->once())->method('find')->with(self::UUID)->willReturn($this->entity());
		$this->objects->expects($this->once())->method('getLogs')->with(self::UUID, [])
			->willReturn([$this->log(['uuid' => 'e1', 'action' => 'create']), $this->log(['uuid' => 'e2', 'action' => 'update'])]);

		$out = $this->node->execute($this->call(['object' => '{{id}}']));

		$this->assertCount(1, $out);
		$this->assertSame(
			[['uuid' => 'e1', 'action' => 'create'], ['uuid' => 'e2', 'action' => 'update']],
			$out[0][FlowItems::JSON]['auditTrail']
		);
		$this->assertSame(self::UUID, $out[0][FlowItems::JSON]['id'], 'the item keeps its own fields');
		$this->assertSame(['file' => 'x'], $out[0][FlowItems::BINARY]);
		$this->assertSame(['alice'], $this->actedAs);
	}

	public function testOneEntryIsReturnedUnderTheNamedOutput(): void {
		$this->objects->method('find')->willReturn($this->entity());
		$this->objects->expects($this->once())->method('getLogs')->with(self::UUID, ['uuid' => 'e2'])
			->willReturn([$this->log(['uuid' => 'e2', 'action' => 'update'])]);

		$out = $this->node->execute($this->call(['object' => '{{id}}', 'entry' => '{{entry}}', 'output' => 'history'], ['id' => self::UUID, 'entry' => 'e2']));

		$this->assertSame(['uuid' => 'e2', 'action' => 'update'], $out[0][FlowItems::JSON]['history']);
		$this->assertArrayNotHasKey('auditTrail', $out[0][FlowItems::JSON]);
	}

	public function testAMissingEntryFailsTheStepInsteadOfCompletingEmpty(): void {
		$this->objects->method('find')->willReturn($this->entity());
		$this->objects->method('getLogs')->willReturn([]);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Object "' . self::UUID . '" has no audit-trail entry "e9".');
		$this->node->execute($this->call(['object' => '{{id}}', 'entry' => 'e9']));
	}

	public function testAnObjectTheIdentityCannotReadFailsAndItsTrailIsNeverRead(): void {
		$this->objects->method('find')->willThrowException(new DoesNotExistException('Object not found'));
		$this->objects->expects($this->never())->method('getLogs');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('The audit-trail step could not read object "' . self::UUID . '": Object not found');
		$this->node->execute($this->call(['object' => '{{id}}']));
	}

	public function testAFindReturningNullFailsAndTheTrailIsNeverRead(): void {
		$this->objects->method('find')->willReturn(null);
		$this->objects->expects($this->never())->method('getLogs');

		$this->expectException(RuntimeException::class);
		$this->node->execute($this->call(['object' => '{{id}}']));
	}

	public function testAnObjectTemplateThatRendersEmptyFailsBeforeAnyRead(): void {
		$this->objects->expects($this->never())->method('find');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage("The audit-trail step's object could not be rendered from this item.");
		$this->node->execute($this->call(['object' => '{{missing}}']));
	}

	public function testARunWithoutAnActingIdentityIsRefused(): void {
		$this->objects->expects($this->never())->method('find');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('no acting identity');
		$this->node->execute($this->call(['object' => '{{id}}'], runAs: ''));
	}

	public function testADisabledOrUnknownIdentityIsRefused(): void {
		$this->objects->expects($this->never())->method('find');

		foreach (['gone', 'nobody'] as $uid) {
			try {
				$this->node->execute($this->call(['object' => '{{id}}'], runAs: $uid));
				$this->fail('the read ran as ' . $uid);
			} catch (RuntimeException $e) {
				$this->assertStringContainsString('"' . $uid . '"', $e->getMessage());
			}
		}
	}

	public function testAnEmptyBranchReadsNothing(): void {
		$this->objects->expects($this->never())->method('runAs');

		$this->assertSame([], $this->node->execute(new FlowNodeCall(items: [], config: [], context: [])));
	}
}
