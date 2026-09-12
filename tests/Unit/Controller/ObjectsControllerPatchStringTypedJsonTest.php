<?php

declare(strict_types=1);

/**
 * ObjectsController PATCH: a `type: string` property whose stored value is JSON.
 *
 * The magic-table read decodes such a value (SchemaTypeConverter::convertString),
 * so `$existingObject->getObject()` hands back an ARRAY where the schema declares
 * a string. Both patch doors on this controller merge that array into the payload
 * and save it, so validation refuses the write because of a property the caller
 * never mentioned — the opposite of the "only the provided fields are updated"
 * promise in patch()'s own docblock.
 *
 * These tests pin the re-encode that closes the seam, and its deliberate limit:
 * a value the CALLER supplied is never rewritten, so a caller that passes an
 * array for a string property keeps the loud refusal it gets today.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

namespace Unit\Controller;

use OCA\OpenRegister\Controller\ObjectsController;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ExportService;
use OCA\OpenRegister\Service\ImportService;
use OCA\OpenRegister\Service\Object\SchemaTypeConverter;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\WebhookService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for the string-typed JSON restore on both PATCH doors.
 */
class ObjectsControllerPatchStringTypedJsonTest extends TestCase {
	/**
	 * The stored form: what a consuming app wrote with JSON.stringify(), and
	 * what the untouched property must still be after the patch.
	 */
	private const STORED = '[{"status":"open","at":"2026-09-11"},{"status":"closed","at":"2026-09-12"}]';

	private ObjectsController $controller;
	private IRequest&MockObject $request;
	private ContainerInterface&MockObject $container;
	private ObjectService&MockObject $objectService;
	private IUserSession&MockObject $userSession;
	private IGroupManager&MockObject $groupManager;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->objectService = $this->createMock(ObjectService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);

		// The container answers with the REAL converter: the encode half of the
		// rule is the thing under test, so mocking it would test nothing. The
		// controller also asks the container for 'userId' after a save.
		$this->container->method('get')->willReturnCallback(
			static function (string $id): mixed {
				if ($id === SchemaTypeConverter::class) {
					return new SchemaTypeConverter();
				}

				return 'admin';
			}
		);

