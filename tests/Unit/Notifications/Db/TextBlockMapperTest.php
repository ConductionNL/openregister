<?php

/**
 * Unit tests for TextBlockMapper: slug lookup and register/schema/group scoping.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Notifications\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Notifications\Db;

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

use OCA\OpenRegister\Notifications\Db\TextBlockMapper;
use OCA\OpenRegister\Notifications\Db\TextBlockScope;
use OCA\OpenRegister\Tests\Unit\Db\RecordingQueryBuilderTrait;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Notifications\Db\TextBlockMapper
 * @covers \OCA\OpenRegister\Notifications\Db\NotificationQueryTrait
 * @uses   \OCA\OpenRegister\Notifications\Db\TextBlock
 */
final class TextBlockMapperTest extends TestCase {
	use RecordingQueryBuilderTrait;

	private function database(): IDBConnection {
		$this->queryLog = [];
		$this->builderCount = 0;
		$this->parameterCount = 0;
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn () => $this->recordingBuilder());
		return $db;
	}

	private function log(): string {
		return implode("\n", $this->queryLog);
	}

	public function testFindBySlugReturnsTheBlock(): void {
		$this->queuedRows = [[['id' => 1, 'slug' => 'greeting', 'title' => 'Hi']]];
		$mapper = new TextBlockMapper($this->database());

		$this->assertSame('Hi', $mapper->findBySlug('greeting')?->getTitle());
		$this->assertStringContainsString('where(eq(slug, :p1, NULL))', $this->log());
	}

	public function testFindBySlugReturnsNullWhenMissing(): void {
		$this->queuedRows = [[]];
		$this->assertNull((new TextBlockMapper($this->database()))->findBySlug('none'));
	}

	public function testFindInScopeWithoutScopeOrGroupsOnlyReturnsGlobalBlocks(): void {
		$this->queuedRows = [[]];
		$mapper = new TextBlockMapper($this->database());

		$this->assertSame([], $mapper->findInScope(new TextBlockScope()));
		$log = $this->log();
		$this->assertStringContainsString('orderBy(title, ASC)', $log);
		$this->assertStringContainsString('andWhere(isNull(group_id))', $log);
		$this->assertStringNotContainsString('isNull(register)', $log);
		$this->assertStringNotContainsString('isNull(schema)', $log);
		$this->assertStringNotContainsString('in(group_id', $log);
	}

	public function testFindInScopeAddsScopedOrUnscopedClausesAndGroupMembership(): void {
		$this->queuedRows = [[['id' => 1, 'slug' => 'a'], ['id' => 2, 'slug' => 'b']]];
		$mapper = new TextBlockMapper($this->database());

		$rows = $mapper->findInScope(new TextBlockScope('reg', 'sch', ['admin', 'staff']));

		$this->assertCount(2, $rows);
		$log = $this->log();
		$this->assertStringContainsString('param :p1 = reg', $log);
		$this->assertStringContainsString('orX(isNull(register), eq(register, :p1, NULL))', $log);
		$this->assertStringContainsString('orX(isNull(schema), eq(schema, :p2, NULL))', $log);
		$this->assertStringContainsString('param :p3 = ["admin","staff"]', $log);
		$this->assertStringContainsString('in(group_id, :p3', $log);
	}
}
