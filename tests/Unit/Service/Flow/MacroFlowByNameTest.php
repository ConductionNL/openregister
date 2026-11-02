<?php

/**
 * Unit tests for a macro that names a flow its schema declares by name.
 *
 * A shipped register cannot carry a flow's uuid: the declared flow is stored
 * on import under a uuid minted per installation. So the binding names the
 * declared flow, the save checks the declaration, and a run resolves the name
 * to the stored flow.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Flow\FlowService;
use OCA\OpenRegister\Service\Flow\FlowTriggerDerivation;
use OCA\OpenRegister\Service\Flow\MacroActionBinding;
use OCA\OpenRegister\Service\Flow\MacroActionResolver;
use OCA\OpenRegister\Service\Flow\MacroActionValidator;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class MacroFlowByNameTest extends TestCase {

	/**
	 * A case schema configuration declaring one macro bound to a declared flow by name.
	 *
	 * @param array $nodes The declared flow's nodes.
	 *
	 * @return array The configuration.
	 */
	private function configuration(array $nodes): array {
		return [
			'x-openregister-action' => [
				'suspend' => ['name' => 'Opschorten voor aanvulling', 'macro' => true, 'flow' => 'Opschorten voor aanvulling'],
			],
			'x-openregister-flows' => [
				['name' => 'Opschorten voor aanvulling', 'app' => 'dossiq', 'nodes' => $nodes],
			],
		];
	}//end configuration()

	/**
	 * A validator whose store holds no flow by that id.
	 *
	 * @return MacroActionValidator
	 */
	private function validator(): MacroActionValidator {
		$flows = $this->createMock(FlowMapper::class);
		$flows->method('findByUuid')->willThrowException(new DoesNotExistException('none'));
		return new MacroActionValidator($flows, new FlowTriggerDerivation());
	}//end validator()

	public function testADeclaredFlowWithAManualTriggerIsAccepted(): void {
		$refusals = $this->validator()->refusals($this->configuration([
			['id' => 'start', 'type' => 'openregister.trigger-manual', 'config' => []],
		]));
		$this->assertSame([], $refusals);
	}//end testADeclaredFlowWithAManualTriggerIsAccepted()

	public function testADeclaredFlowWithoutAManualTriggerIsRefused(): void {
		$refusals = $this->validator()->refusals($this->configuration([
			['id' => 'start', 'type' => 'openregister.trigger-object', 'config' => []],
		]));
		$this->assertCount(1, $refusals);
		$this->assertStringContainsString('no manual trigger', $refusals[0]);
	}//end testADeclaredFlowWithoutAManualTriggerIsRefused()

	public function testANameNoFlowAnswersToIsStillRefused(): void {
		$configuration = $this->configuration([]);
		$configuration['x-openregister-action']['suspend']['flow'] = 'Something else';
		$refusals = $this->validator()->refusals($configuration);
		$this->assertCount(1, $refusals);
		$this->assertStringContainsString('does not exist', $refusals[0]);
	}//end testANameNoFlowAnswersToIsStillRefused()

	public function testARunResolvesTheNameToTheStoredFlow(): void {
		$stored = new Flow();
		$stored->setUuid('0f6a1b2c-0000-4000-8000-000000000001');

		$store = $this->createMock(FlowMapper::class);
		$store->expects($this->once())
			->method('findDeclared')
			->with('Opschorten voor aanvulling', 'case')
			->willReturn($stored);

		$schema = new Schema();
		$schema->setSlug('case');
		$schema->setConfiguration($this->configuration([]));

		$resolver = new MacroActionResolver(
			$this->createMock(SchemaMapper::class),
			$this->createMock(FlowService::class),
			$this->createMock(AuditTrailMapper::class),
			$this->createMock(LoggerInterface::class),
			$store
		);

		$this->assertSame('0f6a1b2c-0000-4000-8000-000000000001', $resolver->bindingFor(schema: $schema, action: 'suspend')?->flow);
	}//end testARunResolvesTheNameToTheStoredFlow()

	public function testAUuidIsNotLookedUpByName(): void {
		$this->assertTrue(MacroActionBinding::namesUuid(flow: '0f6a1b2c-0000-4000-8000-000000000001'));
		$this->assertFalse(MacroActionBinding::namesUuid(flow: 'Opschorten voor aanvulling'));
	}//end testAUuidIsNotLookedUpByName()
}//end class
