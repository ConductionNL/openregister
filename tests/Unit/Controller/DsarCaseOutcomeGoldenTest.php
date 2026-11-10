<?php

/**
 * Golden test pinning what DsarCaseController records for identity verification
 * and regulator escalation, and how a failed save is answered.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\DsarCaseController;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Gdpr\Case\CaseAccessControl;
use OCA\OpenRegister\Service\Gdpr\Case\CaseObjectAccessor;
use OCA\OpenRegister\Service\Gdpr\Evidence\EvidenceHarvestService;
use OCA\OpenRegister\Service\Gdpr\Export\ExportBundleService;
use OCA\OpenRegister\Service\Gdpr\Identity\IdentityVerifyProvider;
use OCA\OpenRegister\Service\Gdpr\Identity\IdentityVerifyRegistry;
use OCA\OpenRegister\Service\Gdpr\Identity\IdentityVerifyResult;
use OCA\OpenRegister\Service\Gdpr\Policy\DsarPolicyPackResolver;
use OCA\OpenRegister\Service\Gdpr\Redaction\RedactionWriteService;
use OCA\OpenRegister\Service\Gdpr\Regulator\RegulatorEscalateProvider;
use OCA\OpenRegister\Service\Gdpr\Regulator\RegulatorEscalateRegistry;
use OCA\OpenRegister\Service\Gdpr\Regulator\RegulatorEscalateResult;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Tests\Unit\Controller\Support\DsarCaseControllerFactory;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class DsarCaseOutcomeGoldenTest extends TestCase {

	private array $saved = [];

	private ?string $uid = 'handler1';

	private bool $mayAct = true;

	private function controller(?ObjectEntity $case, bool $saveFails, mixed $outcome): DsarCaseController {
		$accessor = $this->createMock(CaseObjectAccessor::class);
		$accessor->method('load')->willReturn($case);
		$accessor->method('save')->willReturnCallback(
			function ($loaded, array $data) use ($saveFails) {
				$this->saved[] = $data;
				if ($saveFails === true) {
					throw new RuntimeException('disk full');
				}

				return $loaded;
			}
		);

		$access = $this->createMock(CaseAccessControl::class);
		$access->method('mayAct')->willReturn($this->mayAct);

		$resolver = $this->createMock(DsarPolicyPackResolver::class);
		$resolver->method('identityVerifyProviderId')->willReturn('id-provider');
		$resolver->method('regulatorEscalateProviderId')->willReturn('reg-provider');

		$identity = $this->createMock(IdentityVerifyProvider::class);
		$regulator = $this->createMock(RegulatorEscalateProvider::class);
		if ($outcome instanceof IdentityVerifyResult) {
			$identity->method('verify')->willReturn($outcome);
		}

		if ($outcome instanceof RegulatorEscalateResult) {
			$regulator->method('escalate')->willReturn($outcome);
		}

		$identityRegistry = $this->createMock(IdentityVerifyRegistry::class);
		$identityRegistry->method('resolve')->willReturn($identity);
		$regulatorRegistry = $this->createMock(RegulatorEscalateRegistry::class);
		$regulatorRegistry->method('resolve')->willReturn($regulator);

		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($this->uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($this->uid);
		}

		$session->method('getUser')->willReturn($user);

		return DsarCaseControllerFactory::create(
			'openregister',
			$this->createMock(IRequest::class),
			$this->createMock(ObjectService::class),
			$accessor,
			$access,
			$this->createMock(TransitionEngine::class),
			$this->createMock(EvidenceHarvestService::class),
			$this->createMock(RedactionWriteService::class),
			$this->createMock(ExportBundleService::class),
			$session,
			$resolver,
			$identityRegistry,
			$regulatorRegistry
		);
	}

	private static function aCase(): ObjectEntity {
		$case = new ObjectEntity();
		$case->setObject(['handler' => 'handler1', 'jurisdiction' => 'default']);
		return $case;
	}

	public function testAPositiveEscalationRecordsItsReference(): void {
		$result = RegulatorEscalateResult::escalated('reg-provider', 'AP-2026-1');
		$response = $this->controller(self::aCase(), false, $result)->escalate('case-1');

		$this->assertSame([200, $result->toArray()], [$response->getStatus(), $response->getData()]);
		$this->assertSame($result->toArray(), $this->saved[0]['regulatorEscalation']);
		$this->assertSame('AP-2026-1', $this->saved[0]['regulatorReference']);
	}

	public function testAFailedSaveKeepsTheDecisionAndAnswers400(): void {
		$escalated = RegulatorEscalateResult::escalated('reg-provider', 'AP-2026-1');
		$escalation = $this->controller(self::aCase(), true, $escalated)->escalate('case-1');
		$this->assertSame(400, $escalation->getStatus());
		$this->assertSame(['error' => 'disk full', 'status' => $escalated->getStatus()], $escalation->getData());

		$verified = IdentityVerifyResult::verified('id-provider');
		$verification = $this->controller(self::aCase(), true, $verified)->identityVerify('case-1');
		$this->assertSame(400, $verification->getStatus());
		$this->assertSame(['error' => 'disk full', 'status' => $verified->getStatus()], $verification->getData());
		$this->assertSame($verified->toArray(), $this->saved[1]['identityVerification']);
	}

	public function testAVerificationIsRecordedAndAnswered(): void {
		$verified = IdentityVerifyResult::verified('id-provider');
		$response = $this->controller(self::aCase(), false, $verified)->identityVerify('case-1');

		$this->assertSame([200, $verified->toArray()], [$response->getStatus(), $response->getData()]);
		$this->assertSame(['id' => null, 'handler' => 'handler1', 'jurisdiction' => 'default', 'identityVerification' => $verified->toArray()], $this->saved[0]);
	}

	public function testAMissingCaseIsNotFoundAndNothingIsSaved(): void {
		$expected = [404, ['error' => 'Not found or not authorised.']];
		foreach (['escalate', 'identityVerify'] as $action) {
			$response = $this->controller(null, false, null)->$action('case-1');
			$this->assertSame($expected, [$response->getStatus(), $response->getData()]);
		}

		$this->assertSame([], $this->saved);
	}

	public function testTheCaseGuardRefusesBeforeAnythingIsDecidedOrSaved(): void {
		$escalated = RegulatorEscalateResult::escalated('reg-provider', 'AP-2026-1');
		$verified = IdentityVerifyResult::verified('id-provider');

		$this->mayAct = false;
		$denied = [403, ['error' => 'You are not authorised to act on this case.']];
		$response = $this->controller(self::aCase(), false, $escalated)->escalate('case-1');
		$this->assertSame($denied, [$response->getStatus(), $response->getData()]);
		$response = $this->controller(self::aCase(), false, $verified)->identityVerify('case-1');
		$this->assertSame($denied, [$response->getStatus(), $response->getData()]);

		$this->mayAct = true;
		$this->uid = null;
		$anonymous = [401, ['error' => 'Authentication required.']];
		$response = $this->controller(self::aCase(), false, $escalated)->escalate('case-1');
		$this->assertSame($anonymous, [$response->getStatus(), $response->getData()]);
		$response = $this->controller(self::aCase(), false, $verified)->identityVerify('case-1');
		$this->assertSame($anonymous, [$response->getStatus(), $response->getData()]);

		$this->assertSame([], $this->saved);
	}
}//end class
