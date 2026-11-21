<?php

/**
 * Unit test: the node-rights repair step adds what the matrix lacks and changes nothing else.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-powerful-steps-need-a-right/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Repair;

use OCA\OpenRegister\Repair\InitializeFlowNodeRights;
use OCA\OpenRegister\Service\Flow\FlowNodeRightsGuardInterface;
use OCA\OpenRegister\Service\OpenRegisterActionAuthService;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * An administrator's narrowing survives the upgrade; a new right arrives.
 *
 * @covers \OCA\OpenRegister\Repair\InitializeFlowNodeRights
 * @uses   \OCA\OpenRegister\AppHost\Service\GenericActionAuthService
 * @uses   \OCA\OpenRegister\Service\OpenRegisterActionAuthService
 */
final class InitializeFlowNodeRightsTest extends TestCase {

	/**
	 * The in-memory app config store.
	 *
	 * @var array<string, string>
	 */
	private array $store = [];

	/**
	 * How many times the matrix was written.
	 *
	 * @var int
	 */
	private int $writes = 0;

	/**
	 * The node rights the registered steps declare.
	 *
	 * @var FlowNodeRightsGuardInterface&MockObject
	 */
	private FlowNodeRightsGuardInterface&MockObject $nodeRights;

	/**
	 * Build the node rights double.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = [];
		$this->writes = 0;
		$this->nodeRights = $this->createMock(FlowNodeRightsGuardInterface::class);
	}//end setUp()

	/**
	 * A REAL matrix over an in-memory app config.
	 *
	 * @return OpenRegisterActionAuthService
	 */
	private function matrix(): OpenRegisterActionAuthService {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->store[$app.'/'.$key] ?? $default)
		);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->store[$app.'/'.$key] = $value;
				$this->writes++;
				return true;
			}
		);

		return new OpenRegisterActionAuthService($appConfig, $this->createMock(IGroupManager::class));
	}//end matrix()

	/**
	 * An administrator's narrowed entry is kept and a declared right is added as admin-only.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-powerful-steps-need-a-right/specs/flow-engine/spec.md#requirement-the-action-rights-are-administered-and-published
	 */
	public function testADeclaredRightIsAddedWithoutTouchingTheAdministratorsChoice(): void {
		$this->store['openregister/actions'] = (string)json_encode(
			['flow.create' => ['flow-authors'], 'flow.node.send-email' => ['builders']]
		);
		$this->nodeRights->method('nodeRights')->willReturn(
			[
				'flow.node.send-email' => ['description' => 'Send e-mail.', 'nodeTypes' => ['openregister.send-email']],
				'flow.node.object-delete' => ['description' => 'Delete objects.', 'nodeTypes' => ['openregister.object-write']],
			]
		);
		$matrix = $this->matrix();

		(new InitializeFlowNodeRights(nodeRights: $this->nodeRights, actionAuth: $matrix))->run($this->createMock(IOutput::class));

		$this->assertSame(
			[
				'flow.create' => ['flow-authors'],
				'flow.node.send-email' => ['builders'],
				'flow.node.object-delete' => ['admin'],
			],
			$matrix->getMatrix()
		);
	}//end testADeclaredRightIsAddedWithoutTouchingTheAdministratorsChoice()

	/**
	 * A matrix that already holds every declared right is left exactly as it was.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-powerful-steps-need-a-right/specs/flow-engine/spec.md#requirement-the-action-rights-are-administered-and-published
	 */
	public function testAMatrixWithEveryRightIsNotRewritten(): void {
		$stored = (string)json_encode(['flow.node.send-email' => ['builders']]);
		$this->store['openregister/actions'] = $stored;
		$this->nodeRights->method('nodeRights')->willReturn(
			['flow.node.send-email' => ['description' => 'Send e-mail.', 'nodeTypes' => ['openregister.send-email']]]
		);

		(new InitializeFlowNodeRights(nodeRights: $this->nodeRights, actionAuth: $this->matrix()))->run($this->createMock(IOutput::class));

		// NOT written at all, rather than rewritten to the same bytes: a
		// re-encode would read as untouched here while still being a write
		// over whatever an administrator stored in a shape this does not keep.
		$this->assertSame(0, $this->writes, 'a matrix that lacks nothing is not written');
		$this->assertSame($stored, $this->store['openregister/actions']);
	}//end testAMatrixWithEveryRightIsNotRewritten()

	/**
	 * A registry that throws warns and leaves the matrix alone, rather than blocking the upgrade.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-powerful-steps-need-a-right/specs/flow-engine/spec.md#requirement-the-action-rights-are-administered-and-published
	 */
	public function testAFailingRegistryWarnsAndChangesNothing(): void {
		$this->store['openregister/actions'] = (string)json_encode(['flow.create' => ['@authenticated']]);
		$this->nodeRights->method('nodeRights')->willThrowException(new RuntimeException('an app blew up'));

		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('warning')->with($this->stringContains('an app blew up'));
		$output->expects($this->never())->method('info');

		(new InitializeFlowNodeRights(nodeRights: $this->nodeRights, actionAuth: $this->matrix()))->run($output);

		$this->assertSame(0, $this->writes, 'nothing is written when the registry throws');
		$this->assertSame((string)json_encode(['flow.create' => ['@authenticated']]), $this->store['openregister/actions']);
	}//end testAFailingRegistryWarnsAndChangesNothing()
}//end class
