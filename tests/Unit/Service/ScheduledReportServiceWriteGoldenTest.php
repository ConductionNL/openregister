<?php

/**
 * ScheduledReportService write golden tests
 *
 * Pins the allowed and refused outcomes of creating and updating a scheduled
 * report: every validation refusal (class and message) in check order, which
 * register and schema are asked for with tenancy on, the stored fields of a
 * created and of a partially updated report, the recipient normalisation,
 * and the owner-or-admin refusal before anything is validated or written.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Kernel\Contracts\ReportCaller;
use OCA\OpenRegister\Kernel\Contracts\ScheduledReportUpdate;
use OCA\OpenRegister\Notifications\Db\ScheduledReport;
use OCA\OpenRegister\Notifications\Db\ScheduledReportMapper;
use OCA\OpenRegister\Service\ExportService;
use OCA\OpenRegister\Service\ScheduledReportService;
use OCA\OpenRegister\Service\ScheduledReportDelivery;
use OCA\OpenRegister\Service\ScheduledReportExporters;
use OCA\OpenRegister\Service\ScheduledReportOwnerServices;
use OCA\OpenRegister\Service\ScheduledReportStores;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Mail\IMailer;
use OCP\Notification\IManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Golden tests for scheduled report writes.
 */
class ScheduledReportServiceWriteGoldenTest extends TestCase {

	/**
	 * Report mapper mock.
	 *
	 * @var ScheduledReportMapper&MockObject
	 */
	private ScheduledReportMapper&MockObject $mapper;

	/**
	 * What the register and schema mappers were asked.
	 *
	 * @var list<string>
	 */
	private array $asked = [];

	/**
	 * The service under test.
	 *
	 * @var ScheduledReportService
	 */
	private ScheduledReportService $service;

	/**
	 * Build the service; register 1 and schema 2 exist, anything else throws.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->mapper   = $this->createMock(ScheduledReportMapper::class);
		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->willReturnCallback(
			function (...$args): Register {
				$this->asked[] = 'register:'.var_export($args[0], true).':'.var_export($args[2] ?? null, true);
				if ((int)$args[0] !== 1) {
					throw new RuntimeException('nope');
				}

				return new Register();
			}
		);
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturnCallback(
			function (...$args): Schema {
				$this->asked[] = 'schema:'.var_export($args[0], true).':'.var_export($args[3] ?? null, true);
				if ($args[0] !== 2) {
					throw new RuntimeException('nope');
				}

				return new Schema();
			}
		);
		$this->mapper->method('insert')->willReturnArgument(0);
		$this->mapper->method('update')->willReturnArgument(0);
		$this->service = new ScheduledReportService(
			new ScheduledReportStores($this->mapper, new \OCA\OpenRegister\Db\SchemaCatalog(registers: $registerMapper, schemas: $schemaMapper, tables: $this->createMock(\OCA\OpenRegister\Db\MagicMapperInterface::class))),
			new ScheduledReportExporters($this->createMock(ExportService::class), null, null),
			new ScheduledReportDelivery(
				new ScheduledReportOwnerServices($this->createMock(IUserManager::class), $this->createMock(IUserSession::class), $this->createMock(IRootFolder::class), $this->createMock(IManager::class)),
				$this->createMock(IMailer::class),
				$this->createMock(IConfig::class)
			),
			$this->createMock(LoggerInterface::class)
		);
	}//end setUp()

	/**
	 * A valid payload, with overrides.
	 *
	 * @param array $overrides Overrides; a null value removes the key
	 *
	 * @return array
	 */
	private static function payload(array $overrides=[]): array {
		$payload = array_merge(
			['name' => 'Weekly', 'registerId' => 1, 'schemaId' => 2, 'format' => 'csv', 'scheduleType' => 'daily', 'scheduleHour' => 6],
			$overrides
		);
		return array_filter($payload, static fn ($value): bool => $value !== null);
	}//end payload()

