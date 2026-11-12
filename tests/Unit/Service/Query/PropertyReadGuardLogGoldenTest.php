<?php

/**
 * Golden test for what PropertyReadGuard logs when it fails closed.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Query
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/row-field-level-security/spec.md#requirement-a-property-read-rule-holds-on-every-route-that-returns-its-value
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Query;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Query\PropertyReadGuard;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The refused reads and their warnings, pinned.
 */
class PropertyReadGuardLogGoldenTest extends TestCase {

	/**
	 * Every warning, without the file and line.
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	private array $warnings = [];

	/**
	 * A logger recording warnings.
	 *
	 * @return LoggerInterface The logger.
	 */
	private function logger(): LoggerInterface {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context): void {
				$this->assertSame(['file', 'line'], array_slice(array_keys($context), 0, 2));
				unset($context['file'], $context['line']);
				$this->warnings[] = [$message, $context];
			}
		);

		return $logger;
	}//end logger()

	public function testEachFailureIsRefusedAndSaysWhy(): void {
		$schema = new Schema();
		$schema->setProperties(['salary' => ['type' => 'number', 'authorization' => ['read' => ['hr']]]]);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new \RuntimeException('no handler'));

		$this->assertFalse((new PropertyReadGuard(container: null, logger: $this->logger()))->mayRead(schema: $schema, property: 'salary'));
		$this->assertFalse((new PropertyReadGuard(container: $container, logger: $this->logger()))->mayRead(schema: $schema, property: 'salary'));

		$this->assertSame(
			[
				['[PropertyReadGuard] No container to resolve the property read rule; treating the property as unreadable', ['property' => 'salary']],
				['[PropertyReadGuard] Could not check the property read rule; treating the property as unreadable', ['property' => 'salary', 'exception' => 'no handler']],
			],
			$this->warnings
		);
	}//end testEachFailureIsRefusedAndSaysWhy()
}//end class
