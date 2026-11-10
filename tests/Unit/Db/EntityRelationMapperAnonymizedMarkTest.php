<?php

/**
 * Marking relations anonymized leaves the rows a person chose to skip untouched.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Db
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/specs/entity-relation-grondslagen/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\EntityRelationMapper;
use OCA\OpenRegister\Service\Registers\EntityEventPublisher;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IDBConnection;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Db\EntityRelationMapper
 */
class EntityRelationMapperAnonymizedMarkTest extends TestCase {
	use RecordingQueryBuilderTrait;

	private function mapper(): EntityRelationMapper {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn () => $this->recordingBuilder());
		return new EntityRelationMapper(
			$db,
			$this->createMock(AuditTrailMapperInterface::class),
			$this->createMock(IUserSession::class),
			new EntityEventPublisher(dispatcher: $this->createMock(IEventDispatcher::class)),
			$this->createMock(LoggerInterface::class)
		);
	}

	public function testEveryUpdateLeavesSkippedRowsAlone(): void {
		$this->mapper()->markAsAnonymizedWithPlaceholders(3, ['7' => '[PERSOON: 1]', '8' => '[PERSOON: 2]']);

		$log = implode("\n", $this->queryLog);
		$this->assertSame(2, substr_count($log, 'executeStatement()'), 'one update per entity');
		foreach (['#1', '#2'] as $builder) {
			$found = preg_match('/^' . $builder . ' expr\.eq\(skip_anonymization, (:p\d+)/m', $log, $match);
			$this->assertSame(1, $found, 'the update of ' . $builder . ' excludes skipped rows');
			$this->assertMatchesRegularExpression('/^' . $builder . ' param ' . $match[1] . ' = false\b/m', $log, 'only rows not marked skip');
		}
	}

	public function testTheEntityAndFileBoundTheUpdate(): void {
		$this->mapper()->markAsAnonymizedWithPlaceholders(3, ['7' => '[PERSOON: 1]']);

		$log = implode("\n", $this->queryLog);
		$this->assertStringContainsString('expr.eq(file_id,', $log);
		$this->assertStringContainsString('expr.eq(entity_id,', $log);
	}
}