	/**
	 * Refusal cases: payload, message.
	 *
	 * @return array<string, array{0: array, 1: string}>
	 */
	public static function refusals(): array {
		$weekly  = 'scheduleDayOfWeek (0-6) is required when scheduleType is weekly.';
		$monthly = 'scheduleDayOfMonth (1-28) is required when scheduleType is monthly.';
		return [
			'no name'                => [self::payload(['name' => '']), 'A name is required.'],
			'no name, bad register'  => [self::payload(['name' => null, 'registerId' => 9]), 'A name is required.'],
			'no register'            => [self::payload(['registerId' => 0]), 'A registerId is required.'],
			'unknown register'       => [self::payload(['registerId' => 9]), 'Register not found or not accessible.'],
			'unknown schema'         => [self::payload(['schemaId' => '5']), 'Schema not found or not accessible.'],
			'bad format'             => [self::payload(['format' => 'xml']), 'format must be one of: csv, excel, pdf'],
			'csv without schema'     => [self::payload(['schemaId' => '']), 'CSV export requires a specific schemaId.'],
			'bad schedule type'      => [self::payload(['scheduleType' => 'hourly']), 'scheduleType must be one of: daily, weekly, monthly'],
			'hour too low'           => [self::payload(['scheduleHour' => -1]), 'scheduleHour must be between 0 and 23.'],
			'hour too high'          => [self::payload(['scheduleHour' => '24']), 'scheduleHour must be between 0 and 23.'],
			'weekly without day'     => [self::payload(['scheduleType' => 'weekly']), $weekly],
			'weekly day 7'           => [self::payload(['scheduleType' => 'weekly', 'scheduleDayOfWeek' => 7]), $weekly],
			'weekly day -1'          => [self::payload(['scheduleType' => 'weekly', 'scheduleDayOfWeek' => '-1']), $weekly],
			'monthly without day'    => [self::payload(['scheduleType' => 'monthly', 'scheduleDayOfWeek' => 3]), $monthly],
			'monthly day 0'          => [self::payload(['scheduleType' => 'monthly', 'scheduleDayOfMonth' => 0]), $monthly],
			'monthly day 29'         => [self::payload(['scheduleType' => 'monthly', 'scheduleDayOfMonth' => 29]), $monthly],
			'bad delivery mode'      => [self::payload(['deliveryMode' => 'fax']), 'deliveryMode must be one of: files, email, both'],
			'recipients not array'   => [self::payload(['recipients' => 'a@example.org']), 'recipients must be an array of email addresses.'],
			'too many recipients'    => [self::payload(['recipients' => array_fill(0, 21, 'a@example.org')]), 'recipients cannot exceed 20 addresses.'],
			'recipient not a string' => [self::payload(['recipients' => ['a@example.org', 5]]), '"integer" is not a valid email address.'],
			'recipient not an email' => [self::payload(['recipients' => [' nope ']]), '" nope " is not a valid email address.'],
		];
	}//end refusals()

	/**
	 * Every validation refusal, and nothing is stored.
	 *
	 * @param array  $payload The payload
	 * @param string $message The expected message
	 *
	 * @dataProvider refusals
	 *
	 * @return void
	 */
	public function testCreateRefusals(array $payload, string $message): void {
		$this->mapper->expects($this->never())->method('insert');
		try {
			$this->service->create($payload, 'alice');
			$this->fail('Expected a refusal');
		} catch (InvalidArgumentException $e) {
			$this->assertSame([InvalidArgumentException::class, $message], [get_class($e), $e->getMessage()]);
		}
	}//end testCreateRefusals()

	/**
	 * What a valid create stores, and what the mappers are asked.
	 *
	 * @return void
	 */
	public function testCreateStores(): void {
		$report = $this->service->create(
			self::payload(
				[
					'registerId'         => '1',
					'schemaId'           => null,
					'format'             => 'pdf',
					'scheduleType'       => 'monthly',
					'scheduleHour'       => '23',
					'scheduleDayOfMonth' => '28',
					'deliveryMode'       => 'both',
					'filters'            => ['status' => 'open/ä'],
					'recipients'         => [' b@example.org', 'b@example.org ', 'c@example.org'],
				]
			),
			'alice'
		);
		$this->assertSame(['register:\'1\':true'], $this->asked);
		$this->assertSame(
			['alice', 'Weekly', 1, null, '{"status":"open/ä"}', 'pdf', 'monthly', 23, null, 28, 'Reports/', 'both', '["b@example.org","c@example.org"]', true],
			[
				$report->getOwner(), $report->getName(), $report->getRegisterId(), $report->getSchemaId(), $report->getFilters(), $report->getFormat(),
				$report->getScheduleType(), $report->getScheduleHour(), $report->getScheduleDayOfWeek(), $report->getScheduleDayOfMonth(),
				$report->getDeliveryFolder(), $report->getDeliveryMode(), $report->getRecipients(), $report->getEnabled(),
			]
		);

		$this->asked = [];
		$weekly      = $this->service->create(self::payload(['scheduleType' => 'weekly', 'scheduleDayOfWeek' => 0, 'scheduleHour' => 0, 'recipients' => []]), 'bob');
		$this->assertSame(['register:1:true', 'schema:2:true'], $this->asked);
		$this->assertSame([2, 0, 0, 'files', '[]'], [$weekly->getSchemaId(), $weekly->getScheduleDayOfWeek(), $weekly->getScheduleHour(), $weekly->getDeliveryMode(), $weekly->getRecipients()]);
	}//end testCreateStores()

