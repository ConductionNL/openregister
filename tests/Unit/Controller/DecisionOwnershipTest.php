<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use DateTime;
use OCA\OpenRegister\Controller\EntityRelationsController;
use OCA\OpenRegister\Db\Decision;
use OCA\OpenRegister\Db\DecisionMapper;
use OCA\OpenRegister\Db\EmailLinkMapper;
use OCA\OpenRegister\Db\EntityRelation;
use OCA\OpenRegister\Db\EntityRelationMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Decision\DecisionOwnership;
use OCA\OpenRegister\Service\Decision\DecisionService;
use OCA\OpenRegister\Service\Decision\DecisionVisibility;
use OCA\OpenRegister\Service\Decision\RelationDecisionService;
use OCA\OpenRegister\Service\Decision\ReviewPolicyResolver;
use OCA\OpenRegister\Service\File\ManagedFileOwnerLookup;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Settings\FileSettingsHandler;
use OCP\AppFramework\Http;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\IRootFolder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Through `PATCH /api/entity-relations/{id}`: a reviewer's decision is its own
 * object, and under `decider` ownership only the decider, a supervisor or an
 * administrator changes it (REQ-ROD-001, decision 197).
 *
 * Real controller, real RelationDecisionService, DecisionOwnership,
 * DecisionService and ReviewPolicyResolver; the stores are in-memory.
 *
 * @covers \OCA\OpenRegister\Controller\EntityRelationsController
 * @covers \OCA\OpenRegister\Service\Decision\RelationDecisionService
 * @covers \OCA\OpenRegister\Service\Decision\DecisionOwnedException
 * @uses   \OCA\OpenRegister\Service\Decision\DecisionOwnership
 * @uses   \OCA\OpenRegister\Service\Decision\DecisionService
 * @uses   \OCA\OpenRegister\Service\Decision\ReviewPolicy
 * @uses   \OCA\OpenRegister\Service\Decision\ReviewPolicyResolver
 * @uses   \OCA\OpenRegister\Event\DecisionChangeRequestedEvent
 * @uses   \OCA\OpenRegister\Service\Settings\FileSettingsHandler
 * @uses   \OCA\OpenRegister\Db\Decision
 * @uses   \OCA\OpenRegister\Db\EntityRelation
 *
 * @spec openspec/changes/reviewer-owns-their-decisions/specs/entity-relation-grondslagen/spec.md#requirement-a-reviewers-decision-is-changed-only-by-that-reviewer-or-a-supervisor-req-rod-001
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Exception\CustomValidationException
 * @uses \OCA\OpenRegister\Service\Decision\DecisionVisibility
 */
class DecisionOwnershipTest extends TestCase {

	/**
	 * The relation under review, on an object of schema 12.
	 *
	 * @var EntityRelation
	 */
	private EntityRelation $relation;

	/**
	 * Decisions stored, by uuid.
	 *
	 * @var array<string, Decision>
	 */
	private array $decisions = [];

	/**
	 * Schema 12's configuration.
	 *
	 * @var array<string, mixed>
	 */
	private array $schemaConfiguration = [
		'x-openregister-review' => ['ownership' => 'decider', 'supervisors' => ['woo-coordinator'], 'hideOthers' => false],
	];

	/**
	 * Group memberships by user.
	 *
	 * @var array<string, array<string>>
	 */
	private array $groups = ['coord' => ['woo-coordinator'], 'root' => ['admin']];

	/**
	 * Whether the guarded link finds the link already taken, as a concurrent first decision would.
	 *
	 * @var bool
	 */
	private bool $linkConflict = false;

	/**
	 * Set up a relation every reviewer may write.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->relation = new EntityRelation();
		$this->relation->setId(7);
		$this->relation->setObjectId(5);
		$this->relation->setRegisterId('3');
		$this->relation->setSchemaId('12');
	}//end setUp()

	/**
	 * The controller as `$uid` sees it.
	 *
	 * @param string $uid The signed-in user.
	 *
	 * @return EntityRelationsController The controller.
	 */
	private function controllerAs(string $uid, array $body): EntityRelationsController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn('application/json');
		$request->method('getParams')->willReturn($body + ['id' => 7, '_route' => 'entityRelations#update']);

