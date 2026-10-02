<?php

/**
 * The certificates route returns stored certificates, and an app can ask for a destruction list.
 *
 * The certificate half runs over the REAL DestructionListRepository, reading
 * what DestructionExecutionJob stores: an executed list with a
 * `certificateUuid` that points at a `verklaring_van_vernietiging` object.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/openregister
 *
 * @spec openspec/specs/archival-destruction-workflow/spec.md
 */

declare(strict_types=1);

namespace Unit\Controller;

use InvalidArgumentException;
use OCA\OpenRegister\Controller\ArchivalController;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Archival\ArchivalNominationService;
use OCA\OpenRegister\Service\Archival\DestructionListCreator;
use OCA\OpenRegister\Service\Archival\DestructionListRepository;
use OCA\OpenRegister\Service\Archival\DestructionReviewService;
use OCA\OpenRegister\Service\Archival\DestructionService;
use OCA\OpenRegister\Service\Archival\LegalHoldService;
use OCA\OpenRegister\Service\Archival\ReviewOutcomeService;
use OCA\OpenRegister\Service\Settings\ObjectRetentionHandler;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ArchivalCertificatesTest extends TestCase {

	/** @var array<string, ObjectEntity> Objects by uuid. */
	private array $objects = [];

	/** @var array<int, ObjectEntity> Destruction lists. */
	private array $lists = [];

	/** @var array<string, mixed> Request parameters. */
	private array $params = [];

	/** @var array<string, mixed> Archival settings. */
	private array $settings = ['destructionListRegister' => 7, 'destructionListSchema' => 9];

	private DestructionListCreator&MockObject $creator;

	private ArchivalController $controller;

	protected function setUp(): void {
		parent::setUp();

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(fn (string $key, $default = null) => ($this->params[$key] ?? $default));
		$request->method('getParams')->willReturnCallback(fn (): array => $this->params);

		$objectMapper = $this->getMockBuilder(MagicMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['find', 'findAll', 'update'])
			->getMock();
		$objectMapper->method('find')->willReturnCallback(
			function (string|int $identifier): ObjectEntity {
				if (isset($this->objects[(string)$identifier]) === false) {
					throw new DoesNotExistException('no such object');
				}

				return $this->objects[(string)$identifier];
			}
		);
		$objectMapper->method('findAll')->willReturnCallback(
			function (?int $limit = null, ?int $offset = null, ?array $filters = null): array {
				$statuses = ($filters['status'] ?? null);
				return array_values(
					array_filter(
						$this->lists,
						fn (ObjectEntity $l): bool => $statuses === null
							|| in_array(($l->getObject()['status'] ?? null), $statuses, true)
					)
				);
			}
		);

		$settings = $this->createMock(ObjectRetentionHandler::class);
		$settings->method('getArchivalSettingsOnly')->willReturnCallback(fn (): array => $this->settings);
		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->willReturn(new Register());
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturn(new Schema());

		$repository = new DestructionListRepository(
			$objectMapper,
			$registerMapper,
			$schemaMapper,
			$settings,
			$this->createMock(LoggerInterface::class)
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('archivaris-1');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isInGroup')->willReturn(true);
		$groups->method('isAdmin')->willReturn(false);

		$this->creator = $this->createMock(DestructionListCreator::class);

		$this->controller = new ArchivalController(
			'openregister',
			$request,
			$this->createMock(DestructionService::class),
			$this->createMock(LegalHoldService::class),
			$objectMapper,
			$session,
			$groups,
			$this->createMock(LoggerInterface::class),
			$repository,
			new DestructionReviewService(),
			$this->createMock(ReviewOutcomeService::class),
			$this->createMock(AuditTrailMapper::class),
			$this->createMock(ArchivalNominationService::class),
			$schemaMapper,
			$this->creator
		);
	}//end setUp()

	/**
	 * Store an executed list and, optionally, its certificate.
	 *
	 * @param string      $listUuid The list uuid.
	 * @param string|null $certUuid The certificate uuid, or null when it was never stored.
	 * @param string      $date     The execution date.
	 *
	 * @return void
	 */
	private function executed(string $listUuid, ?string $certUuid, string $date): void {
		$list = new ObjectEntity();
		$list->setUuid($listUuid);
		$list->setObject(['status' => 'executed', 'executedAt' => $date, 'certificateUuid' => $certUuid, 'objects' => []]);
		$this->lists[] = $list;

		if ($certUuid === null) {
			return;
		}

		$cert = new ObjectEntity();
		$cert->setUuid($certUuid);
		$cert->setObject(
			[
				'type' => 'verklaring_van_vernietiging',
				'destructionDate' => $date,
				'destructionListUuid' => $listUuid,
				'totalDestroyed' => 3,
				'approvedBy' => ['els', 'piet'],
				'groupedBySchema' => [['schema' => 5, 'classification' => '2.1', 'count' => 3]],
				'selectielijstBron' => ['Selectielijst gemeenten 2020'],
				'complianceStatement' => 'Vernietiging conform Archiefwet 1995 en Archiefbesluit 1995',
				'immutable' => true,
			]
		);
		$this->objects[$certUuid] = $cert;
	}//end executed()

	public function testTheStoredCertificatesAreReturnedNewestFirst(): void {
		$this->executed('dl-old', 'cert-old', '2026-01-10T10:00:00+00:00');
		$this->executed('dl-new', 'cert-new', '2026-09-01T10:00:00+00:00');
		$open = new ObjectEntity();
		$open->setUuid('dl-open');
		$open->setObject(['status' => 'in_review', 'objects' => []]);
		$this->lists[] = $open;

		$response = $this->controller->listCertificates();
		$data = $response->getData();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(2, $data['total']);
		$this->assertSame(['cert-new', 'cert-old'], array_column($data['results'], 'uuid'));
		$this->assertSame('dl-new', $data['results'][0]['destructionListUuid']);
		$this->assertSame(3, $data['results'][0]['totalDestroyed']);
		$this->assertSame('verklaring_van_vernietiging', $data['results'][0]['type']);
		$this->assertSame([], $data['missing']);
	}//end testTheStoredCertificatesAreReturnedNewestFirst()

	public function testOneListsCertificateAndAMissingOneIsNamed(): void {
		$this->executed('dl-1', 'cert-1', '2026-02-01T10:00:00+00:00');
		$this->executed('dl-2', null, '2026-03-01T10:00:00+00:00');

		$this->params = ['destructionList' => 'dl-1'];
		$one = $this->controller->listCertificates()->getData();
		$this->assertSame(['cert-1'], array_column($one['results'], 'uuid'));

		$this->params = [];
		$all = $this->controller->listCertificates()->getData();
		$this->assertSame(['dl-2'], $all['missing']);
	}//end testOneListsCertificateAndAMissingOneIsNamed()

	public function testAnUnconfiguredInstanceSaysSo(): void {
		$this->settings = [];

		$data = $this->controller->listCertificates()->getData();

		$this->assertFalse($data['configured']);
		$this->assertSame([], $data['results']);
	}//end testAnUnconfiguredInstanceSaysSo()

	public function testCreatingAListAnswers201WithTheRefusals(): void {
		$this->params = ['objects' => ['a', 'b']];
		$this->creator->expects($this->once())->method('createFor')->with(['a', 'b'])->willReturn(
			['list' => ['uuid' => 'dl-9', 'status' => 'in_review', 'objects' => [['uuid' => 'a']]], 'refused' => [['uuid' => 'b', 'reason' => 'legal_hold']]]
		);

		$response = $this->controller->createDestructionList();

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame('dl-9', $response->getData()['uuid']);
		$this->assertSame(1, $response->getData()['entryCount']);
		$this->assertSame([['uuid' => 'b', 'reason' => 'legal_hold']], $response->getData()['refused']);
	}//end testCreatingAListAnswers201WithTheRefusals()

	public function testNothingEligibleAnswers422(): void {
		$this->params = ['objects' => ['b']];
		$this->creator->method('createFor')->willReturn(['list' => null, 'refused' => [['uuid' => 'b', 'reason' => 'legal_hold']]]);

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $this->controller->createDestructionList()->getStatus());
	}//end testNothingEligibleAnswers422()

	public function testABodyWithoutUuidsIsRefusedBeforeAnythingIsJudged(): void {
		$this->creator->expects($this->never())->method('createFor');

		$this->params = ['objects' => 'a'];
		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller->createDestructionList()->getStatus());
		$this->params = ['objects' => []];
		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller->createDestructionList()->getStatus());
		$this->params = ['objects' => [['uuid' => 'a']]];
		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller->createDestructionList()->getStatus());
	}//end testABodyWithoutUuidsIsRefusedBeforeAnythingIsJudged()

	public function testAnUnconfiguredInstanceAnswers409(): void {
		$this->params = ['objects' => ['a']];
		$this->creator->method('createFor')->willThrowException(new InvalidArgumentException('not configured'));

		$this->assertSame(Http::STATUS_CONFLICT, $this->controller->createDestructionList()->getStatus());
	}//end testAnUnconfiguredInstanceAnswers409()
}//end class
