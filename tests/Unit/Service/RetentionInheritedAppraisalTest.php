<?php

/**
 * RetentionService: a permanent appraisal inherited along declared references.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaCatalog;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Archival\ArchiveActionDateCalculator;
use OCA\OpenRegister\Service\Archival\InheritedAppraisal;
use OCA\OpenRegister\Service\Archival\RetentionRowScanner;
use OCA\OpenRegister\Service\Archival\RetentionScanStores;
use OCA\OpenRegister\Service\Archival\SelectielijstResolver;
use OCA\OpenRegister\Service\DisposalSweep;
use OCA\OpenRegister\Service\RetentionRules;
use OCA\OpenRegister\Service\RetentionService;
use OCA\OpenRegister\Service\RetentionStores;
use OCA\OpenRegister\Service\Settings\ObjectRetentionHandler;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Real ObjectEntity and Schema instances; the mappers answer from in-memory maps.
 *
 * @covers \OCA\OpenRegister\Service\Archival\InheritedAppraisal
 * @covers \OCA\OpenRegister\Service\RetentionService
 * @uses \OCA\OpenRegister\Db\MagicMapper\StatisticsScope
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Db\SchemaCatalog
 * @uses \OCA\OpenRegister\Db\Schema\ConfigurationEntryRules
 * @uses \OCA\OpenRegister\Db\Schema\ConfigurationValidator
 * @uses \OCA\OpenRegister\Kernel\Contracts\ListQuery
 * @uses \OCA\OpenRegister\Service\Archival\ArchiveActionDateCalculator
 * @uses \OCA\OpenRegister\Service\Archival\RetentionRowScanner
 * @uses \OCA\OpenRegister\Service\Archival\RetentionScanStores
 * @uses \OCA\OpenRegister\Service\DisposalSweep
 * @uses \OCA\OpenRegister\Service\RetentionRules
 * @uses \OCA\OpenRegister\Service\RetentionStores
 */
class RetentionInheritedAppraisalTest extends TestCase {

	/**
	 * Objects by uuid.
	 *
	 * @var array<string, ObjectEntity>
	 */
	private array $objects = [];

	/**
	 * Schemas by id.
	 *
	 * @var array<string, Schema>
	 */
	private array $schemas = [];

	/**
	 * The uuids read through the finder, in order.
	 *
	 * @var list<string>
	 */
	private array $reads = [];

	private MagicMapper&MockObject $objectMapper;