		$this->controller = new ObjectsController(
			'openregister',
			$this->request,
			$this->createMock(IAppConfig::class),
			$this->createMock(IAppManager::class),
			$this->container,
			$this->createMock(RegisterMapper::class),
			$this->createMock(SchemaMapper::class),
			$this->createMock(AuditTrailMapper::class),
			$this->objectService,
			$this->userSession,
			$this->groupManager,
			$this->createMock(ExportService::class),
			$this->createMock(ImportService::class),
			$this->createMock(WebhookService::class),
			$this->createMock(LoggerInterface::class)
		);
	}//end setUp()

	/**
	 * Stub the world around one patch and capture what reaches saveObject().
	 *
	 * @param array<string, mixed> $storedObject The object as the read hands it back.
	 * @param array<string, mixed> $properties   The schema's property declarations.
	 * @param array<string, mixed> $patchData    The caller's payload.
	 * @param array<string, mixed> $captured     Filled with the data saveObject() was handed.
	 *
	 * @return void
	 */
	private function stubPatch(array $storedObject, array $properties, array $patchData, ?array &$captured): void {
		$user = $this->createMock(IUser::class);
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('getUserGroupIds')->willReturn(['admin']);

		$schema = new Schema();
		$schema->setId(2);
		$schema->setProperties($properties);

		$existingObject = new ObjectEntity();
		$existingObject->setUuid('uuid-123');
		$existingObject->setObject($storedObject);

		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setSchema')->willReturnSelf();
		$this->objectService->method('getRegister')->willReturn(1);
		$this->objectService->method('getSchema')->willReturn(2);
		$this->objectService->method('getCurrentSchemaEntity')->willReturn($schema);
		$this->objectService->method('findSilent')->willReturn($existingObject);
		$this->objectService->method('unlockObject')->willReturn(true);

		$this->request->method('getParams')->willReturn($patchData);
		$this->request->method('getHeader')->willReturn('application/json');
		$this->request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => $default
		);

		$saved = new ObjectEntity();
		$saved->setUuid('uuid-123');
		$saved->setObject($patchData);

		$this->objectService->method('saveObject')->willReturnCallback(
			// `object` is saveObject()'s first parameter; the controller calls
			// it with named arguments, which PHP binds onto the mock's own
			// signature, so position 0 is still the payload.
			static function (mixed ...$arguments) use (&$captured, $saved): ObjectEntity {
				$captured = $arguments[0];

				return $saved;
			}
		);
	}//end stubPatch()

	/**
	 * @return array<string, mixed>
	 */
	private function stringSchemaProperties(): array {
		return [
			'title' => ['type' => 'string'],
			'statusHistory' => ['type' => 'string'],
		];
	}//end stringSchemaProperties()

	public function testPatchRestoresAStringTypedJsonPropertyTheCallerNeverMentioned(): void {
		$captured = null;
		$this->stubPatch(
			// json_decode() is what the read path did to the stored string.
			['title' => 'Old', 'statusHistory' => json_decode(self::STORED, true)],
			$this->stringSchemaProperties(),
			['title' => 'probe'],
			$captured
		);

		$result = $this->controller->patch('1', '2', 'uuid-123', $this->objectService);

		$this->assertSame(200, $result->getStatus());
		$this->assertNotNull($captured, 'the merged payload reached saveObject()');
		$this->assertSame('probe', $captured['title']);
		$this->assertSame(
			self::STORED,
			$captured['statusHistory'],
			'without the restore this arrives as an array and validation refuses a patch that never named it'
		);
	}//end testPatchRestoresAStringTypedJsonPropertyTheCallerNeverMentioned()

	public function testPostPatchRestoresAStringTypedJsonPropertyTheCallerNeverMentioned(): void {
		$captured = null;
		$this->stubPatch(
			['title' => 'Old', 'statusHistory' => json_decode(self::STORED, true)],
			$this->stringSchemaProperties(),
			['title' => 'probe'],
			$captured
		);

		$result = $this->controller->postPatch('1', '2', 'uuid-123', $this->objectService);

		$this->assertSame(200, $result->getStatus());
		$this->assertNotNull($captured, 'the multipart patch door reached saveObject() too');
		$this->assertSame(
			self::STORED,
			$captured['statusHistory'],
			'the second patch door carried the identical defect and must carry the identical fix'
		);
	}//end testPostPatchRestoresAStringTypedJsonPropertyTheCallerNeverMentioned()

	public function testPatchLeavesAnArrayTheCallerSuppliedAloneSoValidationStillRefusesIt(): void {
		$captured = null;
		$this->stubPatch(
			['title' => 'Old', 'statusHistory' => json_decode(self::STORED, true)],
			$this->stringSchemaProperties(),
			['statusHistory' => [['status' => 'open']]],
			$captured
		);

		$this->controller->patch('1', '2', 'uuid-123', $this->objectService);

		$this->assertNotNull($captured);
		$this->assertSame(
			[['status' => 'open']],
			$captured['statusHistory'],
			'a caller that passes an array deliberately keeps its visible refusal rather than a silent rewrite'
		);
	}//end testPatchLeavesAnArrayTheCallerSuppliedAloneSoValidationStillRefusesIt()

	public function testAContainerThatAnswersWithNoConverterLeavesThePatchAloneRatherThanFatal(): void {
		// A container can answer with null. Calling a method on that would turn
		// a patch into a fatal error, which is a worse failure than the
		// validation refusal this whole change exists to remove.
		$emptyContainer = $this->createMock(ContainerInterface::class);
		$emptyContainer->method('get')->willReturn(null);

		$this->controller = new ObjectsController(
			'openregister',
			$this->request,
			$this->createMock(IAppConfig::class),
			$this->createMock(IAppManager::class),
			$emptyContainer,
			$this->createMock(RegisterMapper::class),
			$this->createMock(SchemaMapper::class),
			$this->createMock(AuditTrailMapper::class),
			$this->objectService,
			$this->userSession,
			$this->groupManager,
			$this->createMock(ExportService::class),
			$this->createMock(ImportService::class),
			$this->createMock(WebhookService::class),
			$this->createMock(LoggerInterface::class)
		);

		$captured = null;
		$this->stubPatch(
			['title' => 'Old', 'statusHistory' => json_decode(self::STORED, true)],
			$this->stringSchemaProperties(),
			['title' => 'probe'],
			$captured
		);

		$result = $this->controller->patch('1', '2', 'uuid-123', $this->objectService);

		$this->assertSame(200, $result->getStatus(), 'the patch ran rather than fatalling');
		$this->assertIsArray(
			$captured['statusHistory'],
			'nothing was restored, so the caller keeps the behaviour it had before this change'
		);
	}//end testAContainerThatAnswersWithNoConverterLeavesThePatchAloneRatherThanFatal()

	public function testPatchDoesNotEncodeAPropertyTheSchemaReallyCallsAnArray(): void {
		$captured = null;
		$this->stubPatch(
			['title' => 'Old', 'tags' => ['a', 'b']],
			['title' => ['type' => 'string'], 'tags' => ['type' => 'array']],
			['title' => 'probe'],
			$captured
		);

		$this->controller->patch('1', '2', 'uuid-123', $this->objectService);

		$this->assertNotNull($captured);
		$this->assertSame(['a', 'b'], $captured['tags']);
	}//end testPatchDoesNotEncodeAPropertyTheSchemaReallyCallsAnArray()
}//end class
