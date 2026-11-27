<?php

/**
 * Unit tests for the export-key routes of the admin settings.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/exchange-encrypted-instance-export/specs/data-import-export/spec.md#requirement-export-keys-are-kept-in-the-credential-broker
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\ExportKeysController;
use OCA\OpenRegister\Kernel\Contracts\ActiveOrganisationReaderInterface;
use OCA\OpenRegister\Service\Exchange\ExportKeyCreated;
use OCA\OpenRegister\Service\Exchange\ExportKeyDirectory;
use OCA\OpenRegister\Service\Exchange\ExportKeyException;
use OCA\OpenRegister\Service\Exchange\ExportKeyService;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Controller\ExportKeysController
 * @uses   \OCA\OpenRegister\Service\Exchange\ExportKeyCreated
 * @uses   \OCA\OpenRegister\Service\Exchange\ExportKeyException
 */
class ExportKeysControllerTest extends TestCase {

	/** @var ExportKeyService&MockObject */
	private ExportKeyService $exportKeys;

	/** @var ExportKeyDirectory&MockObject */
	private ExportKeyDirectory $directory;

	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;

	/**
	 * Fresh collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->exportKeys = $this->createMock(ExportKeyService::class);
		$this->directory  = $this->createMock(ExportKeyDirectory::class);
		$this->logger     = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * The controller for a caller.
	 *
	 * @param string|null $uid          The signed-in user, or null for none.
	 * @param bool        $admin        Whether they are an administrator.
	 * @param string|null $organisation Their active organisation, or null for none.
	 *
	 * @return ExportKeysController
	 */
	private function controller(?string $uid = 'admin', bool $admin = true, ?string $organisation = 'org-a'): ExportKeysController {
		$session = $this->createMock(IUserSession::class);
		if ($uid === null) {
			$session->method('getUser')->willReturn(null);
		} else {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$session->method('getUser')->willReturn($user);
		}

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(static fn (string $who): bool => ($who === $uid && $admin));

		$organisations = $this->createMock(ActiveOrganisationReaderInterface::class);
		$organisations->method('activeOrganisationUuid')->willReturn($organisation);

		return new ExportKeysController(
			appName: 'openregister',
			request: $this->createMock(IRequest::class),
			userSession: $session,
			groups: $groups,
			organisation: $organisations,
			exportKeys: $this->exportKeys,
			directory: $this->directory,
			logger: $this->logger
		);
	}//end controller()

