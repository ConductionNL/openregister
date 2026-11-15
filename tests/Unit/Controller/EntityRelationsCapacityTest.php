<?php

/**
 * A reviewer confirms a person's capacity on PATCH /api/entity-relations/{id} (REQ-DKP-002).
 *
 * Runs the controller over the real decision service and the real
 * updateDecisionMetadata path; only the database writes are stubbed.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/detection-knows-the-person/tasks.md#task-2.1
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

use DateTime;
use OCA\OpenRegister\Controller\EntityRelationsController;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\Decision;
use OCA\OpenRegister\Db\DecisionMapper;
use OCA\OpenRegister\Db\EmailLinkMapper;
use OCA\OpenRegister\Db\EntityRelation;
use OCA\OpenRegister\Db\EntityRelationMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Decision\DecisionOwnership;
use OCA\OpenRegister\Service\Decision\DecisionService;
use OCA\OpenRegister\Service\Decision\DecisionVisibility;
use OCA\OpenRegister\Service\Decision\DecisionVisibilityPlatform;
use OCA\OpenRegister\Service\Decision\RelationDecisionService;
use OCA\OpenRegister\Service\Decision\RelationDecisionStores;
use OCA\OpenRegister\Service\Decision\ReviewPolicyFileOwners;
use OCA\OpenRegister\Service\Decision\ReviewPolicyResolver;
use OCA\OpenRegister\Service\File\ManagedFileOwnerLookup;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Registers\EntityEventPublisher;
use OCA\OpenRegister\Service\Settings\FileSettingsHandler;
use OCA\OpenRegister\Tests\Unit\Controller\Support\EntityRelationsControllerFactory;
use OCP\AppFramework\Http;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class EntityRelationsCapacityTest extends TestCase {

	/**
	 * The audit entries the mapper wrote.
	 *
	 * @var list<AuditTrail>
	 */
	private array $audited = [];

	/**
	 * PATCH the relation with the body, as reviewer "alice", under the stored anonymisation settings.
	 *
	 * @param EntityRelation       $relation      The relation.
	 * @param array<string, mixed> $body          The JSON body.
	 * @param array<string, mixed> $anonymisation The stored anonymisation settings.
	 *
	 * @return array{status: int, data: array}
	 */
	private function patch(EntityRelation $relation, array $body, array $anonymisation = []): array {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $key === 'fileManagement'
				? (string) json_encode(['anonymisation' => $anonymisation])
				: $default
		);
		$fileSettings = new FileSettingsHandler(appConfig: $appConfig);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$auditTrailMapper = $this->createMock(AuditTrailMapper::class);
		$auditTrailMapper->method('insert')->willReturnCallback(
			function (AuditTrail $entry): AuditTrail {
				$this->audited[] = $entry;
				return $entry;
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$mapper = $this->getMockBuilder(EntityRelationMapper::class)
			->setConstructorArgs([$this->createMock(IDBConnection::class), $auditTrailMapper, $userSession, new EntityEventPublisher(dispatcher: $this->createMock(IEventDispatcher::class)), $logger])
			->onlyMethods(['find', 'update', 'linkDecision'])
			->getMock();
		$mapper->method('find')->willReturn($relation);
		$mapper->method('update')->willReturnArgument(0);
		$mapper->method('linkDecision')->willReturnCallback(
			static function (EntityRelation $linked, string $decisionUuid): EntityRelation {
				$linked->setDecisionUuid($decisionUuid);
				return $linked;
			}
		);

		$decisionMapper = $this->createMock(DecisionMapper::class);
		$decisionMapper->method('insert')->willReturnCallback(
			static function (Decision $decision): Decision {
				$decision->setUuid('d-1');
				return $decision;
			}
		);

		$file = $this->createMock(File::class);
		$file->method('isUpdateable')->willReturn(true);
		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getById')->willReturn([$file]);
		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->willReturn($userFolder);

		$schemaMapper = $this->createMock(SchemaMapper::class);
		$decisions = new RelationDecisionService(
			stores: new RelationDecisionStores(relationMapper: $mapper, decisions: new DecisionService(decisionMapper: $decisionMapper), db: $this->createMock(IDBConnection::class)),
			ownership: new DecisionOwnership(
				groupManager: $this->createMock(IGroupManager::class),
				platform: new DecisionVisibilityPlatform($this->createMock(IEventDispatcher::class), $logger)
			),
			policies: new ReviewPolicyResolver(
				schemaMapper: $schemaMapper,
				fileSettings: $fileSettings,
				fileOwners: new ReviewPolicyFileOwners($rootFolder, $this->createMock(ManagedFileOwnerLookup::class)),
				logger: $logger
			),
			fileSettings: $fileSettings
		);

		$visibility = $this->createMock(DecisionVisibility::class);
		$visibility->method('present')->willReturnCallback(
			static fn (EntityRelation $presented, string $userId, ?array $serialised = null): array => ($serialised ?? $presented->jsonSerialize())
		);

		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn('application/json');
		$request->method('getParams')->willReturn($body + ['id' => 42, '_route' => 'openregister.entityRelations.update']);

		/** @var EntityRelationsController $controller */
		$controller = EntityRelationsControllerFactory::create(
			'openregister',
			$request,
			$mapper,
			$userSession,
			$rootFolder,
			$this->createMock(PermissionHandler::class),
			$this->createMock(MagicMapper::class),
			$schemaMapper,
			$this->createMock(RegisterMapper::class),
			$this->createMock(EmailLinkMapper::class),
			$logger,
			$decisions,
			$visibility,
			$this->createMock(IGroupManager::class)
		);

		$response = $controller->update(42);

		return ['status' => $response->getStatus(), 'data' => $response->getData()];
	}//end patch()

	private static function suggested(string $capacity, string $source): EntityRelation {
		$relation = new EntityRelation();
		$relation->setId(42);
		$relation->setFileId(7);
		$relation->setCapacity($capacity);
		$relation->setCapacitySource($source);

		return $relation;
	}//end suggested()

	public function testConfirmingRecordsWhoAndWhen(): void {
		$relation = self::suggested(capacity: 'public-office', source: 'officials-register');
		$before = new DateTime();

		$result = $this->patch(relation: $relation, body: ['capacity' => 'public-office']);

		$this->assertSame(Http::STATUS_OK, $result['status']);
		$this->assertSame('public-office', $relation->getCapacityApplied());
		$this->assertSame('officials-register', $relation->getCapacitySource());
		$this->assertSame('alice', $relation->getCapacityConfirmedBy());
		$this->assertGreaterThanOrEqual($before->getTimestamp(), $relation->getCapacityConfirmedAt()->getTimestamp());
		// The shipped policy releases a public office.
		$this->assertTrue($relation->getSkipAnonymization());
		$this->assertSame('d-1', $relation->getDecisionUuid());
		$this->assertSame('public-office', $result['data']['capacityApplied']);
		$this->assertSame('alice', $result['data']['capacityConfirmedBy']);

		$this->assertCount(1, $this->audited);
		$fields = $this->audited[0]->getChanged()['fields'];
		$this->assertSame(['previous' => null, 'new' => 'public-office'], $fields['capacityApplied']);
		$this->assertSame(['previous' => false, 'new' => true], $fields['skipAnonymization']);
		$this->assertSame('alice', $this->audited[0]->getUser());
	}//end testConfirmingRecordsWhoAndWhen()

	public function testConfirmingAWithheldCapacityRedactsUnderThePolicyGround(): void {
		$relation = self::suggested(capacity: 'professional', source: 'role-words');

		$this->patch(
			relation: $relation,
			body: ['capacity' => 'professional'],
			anonymisation: ['capacityPolicy' => ['professional' => ['decision' => 'withhold', 'ground' => '5.1.2i']]]
		);

		$this->assertSame('professional', $relation->getCapacityApplied());
		$this->assertFalse($relation->getSkipAnonymization());
		$this->assertSame(['5.1.2i'], $relation->getBases());
	}//end testConfirmingAWithheldCapacityRedactsUnderThePolicyGround()

	public function testAnExplicitDecisionWinsOverThePolicy(): void {
		$relation = self::suggested(capacity: 'public-office', source: 'officials-register');

		$this->patch(relation: $relation, body: ['capacity' => 'public-office', 'skipAnonymization' => false, 'bases' => ['5.1.1d']]);

		$this->assertSame('public-office', $relation->getCapacityApplied());
		$this->assertFalse($relation->getSkipAnonymization());
		$this->assertSame(['5.1.1d'], $relation->getBases());
	}//end testAnExplicitDecisionWinsOverThePolicy()

	public function testAnUnknownCapacityIsRefusedAndNothingChanges(): void {
		$relation = self::suggested(capacity: 'public-office', source: 'officials-register');

		$result = $this->patch(relation: $relation, body: ['capacity' => 'koning']);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $result['status']);
		$this->assertSame(['field' => 'capacity', 'reason' => 'unknown_capacity'], $result['data']['details']);
		$this->assertNull($relation->getCapacityApplied());
		$this->assertFalse($relation->getSkipAnonymization());
		$this->assertSame([], $this->audited);
	}//end testAnUnknownCapacityIsRefusedAndNothingChanges()
}//end class
