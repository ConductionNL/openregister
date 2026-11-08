<?php

/**
 * RetentionService rules golden tests
 *
 * Pins the allowed and refused outcomes of the retention rules: archival
 * metadata on create, the category override on update (re-derived, ignored
 * and refused), the action-date extension, every destruction refusal reason,
 * transfer eligibility, the pending-list exclusion set and the destruction
 * list built for a sweep.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Exception\ObjectStateWriteException;
use OCA\OpenRegister\Exception\ValidationException;
use OCA\OpenRegister\Service\Archival\ArchiveActionDateCalculator;
use OCA\OpenRegister\Service\Archival\RetentionRowScanner;
use OCA\OpenRegister\Service\Archival\SelectielijstResolver;
use OCA\OpenRegister\Service\RetentionService;
use OCA\OpenRegister\Service\Settings\ArchivalSettingsReaderInterface;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Golden tests for the RetentionService rules.
 */
class RetentionServiceRulesGoldenTest extends TestCase {

	/**
	 * Object mapper mock.
	 *
	 * @var MagicMapperInterface&MockObject
	 */
	private MagicMapperInterface&MockObject $objectMapper;

	/**
	 * Schema mapper mock.
	 *
	 * @var SchemaMapperInterface&MockObject
	 */
	private SchemaMapperInterface&MockObject $schemaMapper;

	/**
	 * Register mapper mock.
	 *
	 * @var RegisterMapperInterface&MockObject
	 */
	private RegisterMapperInterface&MockObject $registerMapper;

	/**
	 * Settings mock.
	 *
	 * @var ArchivalSettingsReaderInterface&MockObject
	 */
	private ArchivalSettingsReaderInterface&MockObject $settings;

	/**
	 * Session mock.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	/**
	 * Row scanner mock.
	 *
	 * @var RetentionRowScanner&MockObject
	 */
	private RetentionRowScanner&MockObject $rowScanner;

	/**
	 * Logged warnings.
	 *
	 * @var list<string>
	 */
	private array $warnings = [];

	/**
	 * The service under test.
	 *
	 * @var RetentionService
	 */
	private RetentionService $service;

	/**
	 * Build the service.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objectMapper   = $this->createMock(MagicMapperInterface::class);
		$this->schemaMapper   = $this->createMock(SchemaMapperInterface::class);
		$this->registerMapper = $this->createMock(RegisterMapperInterface::class);
		$this->settings       = $this->createMock(ArchivalSettingsReaderInterface::class);
		$this->userSession    = $this->createMock(IUserSession::class);
		$this->rowScanner     = $this->createMock(RetentionRowScanner::class);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message): void {
				$this->warnings[] = $message;
			}
		);
		$calculator = $this->createMock(ArchiveActionDateCalculator::class);
		$calculator->method('calculate')->willReturnCallback(
			fn (ObjectEntity $object, Schema $schema, string $period): string => 'date-for-'.$period
		);
		$resolver = $this->createMock(SelectielijstResolver::class);
		$resolver->method('entryFor')->willReturnCallback(
			function (string $category): ?ObjectEntity {
				if ($category !== '1.1' && $category !== '2.2') {
					return null;
				}

				$entry = new ObjectEntity();
				$entry->setObject(['archiefnominatie' => 'vernietigen', 'bewaartermijn' => 'P'.$category[0].'Y', 'bron' => 'VNG '.$category]);
				return $entry;
			}
		);
		$resolver->method('provenanceOf')->willReturnCallback(
			fn (ObjectEntity $entry): array => ['listRevision' => 'rev-'.$entry->getObject()['bron']]
		);
		$this->service = new RetentionService(
			$this->objectMapper,
			$this->schemaMapper,
			$this->registerMapper,
			$this->createMock(AuditTrailMapperInterface::class),
			$this->settings,
			$this->userSession,
			$logger,
			$this->rowScanner,
			$calculator,
			$resolver
		);
	}//end setUp()

	/**
	 * A schema with the given archive block and configuration.
	 *
	 * @param array|null $archive       Archive block
	 * @param array      $configuration Configuration
	 *
	 * @return Schema
	 */
	private function schema(?array $archive, array $configuration=[]): Schema {
		$schema = $this->createMock(Schema::class);
		$schema->method('getArchive')->willReturn($archive);
		$schema->method('getConfiguration')->willReturn($configuration);
		return $schema;
	}//end schema()