		$relations = $this->createMock(EntityRelationMapper::class);
		$relations->method('find')->willReturnCallback(fn (): EntityRelation => $this->relation);
		$relations->method('updateDecisionMetadata')->willReturnCallback(
			static function (EntityRelation $relation, array $fields): EntityRelation {
				if (array_key_exists('bases', $fields) === true) {
					$relation->setBases($fields['bases']);
				}

				if (array_key_exists('skipAnonymization', $fields) === true) {
					$relation->setSkipAnonymization($fields['skipAnonymization']);
				}

				return $relation;
			}
		);
		$relations->method('linkDecision')->willReturnCallback(
			function (EntityRelation $relation, string $decisionUuid, ?string $expectedUuid = null, bool $guarded = false): EntityRelation {
				if ($guarded === true && $this->linkConflict === true) {
					throw new \OCA\OpenRegister\Exception\DecisionConflictException('taken');
				}

				$relation->setDecisionUuid($decisionUuid);
				return $relation;
			}
		);

		// Every reviewer may write the relation's object: the subject check passes.
		$schema = new Schema();
		$schema->setConfiguration($this->schemaConfiguration);
		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('find')->willReturn($schema);
		$magic = $this->createMock(MagicMapper::class);
		$magic->method('find')->willReturn(new ObjectEntity());
		$permissions = $this->createMock(PermissionHandler::class);
		$permissions->method('hasPermission')->willReturn(true);

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(fn (string $id): bool => in_array('admin', ($this->groups[$id] ?? []), true));
		$groups->method('isInGroup')->willReturnCallback(fn (string $id, string $gid): bool => in_array($gid, ($this->groups[$id] ?? []), true));

