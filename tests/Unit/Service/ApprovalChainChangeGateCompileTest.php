<?php

/**
 * ApprovalChainChangeGateCompileTest
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Service
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\ApprovalChainAnnotationInstaller;
use OCA\OpenRegister\Service\Task\ApprovingOutcomeSet;
use OCA\OpenRegister\Service\Task\ChangeGateDeclaration;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Compiling a chain that gates a change instead of a transition: the gate is
 * carried onto the template, and a malformed gate or one combined with a
 * transition fails closed.
 *
 * @covers \OCA\OpenRegister\Service\ApprovalChainAnnotationInstaller
 * @uses   \OCA\OpenRegister\Service\Task\ChangeGateDeclaration
 * @uses   \OCA\OpenRegister\Service\Task\ApprovingOutcomeSet
 */
class ApprovalChainChangeGateCompileTest extends TestCase {

	/**
	 * Logged calls.
	 *
	 * @var array<int, mixed>
	 */
	private array $log = [];

	private function installer(): ApprovalChainAnnotationInstaller {
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->log[] = [$level, $message, $context];
				}
			);
		}

		return new ApprovalChainAnnotationInstaller($logger);
	}

	private function schema(array $chains): Schema {
		$schema = new Schema();
		$schema->setId(21);
		$schema->setConfiguration(['x-openregister-approval-chains' => $chains]);

		return $schema;
	}

	public function testAChangeGateIsCompiledOntoTheTemplate(): void {
		$installer = $this->installer();
		$schema = $this->schema(
			[
				'bankAccount' => [
					'gate' => ['change' => ['properties' => ['iban'], 'on' => ['update', 'create'], 'exempt' => ['sync']]],
					'approvers' => [['role' => 'financieel-beheer']],
				],
			]
		);

		$this->assertSame(
			[
				'templateId' => $installer->templateIdFor(21, 'bankAccount'),
				'templateVersion' => 1,
				'name' => 'bankAccount',
				'schemaId' => 21,
				'transition' => '',
				'separationOfDuties' => true,
				'onApprove' => '',
				'amountField' => '',
				'tiers' => 'highest',
				'positions' => [['order' => 1, 'role' => 'financieel-beheer']],
				'changeGate' => ['properties' => ['iban'], 'on' => ['create', 'update'], 'exempt' => ['sync']],
			],
			$installer->compile($schema, 'bankAccount')
		);
		$this->assertSame([], $this->log);
	}

	public function testAChainGatingBothATransitionAndAChangeFailsClosed(): void {
		$installer = $this->installer();
		$schema = $this->schema(
			['both' => ['transition' => 'approve', 'gate' => ['change' => []], 'approvers' => [['role' => 'clerk']]]]
		);

		$this->assertNull($installer->compile($schema, 'both'));
		$this->assertSame(
			[['error', '[ApprovalChainAnnotationInstaller] A chain gates either a transition or a change, not both; the chain is not compiled.', ['chain' => 'both']]],
			$this->log
		);
	}

	public function testAMalformedChangeGateFailsClosed(): void {
		$installer = $this->installer();
		$schema = $this->schema(
			['bad' => ['gate' => ['change' => ['on' => ['delete']]], 'approvers' => [['role' => 'clerk']]]]
		);

		$this->assertNull($installer->compile($schema, 'bad'));
		$this->assertSame(
			[['error', '[ApprovalChainAnnotationInstaller] Malformed change gate; the chain is not compiled.', ['chain' => 'bad']]],
			$this->log
		);
	}

	public function testATransitionChainWithoutAChangeGateIsUnchanged(): void {
		$template = $this->installer()->compile(
			$this->schema(['plain' => ['transition' => 'approve', 'gate' => ['other' => true], 'approvers' => [['role' => 'clerk']]]]),
			'plain'
		);

		$this->assertSame('approve', $template['transition']);
		$this->assertArrayNotHasKey('changeGate', $template);
		$this->assertSame([], $this->log);
	}
}//end class