	private SchemaMapper&MockObject $schemaMapper;

	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		$this->objectMapper = $this->createMock(MagicMapper::class);
		$this->objectMapper->method('findAcrossAllSources')->willReturnCallback(
			function (string|int $identifier, bool $includeDeleted=false, bool $_rbac=true, bool $_multitenancy=true): array {
				$this->reads[] = (string) $identifier;
				$this->assertFalse($_rbac, 'ancestors are read as the system');
				$this->assertFalse($_multitenancy, 'ancestors are read as the system');
				if (isset($this->objects[(string) $identifier]) === false) {
					throw new DoesNotExistException('gone');
				}

				return ['object' => $this->objects[(string) $identifier], 'register' => null, 'schema' => null];
			}
		);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->schemaMapper->method('find')->willReturnCallback(
			fn (string|int $id): Schema => ($this->schemas[(string) $id] ?? throw new DoesNotExistException('no schema'))
		);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->schema('publication', ['x-openregister-retention' => ['inheritAppraisalFrom' => ['subjects']]]);
		$this->schema('subject', []);
	}

	private function service(bool $inheritance=true): RetentionService {
		$catalog   = new SchemaCatalog(registers: $this->createMock(RegisterMapper::class), schemas: $this->schemaMapper, tables: $this->objectMapper);
		$inherited = null;
		if ($inheritance === true) {
			$inherited = new InheritedAppraisal($this->objectMapper, $this->schemaMapper, $this->logger);
		}

		return new RetentionService(
			new RetentionStores($catalog, $this->createMock(AuditTrailMapper::class)),
			new RetentionRules(
				$this->createMock(ObjectRetentionHandler::class),
				new RetentionRowScanner(new RetentionScanStores(db: $this->createMock(IDBConnection::class), catalog: $catalog), $this->logger),
				new ArchiveActionDateCalculator($this->objectMapper, $this->logger),
				$this->createMock(SelectielijstResolver::class)
			),
			$this->createMock(IUserSession::class),
			$this->logger,
			$inherited,
		);
	}

	private function schema(string $id, array $configuration): void {
		$schema = new Schema();
		$schema->setConfiguration($configuration);
		$this->schemas[$id] = $schema;
	}

	private function object(string $uuid, string $schema, string $appraisal, array $data=[]): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setSchema($schema);
		$object->setObject($data);
		$object->setRetention(['archiefnominatie' => $appraisal, 'archiefactiedatum' => '2020-01-01']);
		$this->objects[$uuid] = $object;
		return $object;
	}

	private function refusal(ObjectEntity $object, bool $inheritance=true): ?string {
		return $this->service($inheritance)->destructionRefusalIn($object, new DisposalSweep(today: '2026-11-13', excludeUuids: []));
	}

	public function testAPublicationUnderAHotspotIsNotEligible(): void {
		$this->object('subject-1', 'subject', 'blijvend_bewaren');
		$publication = $this->object('pub-1', 'publication', 'vernietigen', ['subjects' => ['subject-1']]);

		$this->assertSame('inherited_permanent_appraisal:subject-1', $this->refusal($publication));
	}

	public function testWithoutTheDeclarationOnlyTheOwnAppraisalCounts(): void {
		$this->schema('publication', []);
		$this->object('subject-1', 'subject', 'blijvend_bewaren');
		$publication = $this->object('pub-1', 'publication', 'vernietigen', ['subjects' => ['subject-1']]);

		$this->assertNull($this->refusal($publication));
		$this->assertSame([], $this->reads);
	}

	public function testWithoutTheRuleWiredTheOwnAppraisalCounts(): void {
		$this->object('subject-1', 'subject', 'blijvend_bewaren');

		$this->assertNull($this->refusal($this->object('pub-1', 'publication', 'vernietigen', ['subjects' => ['subject-1']]), false));
	}

	public function testADestroyableAncestorLeavesTheObjectEligible(): void {
		$this->object('subject-1', 'subject', 'vernietigen');
		$publication = $this->object('pub-1', 'publication', 'vernietigen', ['subjects' => [['id' => 'subject-1']]]);

		$this->assertNull($this->refusal($publication));
		$this->assertSame(['subject-1'], $this->reads);
	}

	public function testAGrandparentFurtherUpIsFollowed(): void {
		$this->schema('subject', ['x-openregister-retention' => ['inheritAppraisalFrom' => ['parent']]]);
		$this->object('theme-1', 'subject', 'retain_permanently');
		$this->object('subject-1', 'subject', 'vernietigen', ['parent' => ['@self' => ['id' => 'theme-1']]]);

		$this->assertSame(
			'inherited_permanent_appraisal:theme-1',
			$this->refusal($this->object('pub-1', 'publication', 'vernietigen', ['subjects' => 'subject-1']))
		);
	}

	public function testOnlyFiveLevelsAreFollowed(): void {
		$this->schema('subject', ['x-openregister-retention' => ['inheritAppraisalFrom' => ['parent']]]);
		$this->object('s6', 'subject', 'blijvend_bewaren');
		for ($level = 5; $level >= 1; $level--) {
			$this->object('s'.$level, 'subject', 'vernietigen', ['parent' => 's'.($level + 1)]);
		}

		$this->assertNull($this->refusal($this->object('pub-1', 'publication', 'vernietigen', ['subjects' => ['s1']])));
		$this->assertSame(['s1', 's2', 's3', 's4', 's5'], $this->reads);

		$this->object('s5', 'subject', 'blijvend_bewaren');
		$this->assertSame('inherited_permanent_appraisal:s5', $this->refusal($this->objects['pub-1']));
	}

	public function testACycleIsVisitedOnce(): void {
		$this->schema('subject', ['x-openregister-retention' => ['inheritAppraisalFrom' => ['related']]]);
		$this->object('a', 'subject', 'vernietigen', ['related' => ['b', 'pub-1']]);
		$this->object('b', 'subject', 'vernietigen', ['related' => ['a']]);

		$this->assertNull($this->refusal($this->object('pub-1', 'publication', 'vernietigen', ['subjects' => ['a', 'a']])));
		$this->assertSame(['a', 'b'], $this->reads);
	}

	public function testAnUnresolvableAncestorHoldsTheObjectBack(): void {
		$publication = $this->object('pub-1', 'publication', 'vernietigen', ['subjects' => ['deleted-subject']]);

		$this->assertSame('unresolved_ancestor:subjects:deleted-subject', $this->refusal($publication));
	}

	public function testTheOwnRulesStillComeFirst(): void {
		$this->object('subject-1', 'subject', 'blijvend_bewaren');
		$publication = $this->object('pub-1', 'publication', 'blijvend_bewaren', ['subjects' => ['subject-1']]);

		$this->assertSame(RetentionService::REFUSAL_NOT_NOMINATED, $this->refusal($publication));
		$this->assertSame([], $this->reads);
	}

	public function testTheDeclarationReadsOnlyNamedStrings(): void {
		$this->assertSame(['subjects'], InheritedAppraisal::declaredProperties(['x-openregister-retention' => ['inheritAppraisalFrom' => ['subjects', '', 3]]]));
		$this->assertSame([], InheritedAppraisal::declaredProperties(['x-openregister-retention' => ['inheritAppraisalFrom' => 'subjects']]));
		$this->assertSame([], InheritedAppraisal::declaredProperties(null));
	}

	public function testTheDeclarationSurvivesTheSchemaConfigurationSetter(): void {
		$this->assertSame(['subjects'], InheritedAppraisal::declaredProperties($this->schemas['publication']->getConfiguration()));
	}
}
