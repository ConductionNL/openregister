<?php

/**
 * Unit tests for SchemaVersioningService.
 *
 * Covers the breaking-change acknowledgement gate (409 on unacknowledged
 * breaking, pass on acknowledged, pass on compatible), the changelog
 * no-op on a metadata-only change, and the changelog recording shape.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Schema;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaChangelog;
use OCA\OpenRegister\Db\SchemaChangelogMapper;
use OCA\OpenRegister\Db\SchemaRunEntryMapper;
use OCA\OpenRegister\Db\SchemaRunMapper;
use OCA\OpenRegister\Exception\BreakingSchemaChangeException;
use OCA\OpenRegister\Service\Schema\SchemaChangeSet;
use OCA\OpenRegister\Service\Schema\SchemaDiffService;
use OCA\OpenRegister\Service\Schema\SchemaVersioningService;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SchemaVersioningServiceTest extends TestCase {
	private SchemaVersioningService $service;
	private SchemaChangelogMapper&MockObject $changelogMapper;
	private SchemaRunMapper&MockObject $runMapper;
	private SchemaRunEntryMapper&MockObject $runEntryMapper;

	protected function setUp(): void {
		parent::setUp();
		$this->changelogMapper = $this->createMock(SchemaChangelogMapper::class);
		$this->runMapper = $this->createMock(SchemaRunMapper::class);
		$this->runEntryMapper = $this->createMock(SchemaRunEntryMapper::class);
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);

		$this->service = new SchemaVersioningService(
			new SchemaDiffService(),
			$this->changelogMapper,
			$this->runMapper,
			$this->runEntryMapper,
			$userSession,
			$this->createMock(LoggerInterface::class)
		);
	}

	private function schema(array $properties, array $required = [], ?string $version = '1.0.0'): Schema {
		$schema = new Schema();
		$schema->hydrate([
			'properties' => $properties,
			'required' => $required,
			'version' => $version,
		]);
		return $schema;
	}

	public function testClassifyDetectsBreaking(): void {
		$existing = $this->schema(['email' => ['type' => 'string']]);
		$cs = $this->service->classify($existing, ['properties' => [], 'required' => []]);
		$this->assertTrue($cs->isBreaking());
	}

	public function testGateThrowsOnUnacknowledgedBreaking(): void {
		$this->runMapper->method('findBySchema')->willReturn([]);
		$cs = new SchemaChangeSet([['property' => 'email', 'kind' => 'removed']], SchemaChangeSet::CLASS_BREAKING, 'major');

		$this->expectException(BreakingSchemaChangeException::class);
		$this->service->enforceGate($cs, false, 1);
	}

	public function testGatePassesOnAcknowledgedBreaking(): void {
		$cs = new SchemaChangeSet([['property' => 'email', 'kind' => 'removed']], SchemaChangeSet::CLASS_BREAKING, 'major');
		$this->service->enforceGate($cs, true, 1);
		$this->addToAssertionCount(1);
	}

	public function testGatePassesOnCompatible(): void {
		$cs = new SchemaChangeSet([['property' => 'x', 'kind' => 'added']], SchemaChangeSet::CLASS_COMPATIBLE, 'minor');
		$this->service->enforceGate($cs, false, 1);
		$this->addToAssertionCount(1);
	}

	public function testGate409CarriesChangesAndInvalidCount(): void {
		$this->runMapper->method('findBySchema')->willReturn([]);
		$cs = new SchemaChangeSet([['property' => 'email', 'kind' => 'removed']], SchemaChangeSet::CLASS_BREAKING, 'major');

		try {
			$this->service->enforceGate($cs, false, 1);
			$this->fail('Expected BreakingSchemaChangeException');
		} catch (BreakingSchemaChangeException $e) {
			$body = $e->toResponse();
			$this->assertSame('breaking', $body['classification']);
			$this->assertNotEmpty($body['changes']);
		}
	}

	public function testRecordChangelogNoOpOnMetadataOnly(): void {
		$this->changelogMapper->expects($this->never())->method('createFromArray');
		$cs = new SchemaChangeSet([], SchemaChangeSet::CLASS_NONE, 'none');
		$this->assertNull($this->service->recordChangelog(1, '1.0.0', $cs, false));
	}

	public function testRecordChangelogPersistsBreakingWithAck(): void {
		$cs = new SchemaChangeSet([['property' => 'email', 'kind' => 'removed']], SchemaChangeSet::CLASS_BREAKING, 'major');

		$this->changelogMapper->expects($this->once())
			->method('createFromArray')
			->with($this->callback(function (array $data): bool {
				return $data['classification'] === 'breaking'
					&& $data['version'] === '2.0.0'
					&& array_key_exists('acknowledgedBy', $data);
			}))
			->willReturn(new SchemaChangelog());

		$this->service->recordChangelog(1, '2.0.0', $cs, true);
	}

	public function testNextVersionDelegatesToDiffService(): void {
		$existing = $this->schema(['name' => ['type' => 'string']], [], '1.2.0');
		$cs = new SchemaChangeSet([['property' => 'x', 'kind' => 'added']], SchemaChangeSet::CLASS_COMPATIBLE, 'minor');
		$this->assertSame('1.3.0', $this->service->nextVersion($existing, $cs));
	}
}
