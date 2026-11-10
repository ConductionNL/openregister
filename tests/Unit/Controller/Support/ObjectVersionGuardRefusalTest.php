<?php

/**
 * ObjectVersionGuard refusal tests.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use DateTime;
use OCA\OpenRegister\Controller\Support\ConflictTrail;
use OCA\OpenRegister\Controller\Support\ObjectVersionGuard;
use OCA\OpenRegister\Controller\Support\SentWrite;
use OCA\OpenRegister\Controller\Support\VersionClaim;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\ConflictReportInterface;
use OCA\OpenRegister\Service\Object\RefusedWrite;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A write that claims an older version than the stored one is refused with 409
 * and recorded; no claim or a matching claim passes. With a schema the body is
 * the detailed conflict report, falling back to the plain body when it fails.
 */
class ObjectVersionGuardRefusalTest extends TestCase {

	/**
	 * The stored object's version.
	 */
	private const CURRENT = '2026-05-01T12:00:00+00:00';

	/**
	 * The audit trail double behind the real ConflictTrail.
	 *
	 * @var AuditTrailMapperInterface&MockObject
	 */
	private AuditTrailMapperInterface&MockObject $auditTrail;

	/**
	 * The container double.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * The logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Build the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->auditTrail = $this->createMock(AuditTrailMapperInterface::class);
		$this->auditTrail->method('findAll')->willReturn([]);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->logger    = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * A guard for a request that claims the given version in its body.
	 *
	 * @param string|null $claimed The claimed version.
	 *
	 * @return ObjectVersionGuard
	 */
	private function guard(?string $claimed): ObjectVersionGuard {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key): ?string => ($key === '_expectedUpdated') ? $claimed : null
		);
		$request->method('getHeader')->willReturn('');
		return new ObjectVersionGuard(
			new VersionClaim($request),
			$this->container,
			new ConflictTrail($this->auditTrail, $this->logger),
			$this->logger
		);
	}//end guard()

	/**
	 * The stored object at CURRENT.
	 *
	 * @return ObjectEntity
	 */
	private function stored(): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('obj-1');
		$object->setUpdated(new DateTime(self::CURRENT));
		return $object;
	}//end stored()

	/**
	 * No claim, or a claim of the current version, passes without recording.
	 *
	 * @return void
	 */
	public function testNoOrMatchingClaimPasses(): void {
		$this->auditTrail->expects($this->never())->method('createAuditTrailEntry');

		$this->assertNull($this->guard(null)->refusal($this->stored(), new SentWrite(['title' => 'x'])));
		$this->assertNull($this->guard(self::CURRENT)->refusal($this->stored(), new SentWrite(['title' => 'x'])));
	}//end testNoOrMatchingClaimPasses()

	/**
	 * A stale claim without a schema is refused with the plain body and recorded.
	 *
	 * @return void
	 */
	public function testAStaleClaimIsRefusedWithThePlainBody(): void {
		$this->auditTrail->expects($this->once())->method('createAuditTrailEntry')->willReturn(new AuditTrail());
		$this->container->expects($this->never())->method('get');

		$response = $this->guard('2026-04-01T00:00:00+00:00')->refusal($this->stored(), new SentWrite(['title' => 'x']));

		$this->assertSame(409, $response?->getStatus());
		$this->assertSame(
			[
				'error' => ConflictReportInterface::ERROR,
				'code' => 'version-conflict',
				'message' => 'This object changed since you read it. Re-read it and try again.',
				'conflicts' => [],
				'changedBy' => null,
				'changedAt' => null,
				'expectedUpdated' => '2026-04-01T00:00:00+00:00',
				'currentUpdated' => self::CURRENT,
			],
			$response->getData()
		);
	}//end testAStaleClaimIsRefusedWithThePlainBody()

	/**
	 * With a schema the detailed report becomes the body, keeping both versions.
	 *
	 * @return void
	 */
	public function testWithASchemaTheReportIsTheBody(): void {
		$schema = new Schema();
		$report = $this->createMock(ConflictReportInterface::class);
		$report->expects($this->once())
			->method('build')
			->with(
				$this->callback(
					callback: static fn (RefusedWrite $write): bool => $write->stored instanceof ObjectEntity
						&& $write->schema === $schema
						&& $write->sent === ['title' => 'mine']
				),
				[]
			)
			->willReturn(['error' => 'detailed', 'conflicts' => ['title' => ['theirs', 'mine', 'base']]]);
		$this->container->method('get')->with(ConflictReportInterface::class)->willReturn($report);

		$response = $this->guard('2026-04-01T00:00:00+00:00')->refusal($this->stored(), new SentWrite(['title' => 'mine'], $schema));

		$this->assertSame(
			[
				'error' => 'detailed',
				'conflicts' => ['title' => ['theirs', 'mine', 'base']],
				'expectedUpdated' => '2026-04-01T00:00:00+00:00',
				'currentUpdated' => self::CURRENT,
			],
			$response?->getData()
		);
	}//end testWithASchemaTheReportIsTheBody()

	/**
	 * A report that cannot be built falls back to the plain body and is logged.
	 *
	 * @return void
	 */
	public function testAFailingReportFallsBackToThePlainBody(): void {
		$this->container->method('get')->willThrowException(new RuntimeException('not wired'));
		$this->logger->expects($this->once())
			->method('warning')
			->with('[ObjectsController] a conflict body could not be built: not wired', []);

		$response = $this->guard('2026-04-01T00:00:00+00:00')->refusal($this->stored(), new SentWrite([], new Schema()));

		$this->assertSame(409, $response?->getStatus());
		$this->assertSame(ConflictReportInterface::ERROR, $response->getData()['error']);
		$this->assertSame([], $response->getData()['conflicts']);
	}//end testAFailingReportFallsBackToThePlainBody()
}//end class
