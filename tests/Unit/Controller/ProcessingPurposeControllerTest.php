<?php

/**
 * Unit tests for the administered purpose list as an API.
 *
 * Covers who may read the list and who may change it, the delete that retires
 * rather than removes, and the per-purpose count that reports unattributed
 * reads instead of hiding them.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/verwerkingsregister-api/spec.md
 */

declare(strict_types=1);

namespace Unit\Controller;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Controller\ProcessingPurposeController;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ProcessingPurpose;
use OCA\OpenRegister\Db\ProcessingPurposeMapper;
use OCA\OpenRegister\Db\Verwerkingsactiviteit;
use OCA\OpenRegister\Service\Audit\PurposeRegistry;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

final class ProcessingPurposeControllerTest extends TestCase {
	private function purpose(string $code = 'brp-adresonderzoek'): ProcessingPurpose {
		$purpose = new ProcessingPurpose();
		$purpose->setId(1);
		$purpose->setUuid('purpose-uuid');
		$purpose->setCode($code);
		$purpose->setActivity('VA-01');
		$purpose->setStatus(ProcessingPurpose::STATUS_ACTIVE);

		return $purpose;
	}//end purpose()

	private function activity(): Verwerkingsactiviteit {
		$activity = new Verwerkingsactiviteit();
		$activity->setUuid('activity-uuid');
		$activity->setCode('VA-01');
		$activity->setName('Adresonderzoek BRP');

		return $activity;
	}//end activity()

	private function session(?string $uid): IUserSession {
		$session = $this->createMock(IUserSession::class);
		if ($uid === null) {
			$session->method('getUser')->willReturn(null);

			return $session;
		}

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$session->method('getUser')->willReturn($user);

		return $session;
	}//end session()

	private function groupManager(bool $admin): IGroupManager {
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('getUserGroupIds')->willReturn($admin === true ? ['admin'] : ['users']);

		return $groups;
	}//end groupManager()

