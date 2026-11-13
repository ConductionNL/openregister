<?php

/**
 * A schema or register edit writes one row with the changed paths.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/history-schema-and-settings-edits-audited/design.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Audit;

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Audit\EntityEditAuditor;
use OCA\OpenRegister\Service\WriteCause;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\Audit\EntityEditAuditor
 * @uses   \OCA\OpenRegister\Service\Rbac\AuditRowWriter
 * @uses   \OCA\OpenRegister\Service\WriteCause
 * @uses   \OCA\OpenRegister\Db\AuditTrail
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Db\Register
 */
final class EntityEditAuditorTest extends TestCase {

	/**
	 * The rows the audit trail received.
	 *
	 * @var array<int, AuditTrail>
	 */
	private array $written = [];

	protected function tearDown(): void {
		WriteCause::reset();
	}

	/**
	 * The auditor over a recording audit trail, acting as `beheerder`.
	 *
	 * @param LoggerInterface|null $logger  The logger.
	 * @param bool                 $failing Whether the trail refuses the write.
	 *
	 * @return EntityEditAuditor
	 */
	private function auditor(?LoggerInterface $logger=null, bool $failing=false): EntityEditAuditor {
		$mapper = $this->createMock(AuditTrailMapperInterface::class);
		$mapper->method('insertAuditTrails')->willReturnCallback(
			function (array $entries) use ($failing): array {
				if ($failing === true) {
					throw new RuntimeException('database gone');
				}

				array_push($this->written, ...$entries);
				return $entries;
			}
		);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('beheerder');
		$user->method('getDisplayName')->willReturn('Beheerder');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new EntityEditAuditor($mapper, $session, $logger ?? $this->createMock(LoggerInterface::class));
	}

	/**
	 * A schema `meldingen`.
	 *
	 * @param array<string, mixed> $properties    The properties.
	 * @param array<string, mixed> $authorization The rules.
	 * @param string               $version       The version.
	 *
	 * @return Schema
	 */
	private static function schema(array $properties, array $authorization=[], string $version='1.0.0'): Schema {
		$schema = new Schema();
		$schema->setId(12);
		$schema->setUuid('00000000-0000-4000-8000-00000000000c');
		$schema->setSlug('meldingen');
		$schema->setTitle('Meldingen');
		$schema->setVersion($version);
		$schema->setOrganisation('org-1');
		$schema->setProperties($properties);
		$schema->setAuthorization($authorization);
		return $schema;
	}

	/**
	 * The fields of the only written row.
	 *
	 * @return array<int, array{path: string, old: mixed, new: mixed}>
	 */
	private function onlyFields(): array {
		$this->assertCount(1, $this->written);
		return $this->written[0]->getChanged()['fields'];
	}

	public function testANarrowedMaxLengthIsOnePathWithBothValues(): void {
		$old = self::schema(['omschrijving' => ['type' => 'string', 'maxLength' => 500]]);
		$new = self::schema(['omschrijving' => ['type' => 'string', 'maxLength' => 200]], [], '1.1.0');
		$this->assertSame(1, $this->auditor()->recordUpdated($old, $new));
		$this->assertSame([['path' => 'properties.omschrijving.maxLength', 'old' => 500, 'new' => 200]], $this->onlyFields());
		$row = $this->written[0];
		$this->assertSame('schema.updated', $row->getAction());
		$this->assertSame(12, $row->getSchema());
		$this->assertSame('00000000-0000-4000-8000-00000000000c', $row->getSchemaUuid());
		$this->assertSame('org-1', $row->getOrganisationId());
		$this->assertSame('beheerder', $row->getUser());
		$this->assertSame('person', $row->getCause());
		$this->assertSame(['slug' => 'meldingen', 'versionBefore' => '1.0.0', 'versionAfter' => '1.1.0'], array_diff_key($row->getChanged(), ['fields' => true]));
	}

	public function testAWidenedReadRuleIsRecordedPerKey(): void {
		$old = self::schema(['a' => ['type' => 'string']], ['read' => ['staff']]);
		$new = self::schema(['a' => ['type' => 'string']], ['read' => ['staff', 'public']]);
		$this->auditor()->recordUpdated($old, $new);
		$this->assertSame([['path' => 'authorization.read', 'old' => ['staff'], 'new' => ['staff', 'public']]], $this->onlyFields());
	}