		$decisionMapper = $this->createMock(DecisionMapper::class);
		$decisionMapper->method('findByUuid')->willReturnCallback(fn (string $uuid): Decision => ($this->decisions[$uuid] ?? throw new \OCP\AppFramework\Db\DoesNotExistException('gone')));
		$decisionMapper->method('insert')->willReturnCallback(
			function (Decision $decision): Decision {
				$decision->setUuid('d-'.(count($this->decisions) + 1));
				$decision->setDecidedAt(new DateTime('2026-10-11T10:00:00+00:00'));
				$this->decisions[(string)$decision->getUuid()] = $decision;
				return $decision;
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$decide = new RelationDecisionService(
			relationMapper: $relations,
			decisions: new DecisionService(decisionMapper: $decisionMapper),
			ownership: new DecisionOwnership(groupManager: $groups, eventDispatcher: $this->createMock(IEventDispatcher::class), logger: $logger),
			policies: new ReviewPolicyResolver(
				schemaMapper: $schemas,
				fileSettings: new FileSettingsHandler(appConfig: $this->createMock(IAppConfig::class)),
				rootFolder: $this->createMock(IRootFolder::class),
				owners: $this->createMock(ManagedFileOwnerLookup::class),
				logger: $logger
			),
			db: $this->createMock(IDBConnection::class)
		);

		return new EntityRelationsController(
			'openregister',
			$request,
			$relations,
			$session,
			$this->createMock(IRootFolder::class),
			$permissions,
			$magic,
			$schemas,
			$this->createMock(RegisterMapper::class),
			$this->createMock(EmailLinkMapper::class),
			$logger,
			$decide,
			new DecisionVisibility(
				resolver: new ReviewPolicyResolver(
					schemaMapper: $schemas,
					fileSettings: new FileSettingsHandler(appConfig: $this->createMock(IAppConfig::class)),
					rootFolder: $this->createMock(IRootFolder::class),
					owners: $this->createMock(ManagedFileOwnerLookup::class),
					logger: $logger
				),
				decisions: new DecisionService(decisionMapper: $decisionMapper),
				groupManager: $groups,
				eventDispatcher: $this->createMock(IEventDispatcher::class),
				logger: $logger
			)
		);
	}//end controllerAs()

	/**
	 * j.devries redacts the name under 5.1.2e.
	 *
	 * @return void
	 */
	private function firstReviewerDecides(): void {
		$response = $this->controllerAs(uid: 'j.devries', body: ['bases' => ['5.1.2e'], 'skipAnonymization' => false, 'decisionNote' => 'Private person.'])->update(7);
		self::assertSame(Http::STATUS_OK, $response->getStatus());
	}//end firstReviewerDecides()

	/**
	 * A second reviewer is refused, told who decided and when, and nothing changes.
	 *
	 * @return void
	 */
	public function testASecondReviewerIsRefused(): void {
		$this->firstReviewerDecides();

		$response = $this->controllerAs(uid: 'p.bakker', body: ['skipAnonymization' => true])->update(7);

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		self::assertSame(
			['error' => 'decision-owned', 'decision' => 'd-1', 'decidedBy' => 'j.devries', 'decidedAt' => '2026-10-11T10:00:00+00:00'],
			$response->getData()
		);
		self::assertFalse($this->relation->getSkipAnonymization());
		self::assertSame('d-1', $this->relation->getDecisionUuid());
		self::assertCount(1, $this->decisions);
	}//end testASecondReviewerIsRefused()

	/**
	 * Under hideOthers the PATCH refusal names neither who decided nor when (REQ-ROD-002).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/reviewer-owns-their-decisions/specs/entity-relation-grondslagen/spec.md#requirement-a-schema-can-hide-one-reviewers-decisions-from-another-req-rod-002
	 */
	public function testUnderHideOthersTheRefusalNamesNoDecider(): void {
		$this->schemaConfiguration['x-openregister-review']['hideOthers'] = true;
		$this->firstReviewerDecides();

		$response = $this->controllerAs(uid: 'p.bakker', body: ['skipAnonymization' => true])->update(7);

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		self::assertSame(['error' => 'decision-owned', 'decision' => 'd-1'], $response->getData());
		self::assertStringNotContainsString('j.devries', (string)json_encode($response->getData()));
	}//end testUnderHideOthersTheRefusalNamesNoDecider()

	/**
	 * A coordinator's change is a new decision, by the coordinator, superseding the first.
	 *
	 * @return void
	 */
	public function testASupervisorMayChangeAnotherReviewersDecision(): void {
		$this->firstReviewerDecides();

		$response = $this->controllerAs(uid: 'coord', body: ['skipAnonymization' => true])->update(7);

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('d-2', $this->relation->getDecisionUuid());
		$decision = $this->decisions['d-2'];
		self::assertSame('coord', $decision->getDecidedBy());
		self::assertSame('release', $decision->getVerdict());
		self::assertSame('d-1', $decision->getSupersedes());
		self::assertNull($decision->getNote(), 'The first reviewer\'s note does not pass to the coordinator.');
		self::assertSame('j.devries', $this->decisions['d-1']->getDecidedBy(), 'The first decision is kept as it was.');
		self::assertSame('d-2', $response->getData()['decisionRecord']['uuid'] ?? null);
	}//end testASupervisorMayChangeAnotherReviewersDecision()

	/**
	 * An administrator may change it.
	 *
	 * @return void
	 */
	public function testAnAdministratorMay(): void {
		$this->firstReviewerDecides();

		$response = $this->controllerAs(uid: 'root', body: ['bases' => ['5.1.2a']])->update(7);

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('root', $this->decisions['d-2']->getDecidedBy());
	}//end testAnAdministratorMay()

	/**
	 * An undecided relation is decided by whoever may write it, as today.
	 *
	 * @return void
	 */
	public function testADanglingDecisionLinkIsRefusedForAReviewer(): void {
		$this->schemaConfiguration = ['x-openregister-review' => ['ownership' => 'decider', 'supervisors' => ['woo-coordinator']]];
		// The relation names a decision whose record is gone.
		$this->relation->setDecisionUuid('d-gone');

		$response = $this->controllerAs('alice', ['bases' => ['A']])->update(7);
		$this->assertSame(403, $response->getStatus());
		$this->assertSame('decision-owned', $response->getData()['error']);
		$this->assertEmpty($this->decisions);

		// A supervisor may take it over.
		$this->assertSame(200, $this->controllerAs('coord', ['bases' => ['A']])->update(7)->getStatus());
	}//end testADanglingDecisionLinkIsRefusedForAReviewer()

	/**
	 * The link is guarded: when somebody else linked a decision first, the second is refused and nothing is recorded as linked.
	 */
	public function testASecondConcurrentFirstDecisionIsRefused(): void {
		$this->schemaConfiguration = ['x-openregister-review' => ['ownership' => 'decider']];
		$this->linkConflict = true;

		$response = $this->controllerAs('alice', ['bases' => ['A']])->update(7);

		$this->assertSame(403, $response->getStatus());
		$this->assertSame('decision-owned', $response->getData()['error']);
		$this->assertNull($this->relation->getDecisionUuid());
	}//end testASecondConcurrentFirstDecisionIsRefused()

	public function testAnUndecidedRelationIsOpen(): void {
		$response = $this->controllerAs(uid: 'p.bakker', body: ['skipAnonymization' => false])->update(7);

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('d-1', $this->relation->getDecisionUuid(), 'Confirming the default is a decision too.');
		self::assertSame('p.bakker', $this->decisions['d-1']->getDecidedBy());
		self::assertSame('redact', $this->decisions['d-1']->getVerdict());
		self::assertSame('d-1', $response->getData()['decision']);
	}//end testAnUndecidedRelationIsOpen()

	/**
	 * Under open ownership a second reviewer may change the first's decision.
	 *
	 * @return void
	 */
	public function testOpenOwnershipKeepsTodaysBehaviour(): void {
		$this->schemaConfiguration = [];
		$this->firstReviewerDecides();

		$response = $this->controllerAs(uid: 'p.bakker', body: ['skipAnonymization' => true])->update(7);

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertTrue($this->relation->getSkipAnonymization());
		self::assertSame('p.bakker', $this->decisions['d-2']->getDecidedBy());
	}//end testOpenOwnershipKeepsTodaysBehaviour()

	/**
	 * The note is the decider's: kept on their own change, refused to another, malformed refused.
	 *
	 * @return void
	 */
	public function testTheNoteFollowsTheDecision(): void {
		$this->firstReviewerDecides();
		self::assertSame('Private person.', $this->decisions['d-1']->getNote());

		// Another reviewer may not even change the note.
		$refused = $this->controllerAs(uid: 'p.bakker', body: ['decisionNote' => 'I disagree.'])->update(7);
		self::assertSame(Http::STATUS_FORBIDDEN, $refused->getStatus());

		// The decider changing their verdict keeps their note.
		$this->controllerAs(uid: 'j.devries', body: ['bases' => ['5.1.2e', '5.1.2i']])->update(7);
		self::assertSame('Private person.', $this->decisions['d-2']->getNote());

		// Repeating the same decision records nothing new.
		$this->controllerAs(uid: 'j.devries', body: ['bases' => ['5.1.2e', '5.1.2i']])->update(7);
		self::assertCount(2, $this->decisions);

		// A note that is not text is refused with 400.
		$malformed = $this->controllerAs(uid: 'j.devries', body: ['decisionNote' => ['x']])->update(7);
		self::assertSame(Http::STATUS_BAD_REQUEST, $malformed->getStatus());
	}//end testTheNoteFollowsTheDecision()
}//end class
