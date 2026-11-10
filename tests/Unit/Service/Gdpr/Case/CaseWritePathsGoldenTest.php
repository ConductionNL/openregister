<?php

declare(strict_types=1);

/**
 * DSAR case write paths golden test.
 *
 * Pins the allowed and refused outcomes of the three paths that read and
 * write a data-subject-request case through CaseObjectAccessor: evidence
 * harvest (dedup, failing / disabled sources, single audited save only on
 * change), field redaction (before/after capture, appended record) and the
 * retention sweep's case enumeration (RBAC + tenant scoped list, live
 * reload per uuid). An unloadable case refuses with the exact message and
 * writes nothing.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Gdpr\Case
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Gdpr\Case;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\DsarService;
use OCA\OpenRegister\Service\Gdpr\Case\CaseObjectAccessor;
use OCA\OpenRegister\Service\Gdpr\Evidence\EvidenceHarvestService;
use OCA\OpenRegister\Service\Gdpr\Evidence\EvidenceItem;
use OCA\OpenRegister\Service\Gdpr\Evidence\EvidenceSourceProvider;
use OCA\OpenRegister\Service\Gdpr\Evidence\EvidenceSourceRegistry;
use OCA\OpenRegister\Service\Gdpr\Redaction\FieldRedaction;
use OCA\OpenRegister\Service\Gdpr\Redaction\RedactionWriteService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Golden test for the DSAR case write paths.
 */
class CaseWritePathsGoldenTest extends TestCase {

	/**
	 * Payloads handed to CaseObjectAccessor::save(), in order.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * Warnings logged, in order.
	 *
	 * @var list<string>
	 */
	private array $warnings = [];

	/**
	 * An accessor mock loading $case (or nothing) and recording saves.
	 *
	 * @param ObjectEntity|null $case The case load() answers.
	 *
	 * @return CaseObjectAccessor
	 */
	private function accessor(?ObjectEntity $case): CaseObjectAccessor {
		$accessor = $this->createMock(CaseObjectAccessor::class);
		$accessor->method('load')->willReturn($case);
		$accessor->method('save')->willReturnCallback(
			function (ObjectEntity $c, array $data) {
				$this->saved[] = $data;
				return $c;
			}
		);
		return $accessor;

	}//end accessor()

