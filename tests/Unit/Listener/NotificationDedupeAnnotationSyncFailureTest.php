<?php

/**
 * Characterisation tests for NotificationDedupeAnnotationSyncListener's skip paths.
 *
 * Pins that a schema without an id is ignored without touching the mapper, that
 * a failure while pruning is swallowed and logged at debug level with the
 * reason, and that the prune stops at the first failing delete.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Listener;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- PHPUnit arrange/act/assert conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions and entity setters.

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Event\SchemaCreatedEvent;
use OCA\OpenRegister\Notifications\Db\NotificationDedupeStateMapper;
use OCA\OpenRegister\Notifications\Listener\NotificationDedupeAnnotationSyncListener;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class NotificationDedupeAnnotationSyncFailureTest extends TestCase {

	private static function schema(int $id, array $configuration): Schema {
		$schema = new Schema();
		$schema->setId($id);
		$schema->setConfiguration($configuration);
		return $schema;
	}

	public function testASchemaWithoutAnIdIsIgnored(): void {
		$mapper = $this->createMock(NotificationDedupeStateMapper::class);
		$mapper->expects($this->never())->method('listRuleKeysForSchema');

		(new NotificationDedupeAnnotationSyncListener($mapper, new NullLogger()))
			->handle(new SchemaCreatedEvent(self::schema(id: 0, configuration: [])));
	}

	public function testANonMapNotificationsBlockPrunesEveryTrackedRule(): void {
		$mapper = $this->createMock(NotificationDedupeStateMapper::class);
		$mapper->method('listRuleKeysForSchema')->willReturn(['a', 'b']);
		$deleted = [];
		$mapper->method('deleteByRule')->willReturnCallback(function (int $schemaId, string $ruleKey) use (&$deleted): int {
			$deleted[] = $schemaId . ':' . $ruleKey;
			return 1;
		});

		(new NotificationDedupeAnnotationSyncListener($mapper, new NullLogger()))
			->handle(new SchemaCreatedEvent(self::schema(id: 3, configuration: ['x-openregister-notifications' => 'oops'])));

		$this->assertSame(['3:a', '3:b'], $deleted);
	}

	public function testAFailingPruneIsLoggedAtDebugAndStopsThere(): void {
		$mapper = $this->createMock(NotificationDedupeStateMapper::class);
		$mapper->method('listRuleKeysForSchema')->willReturn(['a', 'b', 'c']);
		$calls = [];
		$mapper->method('deleteByRule')->willReturnCallback(function (int $schemaId, string $ruleKey) use (&$calls): int {
			$calls[] = $ruleKey;
			throw new \RuntimeException('locked');
		});
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('debug')
			->with('[NotificationDedupeAnnotationSyncListener] sync skipped: locked');

		(new NotificationDedupeAnnotationSyncListener($mapper, $logger))
			->handle(new SchemaCreatedEvent(self::schema(id: 3, configuration: ['x-openregister-notifications' => ['a' => []]])));

		$this->assertSame(['b'], $calls);
	}
}