	/**
	 * The list is the active organisation's keys, without a recovery string.
	 *
	 * @return void
	 */
	public function testIndexListsTheActiveOrganisationsKeys(): void {
		$rows = [['id' => 'k1', 'fingerprint' => '0123456789abcdef', 'createdAt' => '2026-01-01T10:00:00+00:00']];
		$this->directory->expects($this->once())->method('list')->with('org-a')->willReturn($rows);

		$response = $this->controller()->index();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['results' => $rows, 'organisation' => 'org-a'], $response->getData());
	}//end testIndexListsTheActiveOrganisationsKeys()

	/**
	 * Create answers 201 with the recovery string once, and forbids caching it.
	 *
	 * @return void
	 */
	public function testCreateShowsTheRecoveryStringOnceAndIsNotCached(): void {
		$this->exportKeys->expects($this->once())->method('create')->with('org-a', 'admin')
			->willReturn(new ExportKeyCreated(keyId: 'k2', fingerprint: 'fedcba9876543210', recovery: 'RECOVERY'));

		$response = $this->controller()->create();

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame(['id' => 'k2', 'fingerprint' => 'fedcba9876543210', 'recovery' => 'RECOVERY'], $response->getData());
		$this->assertSame('no-store', $response->getHeaders()['Cache-Control']);
	}//end testCreateShowsTheRecoveryStringOnceAndIsNotCached()

	/**
	 * Rotate names the rotated-out key and shows the new key's recovery string.
	 *
	 * @return void
	 */
	public function testRotateNamesThePreviousKey(): void {
		$this->directory->method('list')->with('org-a')->willReturn([['id' => 'k1', 'fingerprint' => null, 'createdAt' => null]]);
		$this->exportKeys->expects($this->once())->method('rotate')->with('k1', 'org-a', 'admin')
			->willReturn(new ExportKeyCreated(keyId: 'k2', fingerprint: 'fedcba9876543210', recovery: 'RECOVERY'));

		$response = $this->controller()->rotate(id: 'k1');

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame(
			['id' => 'k2', 'fingerprint' => 'fedcba9876543210', 'recovery' => 'RECOVERY', 'previousId' => 'k1'],
			$response->getData()
		);
		$this->assertSame('no-store', $response->getHeaders()['Cache-Control']);
	}//end testRotateNamesThePreviousKey()

	/**
	 * Delete answers 200 when the key and its secret are gone, 500 when only part went.
	 *
	 * @return void
	 */
	public function testDestroyReportsWhetherTheKeyIsGone(): void {
		$this->directory->method('list')->with('org-a')->willReturn([['id' => 'k1', 'fingerprint' => null, 'createdAt' => null]]);
		$this->exportKeys->expects($this->exactly(2))->method('delete')->with('k1', 'org-a', 'admin')
			->willReturnOnConsecutiveCalls(true, false);

		$gone = $this->controller()->destroy(id: 'k1');
		$this->assertSame(Http::STATUS_OK, $gone->getStatus());
		$this->assertSame(['id' => 'k1', 'removed' => true], $gone->getData());

		$partly = $this->controller()->destroy(id: 'k1');
		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $partly->getStatus());
		$this->assertSame(['id' => 'k1', 'removed' => false], $partly->getData());
	}//end testDestroyReportsWhetherTheKeyIsGone()

	/**
	 * No session is 401, and nothing is read or written.
	 *
	 * @return void
	 */
	public function testAnAnonymousCallerIsRefusedWith401(): void {
		$this->directory->expects($this->never())->method('list');
		$this->exportKeys->expects($this->never())->method('create');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller(uid: null)->index()->getStatus());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller(uid: null)->create()->getStatus());
	}//end testAnAnonymousCallerIsRefusedWith401()

	/**
	 * A signed-in non-administrator is 403 on every route, and nothing is read or written.
	 *
	 * @return void
	 */
	public function testANonAdministratorIsRefusedOnEveryRoute(): void {
		$this->directory->expects($this->never())->method('list');
		$this->exportKeys->expects($this->never())->method('create');
		$this->exportKeys->expects($this->never())->method('rotate');
		$this->exportKeys->expects($this->never())->method('delete');
		$controller = $this->controller(uid: 'bea', admin: false);

		foreach ([$controller->index(), $controller->create(), $controller->rotate(id: 'k1'), $controller->destroy(id: 'k1')] as $response) {
			$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
			$this->assertSame(['message' => 'Only administrators may manage export keys.'], $response->getData());
		}
	}//end testANonAdministratorIsRefusedOnEveryRoute()

	/**
	 * Without an active organisation the answer is 409, naming the remedy.
	 *
	 * @return void
	 */
	public function testNoActiveOrganisationIsAConflict(): void {
		$this->exportKeys->expects($this->never())->method('create');

		$response = $this->controller(organisation: null)->create();

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertStringContainsString('active organisation', $response->getData()['message']);
	}//end testNoActiveOrganisationIsAConflict()

	/**
	 * A credential that is not an export key is 404.
	 *
	 * @return void
	 */
	public function testAListedCredentialThatIsNoExportKeyIs404(): void {
		$this->directory->method('list')->willReturn([['id' => 'k1', 'fingerprint' => null, 'createdAt' => null]]);
		$this->exportKeys->method('delete')->willThrowException(new ExportKeyException());

		$response = $this->controller()->destroy(id: 'k1');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['message' => 'No such export key.'], $response->getData());
	}//end testAListedCredentialThatIsNoExportKeyIs404()

	/**
	 * A key outside the organisation's own list, another organisation's or none at all,
	 * is 404 for rotate and delete, and the service is never asked.
	 *
	 * @return void
	 */
	public function testAKeyOutsideTheOrganisationsListIs404AndUntouched(): void {
		$this->directory->method('list')->with('org-a')->willReturn([['id' => 'mine', 'fingerprint' => null, 'createdAt' => null]]);
		$this->exportKeys->expects($this->never())->method('rotate');
		$this->exportKeys->expects($this->never())->method('delete');
		$controller = $this->controller();

		foreach ([$controller->rotate(id: 'theirs'), $controller->destroy(id: 'theirs')] as $response) {
			$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
			$this->assertSame(['message' => 'No such export key.'], $response->getData());
		}
	}//end testAKeyOutsideTheOrganisationsListIs404AndUntouched()

	/**
	 * An unexpected failure is 500 without its message, and is logged by class only.
	 *
	 * @return void
	 */
	public function testAnUnexpectedFailureIs500WithoutItsMessage(): void {
		$this->exportKeys->method('create')->willThrowException(new RuntimeException('secret detail'));
		$this->logger->expects($this->once())->method('error')->with(
			'[ExportKeysController] an export key action failed',
			['reason' => RuntimeException::class]
		);

		$response = $this->controller()->create();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['message' => 'The export key action failed.'], $response->getData());
	}//end testAnUnexpectedFailureIs500WithoutItsMessage()
}//end class