	/**
	 * A stored report owned by alice.
	 *
	 * @return ScheduledReport
	 */
	private function stored(): ScheduledReport {
		$report = new ScheduledReport();
		$report->setId(4);
		$report->setOwner('alice');
		$report->setName('Old');
		$report->setRegisterId(1);
		$report->setSchemaId(2);
		$report->setFilters('{"a":1}');
		$report->setFormat('excel');
		$report->setProfileId(8);
		$report->setScheduleType('weekly');
		$report->setScheduleHour(5);
		$report->setScheduleDayOfWeek(3);
		$report->setScheduleDayOfMonth(null);
		$report->setDeliveryFolder('Out/');
		$report->setDeliveryMode('email');
		$report->setRecipients('["x@example.org"]');
		$report->setEnabled(true);
		return $report;
	}//end stored()

	/**
	 * The fields of a report, in a fixed order.
	 *
	 * @param ScheduledReport $report The report
	 *
	 * @return array
	 */
	private function fields(ScheduledReport $report): array {
		return [
			$report->getOwner(), $report->getName(), $report->getRegisterId(), $report->getSchemaId(), $report->getFilters(), $report->getFormat(),
			$report->getProfileId(), $report->getScheduleType(), $report->getScheduleHour(), $report->getScheduleDayOfWeek(),
			$report->getScheduleDayOfMonth(), $report->getDeliveryFolder(), $report->getDeliveryMode(), $report->getRecipients(), $report->getEnabled(),
		];
	}//end fields()

	/**
	 * Updates: partial merge, explicit nulls, profile and enabled only when sent; refusals.
	 *
	 * @return void
	 */
	public function testUpdate(): void {
		$this->mapper->method('find')->willReturnCallback(fn (int $id): ScheduledReport => $this->stored());

		$this->assertSame(
			['alice', 'Old', 1, 2, '{"a":1}', 'excel', 8, 'weekly', 5, 3, null, 'Out/', 'email', '["x@example.org"]', true],
			$this->fields($this->service->applyUpdate(update: new ScheduledReportUpdate(id: 4, data: [], caller: new ReportCaller(uid: 'alice', isAdmin: false))))
		);
		$this->assertSame(
			['alice', 'New', 1, null, '{"b":2}', 'pdf', null, 'monthly', 7, null, 12, 'Out/', 'files', '[]', false],
			$this->fields(
				$this->service->applyUpdate(update: new ScheduledReportUpdate(id: 4, data: [
						'name' => 'New', 'schemaId' => null, 'filters' => ['b' => 2], 'format' => 'pdf', 'profileId' => '', 'scheduleType' => 'monthly',
						'scheduleHour' => 7, 'scheduleDayOfWeek' => null, 'scheduleDayOfMonth' => '12', 'deliveryMode' => 'files', 'recipients' => [], 'enabled' => 0,
					], caller: new ReportCaller(uid: 'bob', isAdmin: true)))
			)
		);

		$this->asked = [];
		try {
			$this->service->applyUpdate(update: new ScheduledReportUpdate(id: 4, data: ['name' => ''], caller: new ReportCaller(uid: 'bob', isAdmin: false)));
			$this->fail('Expected a refusal');
		} catch (RuntimeException $e) {
			$this->assertSame([RuntimeException::class, 'You do not have permission to access this scheduled report.'], [get_class($e), $e->getMessage()]);
		}

		$this->assertSame([], $this->asked);
		try {
			$this->service->applyUpdate(update: new ScheduledReportUpdate(id: 4, data: ['scheduleDayOfWeek' => 9], caller: new ReportCaller(uid: 'alice', isAdmin: false)));
			$this->fail('Expected a refusal');
		} catch (InvalidArgumentException $e) {
			$this->assertSame('scheduleDayOfWeek (0-6) is required when scheduleType is weekly.', $e->getMessage());
		}
	}//end testUpdate()
}//end class
