<?php

/**
 * Pins the published shape of the rule refusals: export and destruction.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Exception
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/specs/authorization-rbac/spec.md
 */

declare(strict_types=1);

namespace Unit\Exception;

use OCA\OpenRegister\Service\Deletion\DestructionRefusedException;
use OCA\OpenRegister\Service\Export\ExportRefusedException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RuleRefusedExceptionTest extends TestCase {
	public function testExportRefusalPublishesVerbRuleMessageThenContext(): void {
		$previous = new RuntimeException('cause');
		$refusal = new ExportRefusedException(
			rule: 'export-right-missing',
			reason: 'You may not export this register.',
			statusCode: 409,
			context: ['evaluated' => 'read', 'rule' => 'overridden'],
			previous: $previous
		);

		self::assertSame(
			[
				'error' => 'EXPORT_REFUSED',
				'verb' => 'export',
				'rule' => 'overridden',
				'message' => 'You may not export this register.',
				'evaluated' => 'read',
			],
			$refusal->toResponseBody()
		);
		self::assertSame('export-right-missing', $refusal->getRule());
		self::assertSame(409, $refusal->getStatusCode());
		self::assertSame(409, $refusal->getCode());
		self::assertSame('You may not export this register.', $refusal->getMessage());
		self::assertSame($previous, $refusal->getPrevious());
	}//end testExportRefusalPublishesVerbRuleMessageThenContext()

	public function testDestructionRefusalDefaultsTo403WithoutContext(): void {
		$refusal = new DestructionRefusedException(rule: 'destroy-right-missing', reason: 'Not yours to destroy.');

		self::assertSame(
			['error' => 'DESTRUCTION_REFUSED', 'rule' => 'destroy-right-missing', 'message' => 'Not yours to destroy.'],
			$refusal->toResponseBody()
		);
		self::assertSame(403, $refusal->getStatusCode());
		self::assertSame(403, $refusal->getCode());
		self::assertNull($refusal->getPrevious());
	}//end testDestructionRefusalDefaultsTo403WithoutContext()
}//end class