	/**
	 * An object with retention, data and identity.
	 *
	 * @param array|null $retention Retention block
	 * @param array      $data      Object data
	 * @param string     $uuid      UUID
	 *
	 * @return ObjectEntity
	 */
	private function object(?array $retention, array $data=[], string $uuid='u-1'): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setObject($data);
		if ($retention !== null) {
			$object->setRetention($retention);
		}

		return $object;
	}//end object()

	/**
	 * Cases for applyArchivalMetadata: archive, existing retention, data, expected retention.
	 *
	 * @return array<string, array{0: array|null, 1: array|null, 2: array, 3: array|null}>
	 */
	public static function archivalMetadataCases(): array {
		return [
			'empty archive block'       => [[], null, [], []],
			'archive disabled'          => [['enabled' => false, 'defaultNominatie' => 'vernietigen'], ['x' => 1], [], ['x' => 1]],
			'archive without enabled'   => [['defaultNominatie' => 'vernietigen'], ['x' => 1], [], ['x' => 1]],
			'already nominated'         => [['enabled' => true], ['archiefnominatie' => 'bewaren'], [], ['archiefnominatie' => 'bewaren']],
			'defaults only'             => [
				['enabled' => true, 'defaultNominatie' => 'vernietigen', 'defaultBewaartermijn' => 'P7Y'],
				['legalHold' => ['active' => false]],
				[],
				[
					'legalHold'         => ['active' => false],
					'archiefnominatie'  => 'vernietigen',
					'archiefstatus'     => 'active',
					'classification'    => null,
					'bewaartermijn'     => 'P7Y',
					'selectielijstBron' => null,
					'archiefactiedatum' => 'date-for-P7Y',
				],
			],
			'no period, no action date' => [
				['enabled' => true],
				null,
				[],
				[
					'archiefnominatie'  => 'nog_niet_bepaald',
					'archiefstatus'     => 'active',
					'classification'    => null,
					'bewaartermijn'     => null,
					'selectielijstBron' => null,
				],
			],
			'selectielijst category'    => [
				['enabled' => true, 'classification' => '1.1', 'defaultNominatie' => 'bewaren'],
				[],
				[],
				[
					'archiefnominatie'  => 'vernietigen',
					'archiefstatus'     => 'active',
					'classification'    => '1.1',
					'bewaartermijn'     => 'P1Y',
					'selectielijstBron' => 'VNG 1.1',
					'listRevision'      => 'rev-VNG 1.1',
					'archiefactiedatum' => 'date-for-P1Y',
				],
			],
			'record override and term'  => [
				['enabled' => true, 'classification' => '1.1', 'classificationProperty' => 'cat', 'bewaartermijnOverride' => 'P20Y'],
				[],
				['cat' => ' 2.2 '],
				[
					'archiefnominatie'  => 'vernietigen',
					'archiefstatus'     => 'active',
					'classification'    => '2.2',
					'bewaartermijn'     => 'P20Y',
					'selectielijstBron' => 'VNG 2.2',
					'listRevision'      => 'rev-VNG 2.2',
					'archiefactiedatum' => 'date-for-P20Y',
				],
			],
		];
	}//end archivalMetadataCases()

	/**
	 * Archival metadata on create.
	 *
	 * @param array|null $archive   Archive block
	 * @param array|null $retention Existing retention
	 * @param array      $data      Object data
	 * @param array|null $expected  Expected retention
	 *
	 * @dataProvider archivalMetadataCases
	 *
	 * @return void
	 */
	public function testApplyArchivalMetadata(?array $archive, ?array $retention, array $data, ?array $expected): void {
		$object = $this->object($retention, $data);
		$result = $this->service->applyArchivalMetadata($object, $this->schema($archive));
		$this->assertSame($object, $result);
		$this->assertSame($expected, $result->getRetention());
	}//end testApplyArchivalMetadata()

	/**
	 * Category override on update: ignored, re-derived.
	 *
	 * @return void
	 */
	public function testApplyClassificationOnUpdateAllowedOutcomes(): void {
		$archive = ['enabled' => true, 'classification' => '1.1', 'classificationProperty' => 'cat'];
		$stored  = ['archiefnominatie' => 'vernietigen', 'archiefstatus' => 'actief', 'classification' => '1.1', 'archiefactiedatum' => 'x', 'selectielijstBron' => 'y', 'keep' => 1];

		// Not enabled, no property, unchanged override, same effective category: untouched.
		$untouched = [
			[['classification' => '1.1', 'classificationProperty' => 'cat'], ['cat' => '2.2'], []],
			[['enabled' => true, 'classification' => '1.1'], ['cat' => '2.2'], []],
			[$archive, ['cat' => '2.2'], ['cat' => '2.2 ']],
			[$archive, ['cat' => '1.1'], []],
		];
		foreach ($untouched as [$schemaArchive, $data, $previous]) {
			$object = $this->object($stored, $data);
			$this->assertSame($object, $this->service->applyClassificationOnUpdate($object, $this->schema($schemaArchive), $previous));
			$this->assertSame($stored, $object->getRetention());
		}

		// A live record is re-derived from its new category.
		$object = $this->object($stored, ['cat' => '2.2']);
		$this->service->applyClassificationOnUpdate($object, $this->schema($archive), ['cat' => '1.1']);
		$this->assertSame(
			[
				'archiefstatus'     => 'active',
				'classification'    => '2.2',
				'keep'              => 1,
				'archiefnominatie'  => 'vernietigen',
				'bewaartermijn'     => 'P2Y',
				'selectielijstBron' => 'VNG 2.2',
				'listRevision'      => 'rev-VNG 2.2',
				'archiefactiedatum' => 'date-for-P2Y',
			],
			$object->getRetention()
		);

		// No stored status counts as live.
		$object = $this->object(['classification' => '1.1'], ['cat' => '2.2']);
		$this->service->applyClassificationOnUpdate($object, $this->schema($archive), []);
		$this->assertSame('2.2', $object->getRetention()['classification']);
	}//end testApplyClassificationOnUpdateAllowedOutcomes()

	/**
	 * Refusal cases for the override on update: retention, data, exception class, message.
	 *
	 * @return array<string, array{0: array, 1: array, 2: class-string, 3: string}>
	 */
	public static function classificationRefusalCases(): array {
		return [
			'unknown category'   => [
				['archiefstatus' => 'actief', 'classification' => '1.1'],
				['cat' => '9.9'],
				ValidationException::class,
				'The selectielijst category "9.9" in "cat" matches no selectielijst row.',
			],
			'not text'           => [
				['archiefstatus' => 'actief', 'classification' => '1.1'],
				['cat' => ['2.2']],
				ValidationException::class,
				'The selectielijst category in "cat" must be text.',
			],
			'semi-static record' => [
				['archiefstatus' => 'semi_statisch', 'classification' => '1.1'],
				['cat' => '2.2'],
				ObjectStateWriteException::class,
				'Cannot change the selectielijst category to "2.2": this record was already nominated under "1.1". Recompute its nomination instead.',
			],
			'transferred record' => [
				['archiefstatus' => 'overgebracht'],
				['cat' => '2.2'],
				ObjectStateWriteException::class,
				'Cannot change the selectielijst category to "2.2": this record was already nominated under "". Recompute its nomination instead.',
			],
			'nominated record'   => [
				['archiefstatus' => 'active', 'classification' => '1.1', 'nomination' => ['by' => 'x']],
				['cat' => '2.2'],
				ObjectStateWriteException::class,
				'Cannot change the selectielijst category to "2.2": this record was already nominated under "1.1". Recompute its nomination instead.',
			],
		];
	}//end classificationRefusalCases()

	/**
	 * The override on update is refused, and the retention is left alone.
	 *
	 * @param array        $retention Stored retention
	 * @param array        $data      New data
	 * @param class-string $exception Expected exception class
	 * @param string       $message   Expected message
	 *
	 * @dataProvider classificationRefusalCases
	 *
	 * @return void
	 */
	public function testApplyClassificationOnUpdateRefusals(array $retention, array $data, string $exception, string $message): void {
		$object = $this->object($retention, $data);
		try {
			$this->service->applyClassificationOnUpdate(
				$object,
				$this->schema(['enabled' => true, 'classification' => '1.1', 'classificationProperty' => 'cat']),
				['cat' => '1.1']
			);
			$this->fail('Expected a refusal');
		} catch (ValidationException | ObjectStateWriteException $e) {
			$this->assertSame($exception, get_class($e));
			$this->assertSame($message, $e->getMessage());
		}

		$this->assertSame($retention, $object->getRetention());
	}//end testApplyClassificationOnUpdateRefusals()

	/**
	 * Action-date extension.
	 *
	 * @return void
	 */
	public function testExtendArchiveActionDate(): void {
		$this->settings->method('getArchivalSettingsOnly')->willReturnOnConsecutiveCalls(['defaultExtensionPeriod' => 'P6M'], []);

		$none = $this->object(['archiefnominatie' => 'vernietigen']);
		$this->assertSame(['archiefnominatie' => 'vernietigen'], $this->service->extendArchiveActionDate($none, 'P1Y')->getRetention());

		$given = $this->object(['archiefactiedatum' => '2030-01-15']);
		$this->assertSame(
			['archiefactiedatum' => '2032-01-15', 'originalArchiefactiedatum' => '2032-01-15'],
			$this->service->extendArchiveActionDate($given, 'P2Y')->getRetention()
		);

		$kept = $this->object(['archiefactiedatum' => '2030-01-15', 'originalArchiefactiedatum' => '2029-01-01']);
		$this->assertSame(
			['archiefactiedatum' => '2030-07-15', 'originalArchiefactiedatum' => '2029-01-01'],
			$this->service->extendArchiveActionDate($kept)->getRetention()
		);

		$fallback = $this->object(['archiefactiedatum' => '2030-01-15']);
		$this->assertSame('2031-01-15', $this->service->extendArchiveActionDate($fallback)->getRetention()['archiefactiedatum']);

		$bad = $this->object(['archiefactiedatum' => '2030-01-15']);
		$this->assertSame(['archiefactiedatum' => '2030-01-15'], $this->service->extendArchiveActionDate($bad, 'two years')->getRetention());
		$this->assertCount(1, $this->warnings);
		$this->assertStringStartsWith('[RetentionService] Failed to extend archiefactiedatum: ', $this->warnings[0]);
	}//end testExtendArchiveActionDate()

	/**
	 * Destruction cases: retention, uuid, excluded uuids, expected refusal.
	 *
	 * @return array<string, array{0: array|null, 1: string, 2: list<string>, 3: string|null}>
	 */
	public static function destructionCases(): array {
		$due = ['archiefnominatie' => 'vernietigen', 'archiefactiedatum' => '2030-06-01'];
		return [
			'no retention'          => [null, 'u-1', [], 'not_nominated_for_destruction'],
			'retain'                => [['archiefnominatie' => 'bewaren'] + $due, 'u-1', [], 'not_nominated_for_destruction'],
			'english destroy'       => [['archiefnominatie' => 'destroy'] + $due, 'u-1', [], null],
			'semi static'           => [$due + ['archiefstatus' => 'semi_statisch'], 'u-1', [], 'record_not_live'],
			'transferred'           => [$due + ['archiefstatus' => 'overgebracht'], 'u-1', [], 'record_not_live'],
			'blank state is live'   => [$due + ['archiefstatus' => '  '], 'u-1', [], null],
			'non-string state'      => [$due + ['archiefstatus' => 1], 'u-1', [], 'record_not_live'],
			'no action date'        => [['archiefnominatie' => 'vernietigen'], 'u-1', [], 'action_date_not_reached'],
			'action date ahead'     => [['archiefactiedatum' => '2030-06-02'] + $due, 'u-1', [], 'action_date_not_reached'],
			'legal hold'            => [$due + ['legalHold' => ['active' => true]], 'u-1', [], 'legal_hold'],
			'inactive legal hold'   => [$due + ['legalHold' => ['active' => 'yes']], 'u-1', [], null],
			'already listed'        => [$due, 'u-1', ['u-0', 'u-1'], 'already_on_a_list'],
			'due today'             => [$due + ['archiefstatus' => 'actief'], 'u-1', ['u-2'], null],
			'hold beats listed'     => [$due + ['legalHold' => ['active' => true]], 'u-1', ['u-1'], 'legal_hold'],
		];
	}//end destructionCases()

	/**
	 * Every destruction refusal reason, in rule order.
	 *
	 * @param array|null   $retention Retention
	 * @param string       $uuid      UUID
	 * @param list<string> $excluded  Excluded UUIDs
	 * @param string|null  $expected  Expected refusal
	 *
	 * @dataProvider destructionCases
	 *
	 * @return void
	 */
	public function testDestructionRefusal(?array $retention, string $uuid, array $excluded, ?string $expected): void {
		$this->assertSame($expected, $this->service->destructionRefusal($this->object($retention, [], $uuid), '2030-06-01', $excluded));
	}//end testDestructionRefusal()

	/**
	 * Transfer eligibility: which objects the sweep accepts.
	 *
	 * @return void
	 */
	public function testFindEligibleForTransfer(): void {
		$past    = ['archiefnominatie' => 'blijvend_bewaren', 'archiefactiedatum' => '2000-01-01'];
		$objects = [
			'ok'            => $this->object($past, [], 'ok'),
			'semi'          => $this->object($past + ['archiefstatus' => 'semi_statisch'], [], 'semi'),
			'bewaren'       => $this->object(['archiefnominatie' => 'bewaren'] + $past, [], 'bewaren'),
			'destroy'       => $this->object(['archiefnominatie' => 'vernietigen'] + $past, [], 'destroy'),
			'transferred'   => $this->object($past + ['archiefstatus' => 'transferred'], [], 'transferred'),
			'destroyed'     => $this->object($past + ['archiefstatus' => 'vernietigd'], [], 'destroyed'),
			'no date'       => $this->object(['archiefnominatie' => 'blijvend_bewaren'], [], 'no date'),
			'future'        => $this->object(['archiefactiedatum' => '2999-01-01'] + $past, [], 'future'),
			'hold'          => $this->object($past + ['legalHold' => ['active' => true]], [], 'hold'),
			'excluded'      => $this->object($past, [], 'excluded'),
			'none'          => $this->object(null, [], 'none'),
		];
		$this->rowScanner->method('scan')->willReturnCallback(
			fn (callable $accept, ?int $maxMatches) => [
				'objects'   => array_values(array_filter($objects, $accept)),
				'scanned'   => count($objects),
				'truncated' => false,
			]
		);

		$found = array_map(fn (ObjectEntity $object) => $object->getUuid(), $this->service->findEligibleForTransfer(['excluded']));
		$this->assertSame(['ok', 'semi', 'bewaren'], $found);
	}//end testFindEligibleForTransfer()

	/**
	 * The pending-list exclusion set.
	 *
	 * @return void
	 */
	public function testGetObjectsOnPendingDestructionLists(): void {
		$this->settings->method('getArchivalSettingsOnly')->willReturnOnConsecutiveCalls(
			['destructionListRegister' => 3],
			['destructionListRegister' => '3', 'destructionListSchema' => '7'],
			['destructionListRegister' => 3, 'destructionListSchema' => 7]
		);
		$register = new Register();
		$schema   = new Schema();
		$this->registerMapper->expects($this->exactly(2))->method('find')->with(3)->willReturn($register);
		$this->schemaMapper->expects($this->exactly(2))->method('find')->with(7)->willReturn($schema);
		$listA = new ObjectEntity();
		$listA->setObject(['objects' => [['uuid' => 'a'], ['title' => 'no uuid'], ['uuid' => 'b']]]);
		$listB = new ObjectEntity();
		$listB->setObject(['objects' => [['uuid' => 'b'], ['uuid' => 'c']]]);
		$listC = new ObjectEntity();
		$listC->setObject([]);
		$this->objectMapper->expects($this->exactly(2))->method('findAll')->willReturnCallback(
			function (...$args) use ($register, $schema, $listA, $listB, $listC) {
				$this->assertSame(['status' => ['in_review', 'approved', 'awaiting_second_approval']], $args[2]);
				$this->assertSame($register, $args[10]);
				$this->assertSame($schema, $args[11]);
				static $call = 0;
				$call++;
				if ($call === 2) {
					throw new \RuntimeException('db down');
				}

				return [$listA, $listB, $listC];
			}
		);

		$this->assertSame([], $this->service->getObjectsOnPendingDestructionLists());
		$this->assertSame([0 => 'a', 1 => 'b', 3 => 'c'], $this->service->getObjectsOnPendingDestructionLists());
		$this->assertSame([], $this->service->getObjectsOnPendingDestructionLists());
		$this->assertSame(['[RetentionService] Failed to get pending destruction list objects: db down'], $this->warnings);
	}//end testGetObjectsOnPendingDestructionLists()

	/**
	 * The destruction list built for a sweep.
	 *
	 * @return void
	 */
	public function testCreateDestructionList(): void {
		$this->settings->method('getArchivalSettingsOnly')->willReturnOnConsecutiveCalls(
			['destructionListSchema' => 7],
			['destructionListRegister' => 3, 'destructionListSchema' => 7],
			['destructionListRegister' => 3, 'destructionListSchema' => 7]
		);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('archivaris');
		$this->userSession->method('getUser')->willReturnOnConsecutiveCalls(null, $user);

		$this->assertNull($this->service->createDestructionList([]));
		$this->assertSame(['[RetentionService] Cannot create destruction list: register/schema not configured'], $this->warnings);

		$plain = $this->object(['archiefactiedatum' => '2030-01-01', 'classification' => '1.1'], [], 'p');
		$plain->setName('Dossier');
		$plain->setSchema('5');
		$plain->setRegister('3');
		$wooFlag   = $this->object(null, ['woo_gepubliceerd' => true], 'w1');
		$wooStatus = $this->object(null, ['publicatiestatus' => 'gepubliceerd', 'woo_gepubliceerd' => 'true'], 'w2');
		$wooMeta   = $this->object(['wooPublished' => true], [], 'w3');
		$deleted   = $this->object(['wooPublished' => 1], ['publicatiestatus' => 'concept'], 'd');
		$deleted->setDeleted(['deletedBy' => 'x']);

		$expectedEntries = [
			['uuid' => 'p', 'title' => 'Dossier', 'schema' => '5', 'register' => '3', 'archiefactiedatum' => '2030-01-01', 'classification' => '1.1', 'softDeleted' => false, 'wooGepubliceerd' => false, 'reviewer' => null],
			['uuid' => 'w1', 'title' => 'w1', 'schema' => null, 'register' => null, 'archiefactiedatum' => null, 'classification' => null, 'softDeleted' => false, 'wooGepubliceerd' => true, 'reviewer' => null],
			['uuid' => 'w2', 'title' => 'w2', 'schema' => null, 'register' => null, 'archiefactiedatum' => null, 'classification' => null, 'softDeleted' => false, 'wooGepubliceerd' => true, 'reviewer' => null],
			['uuid' => 'w3', 'title' => 'w3', 'schema' => null, 'register' => null, 'archiefactiedatum' => null, 'classification' => null, 'softDeleted' => false, 'wooGepubliceerd' => true, 'reviewer' => null],
			['uuid' => 'd', 'title' => 'd', 'schema' => null, 'register' => null, 'archiefactiedatum' => null, 'classification' => null, 'softDeleted' => true, 'wooGepubliceerd' => false, 'reviewer' => null],
		];
		foreach (['system', 'archivaris'] as $createdBy) {
			$list = $this->service->createDestructionList([$plain, $wooFlag, $wooStatus, $wooMeta, $deleted]);
			$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $list['createdAt']);
			unset($list['createdAt']);
			$this->assertSame(
				['status' => 'in_review', 'createdBy' => $createdBy, 'objects' => $expectedEntries, 'excluded' => [], 'approvals' => [], 'decisions' => []],
				$list
			);
		}
	}//end testCreateDestructionList()
}//end class