	private function controller(
		ProcessingPurposeMapper $purposes,
		?string $uid = 'jan',
		bool $admin = false,
		array $params = [],
		?AuditTrailMapper $audit = null,
		?Verwerkingsactiviteit $bound = null,
	): ProcessingPurposeController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static function (string $key, $default = null) use ($params) {
				return ($params[$key] ?? $default);
			}
		);

		$registry = $this->createMock(PurposeRegistry::class);
		$registry->method('boundActivity')->willReturn($bound);

		return new ProcessingPurposeController(
			'openregister',
			$request,
			$purposes,
			$registry,
			($audit ?? $this->createMock(AuditTrailMapper::class)),
			$this->session($uid),
			$this->groupManager($admin)
		);
	}//end controller()

	public function testAnyAuthenticatedCallerMaySeeThePurposesItCouldName(): void {
		// A client that cannot read the list cannot name a purpose, and a
		// refusal offering codes it may not read is a refusal nobody can act on.
		$mapper = $this->createMock(ProcessingPurposeMapper::class);
		$mapper->method('findAll')->willReturn([$this->purpose()]);

		$response = $this->controller($mapper, uid: 'jan', admin: false, bound: $this->activity())->index();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		$body = $response->getData();
		self::assertSame(1, $body['count']);
		self::assertSame('brp-adresonderzoek', $body['results'][0]['code']);
		self::assertTrue($body['results'][0]['bound']);
		self::assertTrue($body['results'][0]['usable']);
		self::assertSame('Adresonderzoek BRP', $body['results'][0]['activityName']);
	}//end testAnyAuthenticatedCallerMaySeeThePurposesItCouldName()

	public function testAnUnboundPurposeIsPublishedAsUnusableRatherThanHidden(): void {
		$mapper = $this->createMock(ProcessingPurposeMapper::class);
		$mapper->method('findAll')->willReturn([$this->purpose()]);

		$body = $this->controller($mapper, bound: null)->index()->getData();

		self::assertFalse($body['results'][0]['bound']);
		self::assertFalse($body['results'][0]['usable']);
	}//end testAnUnboundPurposeIsPublishedAsUnusableRatherThanHidden()

	public function testAnAnonymousCallerSeesNothing(): void {
		$mapper = $this->createMock(ProcessingPurposeMapper::class);

		$response = $this->controller($mapper, uid: null)->index();

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}//end testAnAnonymousCallerSeesNothing()

	public function testOnlyAnAdministratorMayAddAPurpose(): void {
		$mapper = $this->createMock(ProcessingPurposeMapper::class);
		$mapper->expects(self::never())->method('insert');

		$response = $this->controller($mapper, admin: false, params: ['code' => 'nieuw'])->create();

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testOnlyAnAdministratorMayAddAPurpose()

	public function testAnAdministratorAddsAPurposeAndItsBindingIsResolvedOnWrite(): void {
		$mapper = $this->createMock(ProcessingPurposeMapper::class);
		$mapper->method('findByCode')->willReturn(null);
		$mapper->method('insert')->willReturnCallback(
			static function (ProcessingPurpose $purpose): ProcessingPurpose {
				$purpose->setId(7);

				return $purpose;
			}
		);

		$response = $this->controller(
			$mapper,
			admin: true,
			params: ['code' => 'kvk-controle', 'name' => 'KvK controle', 'activity' => 'VA-01'],
			bound: $this->activity()
		)->create();

		self::assertSame(Http::STATUS_CREATED, $response->getStatus());
		$body = $response->getData();
		self::assertSame('kvk-controle', $body['code']);
		// Resolved at administration time, so the administrator finds out now
		// rather than when a query is refused in production.
		self::assertSame('activity-uuid', $body['activityUuid']);
	}//end testAnAdministratorAddsAPurposeAndItsBindingIsResolvedOnWrite()

	public function testAPurposeWithoutACodeIsRefused(): void {
		$mapper = $this->createMock(ProcessingPurposeMapper::class);

		$response = $this->controller($mapper, admin: true, params: [])->create();

		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
	}//end testAPurposeWithoutACodeIsRefused()

	public function testADuplicateCodeIsRefusedRatherThanShadowingTheOriginal(): void {
		$mapper = $this->createMock(ProcessingPurposeMapper::class);
		$mapper->method('findByCode')->willReturn($this->purpose());
		$mapper->expects(self::never())->method('insert');

		$response = $this->controller(
			$mapper,
			admin: true,
			params: ['code' => 'brp-adresonderzoek']
		)->create();

		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
	}//end testADuplicateCodeIsRefusedRatherThanShadowingTheOriginal()

	public function testDeletingAPurposeRetiresItSoOldEntriesStillNameSomething(): void {
		$retired = null;
		$mapper = $this->createMock(ProcessingPurposeMapper::class);
		$mapper->method('resolveReference')->willReturn($this->purpose());
		$mapper->method('update')->willReturnCallback(
			static function (ProcessingPurpose $purpose) use (&$retired): ProcessingPurpose {
				$retired = $purpose->getStatus();

				return $purpose;
			}
		);

		$response = $this->controller($mapper, admin: true)->destroy('brp-adresonderzoek');

		self::assertSame(Http::STATUS_NO_CONTENT, $response->getStatus());
		self::assertSame(ProcessingPurpose::STATUS_RETIRED, $retired);
	}//end testDeletingAPurposeRetiresItSoOldEntriesStillNameSomething()

	public function testAnUnknownIdentifierIsNotFound(): void {
		$mapper = $this->createMock(ProcessingPurposeMapper::class);
		$mapper->method('resolveReference')->willReturn(null);

		$response = $this->controller($mapper)->show('does-not-exist');

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testAnUnknownIdentifierIsNotFound()

	public function testTheReportCountsEachPurposeAndNamesTheUnattributedReads(): void {
		// A report that silently omits the reads nobody declared a purpose for
		// says doelbinding is complete when it is not.
		$audit = $this->createMock(AuditTrailMapper::class);
		$audit->method('countByPurpose')->willReturn(
			['brp-adresonderzoek' => 12, 'kvk-controle' => 5, 'null' => 3]
		);

		$body = $this->controller(
			$this->createMock(ProcessingPurposeMapper::class),
			audit: $audit
		)->report()->getData();

		self::assertSame(12, $body['counts']['brp-adresonderzoek']);
		self::assertSame(5, $body['counts']['kvk-controle']);
		self::assertSame(3, $body['unattributed']);
		self::assertSame(20, $body['total']);
	}//end testTheReportCountsEachPurposeAndNamesTheUnattributedReads()
}//end class
