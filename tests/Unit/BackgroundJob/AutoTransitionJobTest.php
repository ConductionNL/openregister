<?php

/**
 * Applying a queued automatic transition off-request.
 *
 * The job does not re-evaluate the rule: it reconciles against current state.
 * A newer write means the decision this entry carries is stale and the entry
 * is dropped, because that newer write made its own decision. And the pass
 * travels with the entry, so a loop cannot escape the cap by crossing into a
 * background job.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/lifecycle-auto-transitions/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace Unit\BackgroundJob;

use DateTime;
use OCA\OpenRegister\BackgroundJob\AutoTransitionJob;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Deferral\DeferredEntryObjectResolver;
use OCA\OpenRegister\Service\Deferral\DeferredListenerContext;
use OCA\OpenRegister\Service\Lifecycle\AutoTransitionPass;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * The queued move, and the three reasons it is not applied.
 */
class AutoTransitionJobTest extends TestCase {

	private const UPDATED = '2026-09-11T09:00:00+00:00';

	private IUserManager&MockObject $userManager;

	private DeferredEntryObjectResolver&MockObject $resolver;

	private TransitionEngine&MockObject $engine;

	private AutoTransitionPass&MockObject $pass;

	private LoggerInterface&MockObject $logger;

	private AutoTransitionJob $job;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->userManager = $this->createMock(IUserManager::class);
		$this->resolver = $this->createMock(DeferredEntryObjectResolver::class);
		$this->engine = $this->createMock(TransitionEngine::class);
		$this->pass = $this->createMock(AutoTransitionPass::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->job = new AutoTransitionJob(
			$this->createMock(ITimeFactory::class),
			$this->createMock(IUserSession::class),
			$this->userManager,
			$this->createMock(OrganisationService::class),
			$this->logger,
			$this->resolver,
			$this->engine,
			$this->pass
		);
	}//end setUp()

	/**
	 * Run the protected deferred work directly.
	 *
	 * The identity plumbing around it is `ActorForwardedJob`'s and is tested
	 * there; what is exercised here is what this subclass adds.
	 *
	 * @param array<int, array<string, mixed>> $entries The queued entries.
	 * @param string|null $userId The captured acting user.
	 *
	 * @return void
	 */
	private function runDeferred(array $entries, ?string $userId = 'behandelaar-1'): void {
		$context = new DeferredListenerContext(userId: $userId, orgUuid: null, entries: $entries);
		$method = new ReflectionMethod(AutoTransitionJob::class, 'runDeferred');
		$method->invoke($this->job, $context);
	}//end runDeferred()

	/**
	 * One queued entry for `beslissen` on obj-1.
	 *
	 * @param array<string, mixed> $overrides Fields to override.
	 *
	 * @return array<string, mixed>
	 */
	private function entry(array $overrides = []): array {
		return ($overrides + [
			'uuid' => 'obj-1',
			'register' => '1',
			'schema' => '2',
			'action' => 'beslissen',
			'to' => 'besloten',
			'version' => '3',
			'updated' => self::UPDATED,
			'moves' => 1,
			'visited' => ['open', 'besloten'],
		]);
	}//end entry()

	/**
	 * Make the resolver answer with a stored object at a version and stamp.
	 *
	 * @param string $version The stored version.
	 * @param string $updated The stored `updated` stamp, ISO 8601.
	 *
	 * @return void
	 */
	private function stored(string $version = '3', string $updated = self::UPDATED): void {
		$entity = new ObjectEntity();
		$entity->setUuid('obj-1');
		$entity->setVersion($version);
		$entity->setUpdated(new DateTime($updated));

		$this->resolver->method('resolve')->willReturn($entity);
	}//end stored()

	/**
	 * @return void
	 */
	public function testAnUnchangedObjectHasItsQueuedMoveApplied(): void {
		$this->userManager->method('get')->willReturn($this->enabledUser());
		$this->stored();

		$this->engine->expects($this->once())
			->method('transition')
			->with(objectId: 'obj-1', action: 'beslissen');

		$this->runDeferred([$this->entry()]);
	}//end testAnUnchangedObjectHasItsQueuedMoveApplied()

	/**
	 * @return void
	 */
	public function testAStaleVersionIsANoOp(): void {
		// A later write made its own decision; this one is not re-imposed.
		$this->userManager->method('get')->willReturn($this->enabledUser());
		$this->stored(version: '4');

		$this->engine->expects($this->never())->method('transition');

		$this->runDeferred([$this->entry()]);
	}//end testAStaleVersionIsANoOp()

	/**
	 * @return void
	 */
	public function testAStaleTimestampIsANoOp(): void {
		$this->userManager->method('get')->willReturn($this->enabledUser());
		$this->stored(updated: '2026-09-11T10:00:00+00:00');

		$this->engine->expects($this->never())->method('transition');

		$this->runDeferred([$this->entry()]);
	}//end testAStaleTimestampIsANoOp()

	/**
	 * @return void
	 */
	public function testAVanishedObjectIsANoOp(): void {
		$this->userManager->method('get')->willReturn($this->enabledUser());
		$this->resolver->method('resolve')->willReturn(null);

		$this->engine->expects($this->never())->method('transition');

		$this->runDeferred([$this->entry()]);
	}//end testAVanishedObjectIsANoOp()

	/**
	 * @return void
	 */
	public function testADisabledAccountIsSkippedWithAWarning(): void {
		// 🔴 ActorForwardedJob refuses a user that no longer resolves, but not
		// a disabled one. An automatic move must never run as an account
		// someone has deliberately switched off.
		$user = $this->createMock(IUser::class);
		$user->method('isEnabled')->willReturn(false);
		$this->userManager->method('get')->willReturn($user);

		$this->engine->expects($this->never())->method('transition');
		$this->logger->expects($this->once())
			->method('warning')
			->with($this->stringContains('gone or disabled'), $this->anything());

		$this->runDeferred([$this->entry()]);
	}//end testADisabledAccountIsSkippedWithAWarning()

	/**
	 * @return void
	 */
	public function testTheCarriedLineageResumesThePass(): void {
		// The count and the visited states travel with the entry, so what
		// follows the queued move is bounded by the same pass.
		$this->userManager->method('get')->willReturn($this->enabledUser());
		$this->stored();

		$this->pass->expects($this->once())
			->method('resume')
			->with(uuid: 'obj-1', moves: 9, visited: ['s0', 's9']);

		$this->runDeferred([$this->entry(['moves' => 9, 'visited' => ['s0', 's9']])]);
	}//end testTheCarriedLineageResumesThePass()

	/**
	 * @return void
	 */
	public function testARefusedQueuedMoveDoesNotAbortTheChunk(): void {
		$this->userManager->method('get')->willReturn($this->enabledUser());
		$this->stored();
		$this->engine->method('transition')->willThrowException(new \RuntimeException('refused'));

		$this->logger->expects($this->atLeastOnce())->method('warning');

		$this->runDeferred([$this->entry(), $this->entry()]);
	}//end testARefusedQueuedMoveDoesNotAbortTheChunk()

	/**
	 * @return void
	 */
	public function testASessionLessOriginNeedsNoAccountCheck(): void {
		$this->stored();
		$this->engine->expects($this->once())->method('transition');

		$this->runDeferred([$this->entry()], null);
	}//end testASessionLessOriginNeedsNoAccountCheck()

	/**
	 * An enabled user mock.
	 *
	 * @return IUser&MockObject
	 */
	private function enabledUser(): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('isEnabled')->willReturn(true);

		return $user;
	}//end enabledUser()
}//end class