	/**
	 * A logger recording warnings.
	 *
	 * @return LoggerInterface
	 */
	private function logger(): LoggerInterface {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function ($message) {
				$this->warnings[] = (string)$message;
			}
		);
		return $logger;

	}//end logger()

	/**
	 * A case entity with the given payload.
	 *
	 * @param array<string, mixed> $data Payload.
	 *
	 * @return ObjectEntity
	 */
	private function caseWith(array $data): ObjectEntity {
		$case = new ObjectEntity();
		$case->setUuid('case-1');
		$case->setObject($data);
		return $case;

	}//end caseWith()

	/**
	 * An evidence provider.
	 *
	 * @param string $id Source id.
	 * @param EvidenceItem[]|null $items Items, or null to throw.
	 * @param bool $enabled Enabled flag.
	 *
	 * @return EvidenceSourceProvider
	 */
	private function provider(string $id, ?array $items, bool $enabled = true): EvidenceSourceProvider {
		$provider = $this->createMock(EvidenceSourceProvider::class);
		$provider->method('getSourceId')->willReturn($id);
		$provider->method('isEnabled')->willReturn($enabled);
		if ($items === null) {
			$provider->method('harvest')->willThrowException(new RuntimeException('source down'));
		} else {
			$provider->method('harvest')->willReturn($items);
		}

		return $provider;

	}//end provider()

	/**
	 * Harvest: dedup against existing and within the run, failing and disabled sources.
	 *
	 * @return void
	 */
	public function testHarvestMergesAndSavesOnce(): void {
		$case = $this->caseWith(
			[
				'subjectId' => 's',
				'evidence' => ['x' => ['contentHash' => 'h1', 'sourceId' => 'old'], 'y' => 'not-an-array', 'z' => ['noHash' => true]],
			]
		);
		$registry = new EvidenceSourceRegistry(logger: $this->logger());
		$registry->withProviders(
			providers: [
				$this->provider('a', [new EvidenceItem('a', 'h1'), new EvidenceItem('a', 'h2'), new EvidenceItem('a', '')]),
				$this->provider('off', [new EvidenceItem('off', 'h9')], false),
				$this->provider('down', null),
				$this->provider('b', [new EvidenceItem('b', 'h2'), new EvidenceItem('b', 'h3', EvidenceItem::STATUS_COLLECTED)]),
			]
		);

		$service = new EvidenceHarvestService(registry: $registry, accessor: $this->accessor($case), logger: $this->logger());
		$result = $service->harvest(caseUuid: 'case-1');

		$this->assertSame(
			['caseUuid' => 'case-1', 'evaluated' => 5, 'appended' => 2, 'skipped' => 3, 'providers' => ['a', 'down', 'b']],
			$result
		);
		$this->assertCount(1, $this->saved);
		$this->assertSame(
			[
				['contentHash' => 'h1', 'sourceId' => 'old'],
				'not-an-array',
				['noHash' => true],
				(new EvidenceItem('a', 'h2'))->toEvidenceRecord(),
				(new EvidenceItem('b', 'h3', EvidenceItem::STATUS_COLLECTED))->toEvidenceRecord(),
			],
			$this->saved[0]['evidence']
		);
		$this->assertSame('s', $this->saved[0]['subjectId']);
		$this->assertSame(['[EvidenceHarvestService] provider "down" failed for case "case-1": source down'], $this->warnings);

	}//end testHarvestMergesAndSavesOnce()

	/**
	 * Harvest: nothing new means no write; a non-array evidence field starts empty.
	 *
	 * @return void
	 */
	public function testHarvestWithoutChangeWritesNothing(): void {
		$registry = new EvidenceSourceRegistry(logger: $this->logger());
		$registry->withProviders(providers: [$this->provider('a', [new EvidenceItem('a', '')])]);
		$service = new EvidenceHarvestService(registry: $registry, accessor: $this->accessor($this->caseWith(['evidence' => 'junk'])), logger: $this->logger());

		$result = $service->harvest(caseUuid: 'case-1');

		$this->assertSame(['caseUuid' => 'case-1', 'evaluated' => 1, 'appended' => 0, 'skipped' => 1, 'providers' => ['a']], $result);
		$this->assertSame([], $this->saved);

		$registry->withProviders(providers: [$this->provider('a', [new EvidenceItem('a', 'h')])]);
		$service->harvest(caseUuid: 'case-1');
		$this->assertSame([(new EvidenceItem('a', 'h'))->toEvidenceRecord()], $this->saved[0]['evidence']);

	}//end testHarvestWithoutChangeWritesNothing()

	/**
	 * Harvest and redaction refuse an unloadable case, writing nothing.
	 *
	 * @return void
	 */
	public function testUnloadableCaseIsRefused(): void {
		$registry = new EvidenceSourceRegistry(logger: $this->logger());
		$provider = $this->createMock(EvidenceSourceProvider::class);
		$provider->method('getSourceId')->willReturn('a');
		$provider->expects($this->never())->method('harvest');
		$registry->withProviders(providers: [$provider]);

		$harvest = new EvidenceHarvestService(registry: $registry, accessor: $this->accessor(null), logger: $this->logger());
		$redact = new RedactionWriteService(accessor: $this->accessor(null));

		foreach ([static fn () => $harvest->harvest(caseUuid: 'gone'), static fn () => $redact->applyRedaction(caseUuid: 'gone', redaction: new FieldRedaction(field: 'f', after: 'x', ground: 'g'))] as $call) {
			try {
				$call();
				$this->fail('An unloadable case must be refused.');
			} catch (RuntimeException $e) {
				$this->assertSame(RuntimeException::class, get_class($e));
				$this->assertSame('Case "gone" not found or not authorised.', $e->getMessage());
			}
		}

		$this->assertSame([], $this->saved);

	}//end testUnloadableCaseIsRefused()

	/**
	 * Redaction: scalar before captured, non-scalar before is '', records appended.
	 *
	 * @return void
	 */
	public function testRedactionCapturesAndAppends(): void {
		$case = $this->caseWith(['name' => 42, 'list' => ['a'], 'redactions' => ['k' => ['field' => 'old']]]);
		$service = new RedactionWriteService(accessor: $this->accessor($case));

		$result = $service->applyRedaction(caseUuid: 'case-1', redaction: new FieldRedaction(field: 'name', after: '[x]', ground: 'g1'));
		$this->assertSame(
			['caseUuid' => 'case-1', 'field' => 'name', 'before' => '42', 'after' => '[x]', 'ground' => 'g1', 'recordType' => 'redaction'],
			$result
		);
		$this->assertSame('[x]', $this->saved[0]['name']);
		$this->assertSame(
			[['field' => 'old'], ['field' => 'name', 'before' => '42', 'after' => '[x]', 'ground' => 'g1', 'recordType' => 'redaction']],
			$this->saved[0]['redactions']
		);

		$case2 = $this->caseWith(['list' => ['a'], 'redactions' => 'junk']);
		$result2 = (new RedactionWriteService(accessor: $this->accessor($case2)))->applyRedaction(caseUuid: 'c2', redaction: new FieldRedaction(field: 'list', after: '', ground: 'g2'));
		$this->assertSame('', $result2['before']);
		$this->assertSame('', $this->saved[1]['list']);
		$this->assertSame([['field' => 'list', 'before' => '', 'after' => '', 'ground' => 'g2', 'recordType' => 'redaction']], $this->saved[1]['redactions']);

		$result3 = (new RedactionWriteService(accessor: $this->accessor($this->caseWith([]))))->applyRedaction(caseUuid: 'c3', redaction: new FieldRedaction(field: 'missing', after: 'y', ground: 'g3'));
		$this->assertSame('', $result3['before']);

	}//end testRedactionCapturesAndAppends()

	/**
	 * findAllCaseEntities: scoped list, uuid from @self then id, live reload, misses dropped.
	 *
	 * @return void
	 */
	public function testFindAllCaseEntitiesIsScopedAndReloads(): void {
		$objectService = $this->createMock(ObjectOperationsInterface::class);
		$objectService->expects($this->once())->method('findAll')
			->with(
				['filters' => ['register' => CaseObjectAccessor::REGISTER_SLUG, 'schema' => CaseObjectAccessor::SCHEMA_SLUG]],
				true,
				true
			)
			->willReturn(
				[
					['@self' => ['uuid' => 'u1'], 'id' => 'ignored'],
					['id' => 'u2'],
					['@self' => []],
					'not-a-row',
					['id' => 'u3'],
				]
			);

		$finds = [];
		$entities = [];
		foreach (['u1', 'u2'] as $uuid) {
			$entity = new ObjectEntity();
			$entity->setUuid($uuid);
			$entities[$uuid] = $entity;
		}

		$objectService->method('find')->willReturnCallback(
			function (...$args) use (&$finds, $entities) {
				$finds[] = [$args[0], $args[3], $args[4], $args[5], $args[6]];
				return ($entities[$args[0]] ?? null);
			}
		);

		$accessor = new CaseObjectAccessor(objectService: $objectService, dsarService: $this->createMock(DsarService::class));

		$this->assertSame([$entities['u1'], $entities['u2']], $accessor->findAllCaseEntities());
		$scope = [CaseObjectAccessor::REGISTER_SLUG, CaseObjectAccessor::SCHEMA_SLUG, true, true];
		$this->assertSame(
			[array_merge(['u1'], $scope), array_merge(['u2'], $scope), array_merge(['u3'], $scope)],
			$finds
		);

	}//end testFindAllCaseEntitiesIsScopedAndReloads()
}//end class
