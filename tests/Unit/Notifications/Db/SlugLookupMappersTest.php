<?php

/**
 * Unit tests for ReferencePatternMapper and TimelineKindMapper: slug lookup, enabled filter and scoping.
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

use OCA\OpenRegister\Notifications\Db\ReferencePatternMapper;
use OCA\OpenRegister\Notifications\Db\TimelineKindMapper;
use OCA\OpenRegister\Tests\Unit\Db\RecordingQueryBuilderTrait;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Notifications\Db\ReferencePatternMapper
 * @covers \OCA\OpenRegister\Notifications\Db\TimelineKindMapper
 * @covers \OCA\OpenRegister\Notifications\Db\NotificationQueryTrait
 * @uses   \OCA\OpenRegister\Notifications\Db\ReferencePattern
 * @uses   \OCA\OpenRegister\Notifications\Db\TimelineKind
 */
final class SlugLookupMappersTest extends TestCase {
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

	public function testReferencePatternFindBySlug(): void {
		$this->queuedRows = [[['id' => 3, 'slug' => 'zaak']], []];
		$mapper = new ReferencePatternMapper($this->database());

		$this->assertSame(3, $mapper->findBySlug('zaak')?->getId());
		$this->assertNull($mapper->findBySlug('none'));
		$this->assertStringContainsString('where(eq(slug, :p1, NULL))', $this->log());
	}

	public function testReferencePatternFindAllFiltersEnabledOnlyWhenAsked(): void {
		$this->queuedRows = [[['id' => 1, 'slug' => 'a']]];
		$mapper = new ReferencePatternMapper($this->database());

		$this->assertCount(1, $mapper->findAll());
		$this->assertStringNotContainsString('eq(enabled', $this->log());
		$this->assertStringContainsString('orderBy(slug, ASC)', $this->log());

		$this->queuedRows = [[]];
		$mapper = new ReferencePatternMapper($this->database());
		$this->assertSame([], $mapper->findAll(true));
		$log = $this->log();
		$this->assertStringContainsString('param :p1 = true, boolean', $log);
		$this->assertStringContainsString('andWhere(eq(enabled, :p1, NULL))', $log);
	}

	public function testTimelineKindFindBySlug(): void {
		$this->queuedRows = [[['id' => 4, 'slug' => 'note']], []];
		$mapper = new TimelineKindMapper($this->database());

		$this->assertSame(4, $mapper->findBySlug('note')?->getId());
		$this->assertNull($mapper->findBySlug('none'));
	}

	public function testTimelineKindFindAllScopesOnlyGivenDimensions(): void {
		$this->queuedRows = [[]];
		$mapper = new TimelineKindMapper($this->database());

		$mapper->findAll();
		$this->assertStringNotContainsString('orX(', $this->log());

		$this->queuedRows = [[]];
		$mapper = new TimelineKindMapper($this->database());
		$mapper->findAll(null, 'sch');
		$log = $this->log();
		$this->assertStringNotContainsString('isNull(register)', $log);
		$this->assertStringContainsString('orX(isNull(schema), eq(schema, :p1, NULL))', $log);
	}
}