	public function testANoOpSaveWritesNothing(): void {
		$old = self::schema(['a' => ['type' => 'string', 'maxLength' => 5]]);
		$new = self::schema(['a' => ['maxLength' => '5', 'type' => 'string']]);
		$this->assertSame(0, $this->auditor()->recordUpdated($old, $new));
		$this->assertSame([], $this->written);
	}

	public function testAnAddedAndARemovedPropertyAreOneEntryEach(): void {
		$old = self::schema(['weg' => ['type' => 'string']]);
		$new = self::schema(['nieuw' => ['type' => 'integer']]);
		$this->auditor()->recordUpdated($old, $new);
		$this->assertSame(
			[
				['path' => 'properties.weg', 'old' => ['type' => 'string'], 'new' => null],
				['path' => 'properties.nieuw', 'old' => null, 'new' => ['type' => 'integer']],
			],
			$this->onlyFields()
		);
	}

	public function testALargeEnumIsStoredAsHashAndLength(): void {
		$codes = array_map(fn (int $i): string => sprintf('code-%04d', $i), range(1, 500));
		$old = self::schema(['code' => ['type' => 'string']]);
		$new = self::schema(['code' => ['type' => 'string', 'enum' => $codes]]);
		$this->auditor()->recordUpdated($old, $new);
		$json = (string)json_encode($codes);
		$this->assertGreaterThan(EntityEditAuditor::MAX_VALUE_BYTES, strlen($json));
		$this->assertSame([['path' => 'properties.code.enum', 'old' => null, 'new' => ['sha256' => hash('sha256', $json), 'length' => strlen($json)]]], $this->onlyFields());
	}

	public function testACredentialIsMaskedOnBothSidesAtAnyDepth(): void {
		$fields = $this->auditor()->diff(
			['configuration' => ['source' => ['url' => 'https://x', 'clientSecret' => 'old-secret']], 'apiKey' => 'k1'],
			['configuration' => ['source' => ['url' => 'https://x', 'clientSecret' => 'new-secret']], 'apiKey' => 'k2']
		);
		$this->assertSame(
			[
				['path' => 'configuration.source', 'old' => ['url' => 'https://x', 'clientSecret' => '********'], 'new' => ['url' => 'https://x', 'clientSecret' => '********']],
				['path' => 'apiKey', 'old' => '********', 'new' => '********'],
			],
			$fields
		);
		$this->assertStringNotContainsString('-secret', (string)json_encode($fields));
	}

	public function testAFailingTrailReturnsZeroAndLogsAnError(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error');
		$this->assertSame(0, $this->auditor($logger, true)->recordCreated(self::schema(['a' => []])));
	}

	public function testACreateNamesTheProperties(): void {
		$this->auditor()->recordCreated(self::schema(['a' => [], 'b' => []]));
		$this->assertSame('schema.created', $this->written[0]->getAction());
		$this->assertSame(['slug' => 'meldingen', 'title' => 'Meldingen', 'version' => '1.0.0', 'properties' => ['a', 'b']], $this->written[0]->getChanged());
	}

	public function testARegisterDeleteKeepsOnlyTheHashOfItsDefinition(): void {
		$register = new Register();
		$register->setId(3);
		$register->setUuid('00000000-0000-4000-8000-000000000003');
		$register->setSlug('zaken');
		$register->setTitle('Zaken');
		$register->setVersion('2.0.0');
		$this->auditor()->recordDeleted($register);
		$row = $this->written[0];
		$this->assertSame('register.deleted', $row->getAction());
		$this->assertSame(3, $row->getRegister());
		$this->assertNull($row->getSchema());
		$this->assertSame(
			['slug' => 'zaken', 'title' => 'Zaken', 'version' => '2.0.0', 'propertyCount' => 0, 'sha256' => hash('sha256', (string)json_encode($register->jsonSerialize()))],
			$row->getChanged()
		);
	}

	public function testDerivedFieldsAreSkipped(): void {
		$auditor = $this->auditor();
		$this->assertSame([], $auditor->diff(['updated' => '2026-01-01', 'facets' => ['a'], 'created' => 'x'], ['updated' => '2026-02-01', 'facets' => ['b'], 'created' => 'y']));
		$this->assertSame([['path' => 'title', 'old' => 'A', 'new' => 'B']], $auditor->diff(['title' => 'A', 'updated' => '1'], ['title' => 'B', 'updated' => '2']));
	}
}
