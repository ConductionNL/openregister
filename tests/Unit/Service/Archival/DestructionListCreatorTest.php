<?php

/**
 * Another app creates a destruction list, and OpenRegister judges every uuid itself.
 *
 * The judging is done by the REAL RetentionService (the rule the daily sweep
 * uses), so an app cannot put a held, kept or not-yet-due record on a list.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Archival
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/openregister
 *
 * @spec openspec/changes/archival-for-apps/specs/archival-destruction-workflow/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Archival;

use InvalidArgumentException;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Archival\ArchiveActionDateCalculator;
use OCA\OpenRegister\Service\Archival\DestructionListCreator;
use OCA\OpenRegister\Service\Archival\RetentionRowScanner;
use OCA\OpenRegister\Service\Archival\SelectielijstResolver;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\RetentionService;
use OCA\OpenRegister\Service\Settings\ObjectRetentionHandler;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class DestructionListCreatorTest extends TestCase {

	/** @var array<string, ObjectEntity> Objects by uuid. */
	private array $objects = [];

	/** @var array<int, ObjectEntity> Destruction lists already stored. */
	private array $storedLists = [];

	/** @var array<int, array<string, mixed>> What the creator saved. */
	private array $saved = [];

	/** @var array<string, mixed> The archival settings. */
	private array $settings = ['destructionListRegister' => 7, 'destructionListSchema' => 9];

	private DestructionListCreator $creator;

	protected function setUp(): void {
		parent::setUp();

		$objectMapper = $this->getMockBuilder(MagicMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['find', 'findAll'])
			->getMock();
		$objectMapper->method('find')->willReturnCallback(
			function (string|int $identifier): ObjectEntity {
				if (isset($this->objects[(string)$identifier]) === false) {
					throw new DoesNotExistException('no such object');
				}

				return $this->objects[(string)$identifier];
			}
		);
		$objectMapper->method('findAll')->willReturnCallback(fn (): array => $this->storedLists);

		$settings = $this->createMock(ObjectRetentionHandler::class);
		$settings->method('getArchivalSettingsOnly')->willReturnCallback(fn (): array => $this->settings);

		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->willReturn(new Register());
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturn(new Schema());

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('archivaris-1');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$retention = new RetentionService(
			$objectMapper,
			$schemaMapper,
			$registerMapper,
			$this->createMock(AuditTrailMapper::class),
			$settings,
			$this->createMock(IAppConfig::class),
			$session,
			$this->createMock(LoggerInterface::class),
			$this->createMock(RetentionRowScanner::class),
			$this->createMock(ArchiveActionDateCalculator::class),
			$this->createMock(SelectielijstResolver::class)
		);

		/** @var SaveObject&MockObject $saveObject */
		$saveObject = $this->createMock(SaveObject::class);
		$saveObject->method('saveObject')->willReturnCallback(
			function ($register, $schema, array $data): ObjectEntity {
				$this->saved[] = ['register' => $register, 'schema' => $schema, 'data' => $data];
				$list = new ObjectEntity();
				$list->setUuid('list-' . count($this->saved));
				$list->setObject($data);
				return $list;
			}
		);

		$this->creator = new DestructionListCreator(
			retention: $retention,
			objectMapper: $objectMapper,
			saveObject: $saveObject,
			settingsHandler: $settings,
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end setUp()

	/**
	 * An object, with the retention block the sweep reads.
	 *
	 * @param string               $uuid      The uuid.
	 * @param array<string, mixed> $retention The retention block.
	 *
	 * @return void
	 */
	private function object(string $uuid, array $retention): void {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setName('Dossier ' . $uuid);
		$object->setRegister('3');
		$object->setSchema('5');
		$object->setRetention($retention);
		$this->objects[$uuid] = $object;
	}//end object()

	/**
	 * A record due for destruction today.
	 *
	 * @return array<string, mixed>
	 */
	private function due(): array {
		return ['archiefnominatie' => 'vernietigen', 'archiefactiedatum' => '2020-01-01', 'classification' => '2.1'];
	}//end due()

	public function testAHeldObjectIsRefusedAndTheRestIsListed(): void {
		$this->object('free', $this->due());
		$this->object('held', $this->due() + ['legalHold' => ['active' => true, 'reason' => 'bezwaar']]);

		$result = $this->creator->createFor(uuids: ['free', 'held']);

		$this->assertCount(1, $this->saved, 'exactly one list is stored');
		$this->assertSame(7, $this->saved[0]['register']);
		$this->assertSame(9, $this->saved[0]['schema']);
		$this->assertSame(['free'], array_column($this->saved[0]['data']['objects'], 'uuid'));
		$this->assertSame('in_review', $this->saved[0]['data']['status']);
		$this->assertSame('archivaris-1', $this->saved[0]['data']['createdBy']);
		$this->assertSame('list-1', $result['list']['uuid']);
		$this->assertSame([['uuid' => 'held', 'reason' => 'legal_hold']], $result['refused']);
	}//end testAHeldObjectIsRefusedAndTheRestIsListed()

	public function testEveryRuleOfTheSweepRefusesWithItsOwnReason(): void {
		$this->object('kept', ['archiefnominatie' => 'bewaren', 'archiefactiedatum' => '2020-01-01']);
		$this->object('early', ['archiefnominatie' => 'vernietigen', 'archiefactiedatum' => '2999-01-01']);
		$this->object('gone', $this->due() + ['archiefstatus' => 'vernietigd']);
		$this->object('listed', $this->due());
		$this->object('ok', $this->due());

		$pending = new ObjectEntity();
		$pending->setObject(['status' => 'in_review', 'objects' => [['uuid' => 'listed']]]);
		$this->storedLists = [$pending];

		$result = $this->creator->createFor(uuids: ['kept', 'early', 'gone', 'listed', 'missing', 'ok', 'ok']);

		$reasons = array_column($result['refused'], 'reason', 'uuid');
		$this->assertSame('not_nominated_for_destruction', $reasons['kept']);
		$this->assertSame('action_date_not_reached', $reasons['early']);
		$this->assertContains($reasons['gone'], ['record_not_live', 'record_frozen']);
		$this->assertSame('already_on_a_list', $reasons['listed']);
		$this->assertSame('not_found', $reasons['missing']);
		$this->assertSame(['ok'], array_column($this->saved[0]['data']['objects'], 'uuid'), 'a uuid named twice is listed once');
	}//end testEveryRuleOfTheSweepRefusesWithItsOwnReason()

	public function testNothingEligibleStoresNothing(): void {
		$this->object('kept', ['archiefnominatie' => 'bewaren', 'archiefactiedatum' => '2020-01-01']);

		$result = $this->creator->createFor(uuids: ['kept']);

		$this->assertSame([], $this->saved);
		$this->assertNull($result['list']);
		$this->assertSame([['uuid' => 'kept', 'reason' => 'not_nominated_for_destruction']], $result['refused']);
	}//end testNothingEligibleStoresNothing()

	public function testAnInstanceWithoutADestructionListRegisterSaysSo(): void {
		$this->settings = [];
		$this->object('free', $this->due());

		$this->expectException(InvalidArgumentException::class);
		$this->creator->createFor(uuids: ['free']);
	}//end testAnInstanceWithoutADestructionListRegisterSaysSo()
}//end class
