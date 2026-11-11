<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

use OCA\OpenRegister\Service\Rbac\RecordMetadataConditions;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins the refusal of a stored clause on unknown metadata and its once-per-schema-and-key log.
 */
final class RecordMetadataConditionsLogOnceGoldenTest extends TestCase {

	/**
	 * A known key is allowed and logs nothing.
	 *
	 * @return void
	 */
	public function testAKnownKeyIsAllowedAndLogsNothing(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('error');

		$conditions = new RecordMetadataConditions(logger: $logger);

		$this->assertFalse($conditions->refuses(match: ['@self.owner' => 'bob', 'title' => 'x']));
	}//end testAKnownKeyIsAllowedAndLogsNothing()

	/**
	 * Unknown keys refuse every time, but each schema and key pair is logged once, in clause order.
	 *
	 * @return void
	 */
	public function testAnUnknownKeyRefusesAndLogsOncePerSchemaAndKey(): void {
		$calls  = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $message, array $context) use (&$calls): void {
				$calls[] = [$message, $context['schema'], $context['key']];
			}
		);

		$conditions = new RecordMetadataConditions(logger: $logger);
		$clause     = ['_nothing' => 1, '@self.nowhere' => 2];
		$objectA    = ['@self' => ['schema' => 'a']];
		$objectB    = ['@self' => ['schema' => ['id' => 'b']]];

		$this->assertTrue($conditions->refuses(match: $clause, object: $objectA));
		$this->assertTrue($conditions->refuses(match: $clause, object: $objectA));
		$this->assertTrue($conditions->refuses(match: $clause, object: $objectB));
		$this->assertTrue($conditions->refuses(match: ['_nothing' => 1]));

		$message = static fn (string $key): string => '[RecordMetadataConditions] A stored rule has a condition on '.$key
			.', which no record carries; the rule fails closed (an allow grants nothing, a deny denies)';
		$this->assertSame(
			[
				[$message('_nothing'), 'a', '_nothing'],
				[$message('@self.nowhere'), 'a', '@self.nowhere'],
				[$message('_nothing'), 'b', '_nothing'],
				[$message('@self.nowhere'), 'b', '@self.nowhere'],
				[$message('_nothing'), '', '_nothing'],
			],
			$calls
		);
	}//end testAnUnknownKeyRefusesAndLogsOncePerSchemaAndKey()

	/**
	 * Without a logger a refusal still refuses.
	 *
	 * @return void
	 */
	public function testWithoutALoggerAnUnknownKeyStillRefuses(): void {
		$this->assertTrue((new RecordMetadataConditions())->refuses(match: ['_nothing' => 1]));
	}//end testWithoutALoggerAnUnknownKeyStillRefuses()
}//end class
