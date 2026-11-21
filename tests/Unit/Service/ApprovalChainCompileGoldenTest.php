<?php

/**
 * ApprovalChainCompileGoldenTest
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
use OCA\OpenRegister\Event\SchemaCreatedEvent;
use OCA\OpenRegister\Event\SchemaUpdatedEvent;
use OCA\OpenRegister\Service\ApprovalChainAnnotationInstaller;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Golden: compiling declared approval chains into task templates, and the
 * schema-save listener that reports chains whose gate will fail closed.
 *
 * Every refused outcome (no schema id, no chains, an undeclared or non-array
 * chain, no usable approver, an unknown tiers mode) compiles to null; the
 * allowed outcome is pinned field for field, including key order.
 */
class ApprovalChainCompileGoldenTest extends TestCase {

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

	private function schema(?int $id, mixed $chains): Schema {
		$schema = new Schema();
		if ($id !== null) {
			$schema->setId($id);
		}

		$configuration = ['other' => true];
		if ($chains !== 'absent') {
			$configuration['x-openregister-approval-chains'] = $chains;
		}

		$schema->setConfiguration($configuration);
		return $schema;
	}

	private static function chains(): array {
		return [
			'full' => [
				'transition' => 'approve',
				'separationOfDuties' => false,
				'onApprove' => 'approved',
				'amountField' => 'amount',
				'tiers' => 'cumulative',
				'approvingOutcomes' => [' Approve ', '', 3, 'OK'],
				'approvers' => [
					'skip-me',
					['role' => '  '],
					['role' => ' manager ', 'min' => 2, 'minAmount' => 100, 'statusOnApprove' => 'ok', 'statusOnReject' => 'no', 'other' => 'x'],
					['role' => 'director', 'minAmount' => null],
				],
			],
			'defaults' => ['approvers' => [['role' => 'clerk']]],
			'noApprovers' => ['approvers' => [['role' => '']]],
			'badTiers' => ['tiers' => 'lowest', 'approvers' => [['role' => 'clerk']]],
			'scalar' => 'nope',
			7 => ['approvers' => [['role' => 'clerk']]],
		];
	}

	public function testCompileOutcomes(): void {
		$installer = $this->installer();
		$results = [];
		$schema = $this->schema(12, self::chains());
		foreach (['full', 'defaults', 'noApprovers', 'badTiers', 'scalar', 'missing'] as $key) {
			$results[$key] = $installer->compile($schema, $key);
		}

		$results['noId'] = $installer->compile($this->schema(null, self::chains()), 'full');
		$results['noChains'] = $installer->compile($this->schema(12, 'absent'), 'full');
		$results['chainsNotArray'] = $installer->compile($this->schema(12, 'x'), 'full');

		$this->assertSame(
			[
				'full' => [
					'templateId' => $installer->templateIdFor(12, 'full'),
					'templateVersion' => 1,
					'name' => 'full',
					'schemaId' => 12,
					'transition' => 'approve',
					'separationOfDuties' => false,
					'onApprove' => 'approved',
					'amountField' => 'amount',
					'tiers' => 'cumulative',
					'positions' => [
						['order' => 1, 'role' => 'manager', 'min' => 2, 'minAmount' => 100, 'statusOnApprove' => 'ok', 'statusOnReject' => 'no'],
						['order' => 2, 'role' => 'director', 'minAmount' => null],
					],
					'approvingOutcomes' => ['approve', 'ok'],
				],
				'defaults' => [
					'templateId' => $installer->templateIdFor(12, 'defaults'),
					'templateVersion' => 1,
					'name' => 'defaults',
					'schemaId' => 12,
					'transition' => '',
					'separationOfDuties' => true,
					'onApprove' => '',
					'amountField' => '',
					'tiers' => 'highest',
					'positions' => [['order' => 1, 'role' => 'clerk']],
				],
				'noApprovers' => null,
				'badTiers' => null,
				'scalar' => null,
				'missing' => null,
				'noId' => null,
				'noChains' => null,
				'chainsNotArray' => null,
			],
			$results
		);
		$this->assertSame(
			[['error', '[ApprovalChainAnnotationInstaller] Unknown tiers mode; the chain is not compiled.', ['chain' => 'badTiers', 'tiers' => 'lowest']]],
			$this->log
		);
	}

	public function testHandleReportsEveryChainThatFailsClosed(): void {
		$installer = $this->installer();
		$installer->handle(new SchemaCreatedEvent($this->schema(12, self::chains())));
		$installer->handle(new SchemaUpdatedEvent($this->schema(13, ['only' => ['approvers' => []]]), $this->schema(13, [])));
		$installer->handle(new SchemaCreatedEvent($this->schema(14, 'absent')));
		$installer->handle(new Event());

		$this->assertSame(
			[
				['warning', '[ApprovalChainAnnotationInstaller] chain "noApprovers" on schema 12 declares no usable approver; its gate will refuse the transition as misconfigured.', []],
				['error', '[ApprovalChainAnnotationInstaller] Unknown tiers mode; the chain is not compiled.', ['chain' => 'badTiers', 'tiers' => 'lowest']],
				['warning', '[ApprovalChainAnnotationInstaller] chain "badTiers" on schema 12 declares no usable approver; its gate will refuse the transition as misconfigured.', []],
				['warning', '[ApprovalChainAnnotationInstaller] chain "scalar" on schema 12 declares no usable approver; its gate will refuse the transition as misconfigured.', []],
				['warning', '[ApprovalChainAnnotationInstaller] chain "only" on schema 13 declares no usable approver; its gate will refuse the transition as misconfigured.', []],
			],
			$this->log
		);
	}

	public function testTemplateIdIsStable(): void {
		$this->assertSame(
			sprintf('%s-%s-%s-%s-%s', ...self::uuidParts(md5('or-approval-template:12:full'))),
			$this->installer()->templateIdFor(12, 'full')
		);
	}

	private static function uuidParts(string $hash): array {
		return [substr($hash, 0, 8), substr($hash, 8, 4), substr($hash, 12, 4), substr($hash, 16, 4), substr($hash, 20, 12)];
	}
}//end class
