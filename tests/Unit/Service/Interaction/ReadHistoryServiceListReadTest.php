<?php

/**
 * Unit tests for how a list or search read is registered on the processing log.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Interaction
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/avg-verwerkingsregister/spec.md#requirement-reads-of-personal-data-are-registered-through-the-one-read-registration
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Interaction;

use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Interaction\ProcessingListRead;
use OCA\OpenRegister\Service\Interaction\ReadHistoryService;
use OCA\OpenRegister\Service\ProcessingLogService;
use OCA\OpenRegister\Service\ProcessingRead;
use OCA\OpenRegister\Service\SettingsServiceInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Grouping per register and schema, unattributable rows, an empty page and a failing log.
 *
 * @covers \OCA\OpenRegister\Service\Interaction\ReadHistoryService
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Service\Interaction\ProcessingListRead
 * @uses \OCA\OpenRegister\Service\ProcessingRead
 */
class ReadHistoryServiceListReadTest extends TestCase {

	/**
	 * The container, mocked.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * The logger, mocked.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Build the shared mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->container = $this->createMock(ContainerInterface::class);
		$this->logger    = $this->createMock(LoggerInterface::class);

	}//end setUp()

	/**
	 * The service under test.
	 *
	 * @return ReadHistoryService
	 */
	private function service(): ReadHistoryService {
		return new ReadHistoryService(
			$this->createMock(AuditTrailMapperInterface::class),
			$this->createMock(SettingsServiceInterface::class),
			$this->container,
			$this->logger
		);

	}//end service()

	/**
	 * Rows are logged once per register and schema, rendered rows read by their @self block.
	 *
	 * @return void
	 */
	public function testRowsAreLoggedPerRegisterAndSchema(): void {
		$entity = new ObjectEntity();
		$entity->setUuid('u-1');
		$entity->setRegister('1');
		$entity->setSchema('2');

		$groups = [];
		$log    = $this->createMock(ProcessingLogService::class);
		$log->expects($this->exactly(2))
			->method('recordReadList')
			->willReturnCallback(
				static function (array $objects, ProcessingRead $read) use (&$groups): void {
					$groups[] = [
						array_map(static fn (ObjectEntity $o): string => $o->getUuid(), $objects),
						$read->action,
						$read->query,
					];
				}
			);
		$log->expects($this->once())->method('flush');
		$this->container->method('get')->with(ProcessingLogService::class)->willReturn($log);
		$this->logger->expects($this->never())->method('warning');

		$this->service()->registerListRead(
			[
				$entity,
				['naam' => 'x', '@self' => ['id' => 'u-2', 'register' => 1, 'schema' => 2]],
				['@self' => ['uuid' => 'u-3', 'register' => 1, 'schema' => 3, 'organisation' => 'org']],
			],
			new ProcessingListRead(['_search' => 'jan'], 'export')
		);

		$this->assertSame(
			[
				[['u-1', 'u-2'], 'export', ['_search' => 'jan']],
				[['u-3'], 'export', ['_search' => 'jan']],
			],
			$groups
		);

	}//end testRowsAreLoggedPerRegisterAndSchema()

	/**
	 * A rendered row keeps its data, minus the @self block, and its organisation.
	 *
	 * @return void
	 */
	public function testARenderedRowCarriesItsDataAndOrganisation(): void {
		$seen = null;
		$log  = $this->createMock(ProcessingLogService::class);
		$log->method('recordReadList')->willReturnCallback(
			static function (array $objects) use (&$seen): void {
				$seen = $objects[0];
			}
		);
		$this->container->method('get')->willReturn($log);

		$this->service()->registerListRead([['bsn' => '1', '@self' => ['uuid' => 'u', 'register' => 'r', 'schema' => 's', 'organisation' => 'o']]], new ProcessingListRead());

		$this->assertSame('1', $seen->getObject()['bsn']);
		$this->assertArrayNotHasKey('@self', $seen->getObject());
		$this->assertSame('o', $seen->getOrganisation());
		$this->assertSame('r', $seen->getRegister());

	}//end testARenderedRowCarriesItsDataAndOrganisation()

	/**
	 * Rows that cannot be attributed are reported and, alone, log nothing.
	 *
	 * @return void
	 */
	public function testUnattributableRowsAreReportedAndLogNothing(): void {
		$this->container->expects($this->never())->method('get');
		$this->logger->expects($this->once())
			->method('warning')
			->with(
				'[AVG] list read rows without uuid, register and schema were not processing-logged',
				['rows' => 3, 'of' => 3]
			);

		$this->service()->registerListRead(
			[
				'plain string',
				['@self' => ['uuid' => 'u', 'register' => 'r']],
				['@self' => ['uuid' => ['x'], 'register' => 'r', 'schema' => 's']],
			],
			new ProcessingListRead()
		);

	}//end testUnattributableRowsAreReportedAndLogNothing()

	/**
	 * An empty page neither warns nor logs.
	 *
	 * @return void
	 */
	public function testAnEmptyPageDoesNothing(): void {
		$this->container->expects($this->never())->method('get');
		$this->logger->expects($this->never())->method('warning');

		$this->service()->registerListRead([], new ProcessingListRead());

	}//end testAnEmptyPageDoesNothing()

	/**
	 * A failing processing log is skipped with a debug line, never thrown to the reader.
	 *
	 * @return void
	 */
	public function testAFailingLogIsSkipped(): void {
		$this->container->method('get')->willThrowException(new RuntimeException('no service'));
		$this->logger->expects($this->once())
			->method('debug')
			->with('[AVG] processing-log list read hook skipped', ['exception' => 'no service']);

		$this->service()->registerListRead([['@self' => ['id' => 'u', 'register' => 'r', 'schema' => 's']]], new ProcessingListRead());

	}//end testAFailingLogIsSkipped()
}//end class
