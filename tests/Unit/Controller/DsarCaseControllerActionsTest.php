<?php

/**
 * The case-management actions create, transition, harvest, redact, bundle and dossier answer what their
 * services answer, refuse when the case guard refuses, and turn a service failure into the right status.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/dsar-case-api/spec.md
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\DsarCaseController;
use OCA\OpenRegister\Controller\Support\DsarCaseCases;
use OCA\OpenRegister\Controller\Support\DsarCaseControllerServices;
use OCA\OpenRegister\Controller\Support\DsarCaseDisclosure;
use OCA\OpenRegister\Controller\Support\DsarCasePlatform;
use OCA\OpenRegister\Controller\Support\DsarCaseRegistries;
use OCA\OpenRegister\Db\LockTerms;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\SoftDeletion;
use OCA\OpenRegister\Kernel\Contracts\Value\BulkBatchDefaults;
use OCA\OpenRegister\Kernel\Contracts\Value\BulkSaveOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\SaveActor;
use OCA\OpenRegister\Kernel\Contracts\Value\SavePolicy;
use OCA\OpenRegister\Service\Gdpr\Case\CaseAccessControl;
use OCA\OpenRegister\Service\Gdpr\Case\CaseObjectAccessor;
use OCA\OpenRegister\Service\Gdpr\Evidence\EvidenceHarvestService;
use OCA\OpenRegister\Service\Gdpr\Export\ExportBundleService;
use OCA\OpenRegister\Service\Gdpr\Identity\IdentityVerifyRegistry;
use OCA\OpenRegister\Service\Gdpr\Policy\DsarPolicyPackResolver;
use OCA\OpenRegister\Service\Gdpr\Redaction\FieldRedaction;
use OCA\OpenRegister\Service\Gdpr\Redaction\RedactionWriteService;
use OCA\OpenRegister\Service\Gdpr\Regulator\RegulatorEscalateRegistry;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Tests\Unit\Controller\Support\DsarCaseControllerFactory;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * A handler driving one case through the action endpoints.
 */
#[CoversClass(DsarCaseController::class)]
#[UsesClass(DsarCaseControllerServices::class)]
#[UsesClass(DsarCaseCases::class)]
#[UsesClass(DsarCaseDisclosure::class)]
#[UsesClass(DsarCaseRegistries::class)]
#[UsesClass(DsarCasePlatform::class)]
#[UsesClass(FieldRedaction::class)]
#[UsesClass(ObjectSaveOptions::class)]
#[UsesClass(ObjectFindTarget::class)]
#[UsesClass(SaveActor::class)]
#[UsesClass(SavePolicy::class)]
#[UsesClass(BulkBatchDefaults::class)]
#[UsesClass(BulkSaveOptions::class)]
#[UsesClass(LockTerms::class)]
#[UsesClass(SoftDeletion::class)]
final class DsarCaseControllerActionsTest extends TestCase {
	/** @var ObjectService&MockObject */
	private ObjectService $objects;

	/** @var CaseObjectAccessor&MockObject */
	private CaseObjectAccessor $accessor;

	/** @var CaseAccessControl&MockObject */
	private CaseAccessControl $access;

	/** @var TransitionEngine&MockObject */
	private TransitionEngine $transitions;

	/** @var EvidenceHarvestService&MockObject */
	private EvidenceHarvestService $harvest;

	/** @var RedactionWriteService&MockObject */
	private RedactionWriteService $redaction;

	/** @var ExportBundleService&MockObject */
	private ExportBundleService $bundles;

	/**
	 * Fresh collaborators per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objects = $this->createMock(ObjectService::class);
		$this->accessor = $this->createMock(CaseObjectAccessor::class);
		$this->access = $this->createMock(CaseAccessControl::class);
		$this->transitions = $this->createMock(TransitionEngine::class);
		$this->harvest = $this->createMock(EvidenceHarvestService::class);
		$this->redaction = $this->createMock(RedactionWriteService::class);
		$this->bundles = $this->createMock(ExportBundleService::class);
	}

	/**
	 * The controller for the given request params, signed in as `handler1` unless anonymous.
	 *
	 * @param array<string, mixed> $params    The request params.
	 * @param bool                 $anonymous Whether nobody is signed in.
	 *
	 * @return DsarCaseController
	 */
	private function controller(array $params=[], bool $anonymous=false): DsarCaseController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default=null): mixed => ($params[$key] ?? $default)
		);

		$user = null;
		if ($anonymous === false) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn('handler1');
		}

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return DsarCaseControllerFactory::create(
			$request,
			$this->objects,
			$this->accessor,
			$this->access,
			$this->transitions,
			$this->harvest,
			$this->redaction,
			$this->bundles,
			$session,
			$this->createMock(DsarPolicyPackResolver::class),
			$this->createMock(IdentityVerifyRegistry::class),
			$this->createMock(RegulatorEscalateRegistry::class)
		);
	}

	/**
	 * A loaded case whose handler may act on it (or not).
	 *
	 * @param bool $mayAct What case access control answers.
	 *
	 * @return void
	 */
	private function caseLoaded(bool $mayAct=true): void {
		$case = $this->createMock(ObjectEntity::class);
		$case->method('getObject')->willReturn(['handler' => 'handler1']);
		$this->accessor->method('load')->willReturn($case);
		$this->access->method('mayAct')->willReturn($mayAct);
	}

	/**
	 * A saved entity serialising to the given data.
	 *
	 * @param array<string, mixed> $data The serialised form.
	 *
	 * @return ObjectEntity
	 */
	private function savedEntity(array $data): ObjectEntity {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn($data);
		return $entity;
	}

	/**
	 * Create saves only the case payload: framework keys and any identity are stripped, `@self` keeps the rest.
	 *
	 * @return void
	 */
	public function testCreateSavesThePayloadWithoutIdentityAndAnswers201(): void {
		$captured = null;
		$this->objects->expects($this->once())
			->method('saveObject')
			->willReturnCallback(
				function (array $object, ObjectSaveOptions $options, bool $_rbac, bool $_multitenancy) use (&$captured): ObjectEntity {
					$captured = [$object, $_rbac, $_multitenancy];
					return $this->savedEntity(['uuid' => 'case-new', 'subject' => 'Piet']);
				}
			);

		$response = $this->controller(
			[
				'_route' => 'openregister.dsarCase.create',
				'id' => 'existing-case',
				'uuid' => 'existing-uuid',
				'subject' => 'Piet',
				'@self' => ['id' => 'x', 'uuid' => 'y', 'owner' => 'handler1'],
			]
		)->create();

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame(['uuid' => 'case-new', 'subject' => 'Piet'], $response->getData());
		$this->assertSame(
			[['subject' => 'Piet', '@self' => ['owner' => 'handler1']], true, true],
			$captured
		);
	}

	/**
	 * An `@self` holding only identity is dropped entirely rather than saved empty.
	 *
	 * @return void
	 */
	public function testCreateDropsAnSelfThatHeldOnlyIdentity(): void {
		$this->objects->expects($this->once())
			->method('saveObject')
			->with(['subject' => 'Piet'])
			->willReturn($this->savedEntity(['uuid' => 'case-new']));

		$response = $this->controller(['subject' => 'Piet', '@self' => ['id' => 'x']])->create();

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
	}

	/**
	 * A failing save answers 400 with the failure's message.
	 *
	 * @return void
	 */
	public function testCreateAnswers400WhenTheSaveFails(): void {
		$this->objects->method('saveObject')->willThrowException(new RuntimeException('Schema validation failed'));

		$response = $this->controller(['subject' => 'Piet'])->create();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'Schema validation failed'], $response->getData());
	}

	/**
	 * An anonymous create is refused with 401 and nothing is saved.
	 *
	 * @return void
	 */
	public function testCreateRefusesAnAnonymousCaller(): void {
		$this->objects->expects($this->never())->method('saveObject');

		$response = $this->controller(['subject' => 'Piet'], true)->create();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}

	/**
	 * A transition runs the named action on the case and answers the transitioned case.
	 *
	 * @return void
	 */
	public function testTransitionRunsTheNamedAction(): void {
		$this->caseLoaded();
		$this->transitions->expects($this->once())
			->method('transition')
			->with('case-1', 'assign')
			->willReturn($this->savedEntity(['uuid' => 'case-1', 'status' => 'assigned']));

		$response = $this->controller(['action' => 'assign'])->transition('case-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['uuid' => 'case-1', 'status' => 'assigned'], $response->getData());
	}

	/**
	 * A transition without an action is a 400 and the engine is not called.
	 *
	 * @return void
	 */
	public function testTransitionRequiresAnAction(): void {
		$this->caseLoaded();
		$this->transitions->expects($this->never())->method('transition');

		$response = $this->controller()->transition('case-1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'A transition "action" is required.'], $response->getData());
	}

	/**
	 * A refused or illegal transition surfaces as 403 with the engine's message.
	 *
	 * @return void
	 */
	public function testARefusedTransitionIs403(): void {
		$this->caseLoaded();
		$this->transitions->method('transition')->willThrowException(new RuntimeException('Denial needs a ground'));

		$response = $this->controller(['action' => 'finaliseDenial'])->transition('case-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['error' => 'Denial needs a ground'], $response->getData());
	}

	/**
	 * A transition on a case the caller may not act on is refused before the engine runs.
	 *
	 * @return void
	 */
	public function testTransitionIsGuarded(): void {
		$this->caseLoaded(false);
		$this->transitions->expects($this->never())->method('transition');

		$response = $this->controller(['action' => 'assign'])->transition('case-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['error' => 'You are not authorised to act on this case.'], $response->getData());
	}

	/**
	 * The evidence harvest answers the service's result, and a failing harvest is a 400.
	 *
	 * @return void
	 */
	public function testEvidenceAnswersTheHarvestOrA400(): void {
		$this->caseLoaded();
		$this->harvest->method('harvest')->with('case-1')->willReturnOnConsecutiveCalls(
			['collected' => 3],
			$this->throwException(new RuntimeException('No sources configured'))
		);

		$first = $this->controller()->evidence('case-1');
		$second = $this->controller()->evidence('case-1');

		$this->assertSame(Http::STATUS_OK, $first->getStatus());
		$this->assertSame(['collected' => 3], $first->getData());
		$this->assertSame(Http::STATUS_BAD_REQUEST, $second->getStatus());
		$this->assertSame(['error' => 'No sources configured'], $second->getData());
	}

	/**
	 * A redaction on a case the caller may not act on is refused before the request is read.
	 *
	 * @return void
	 */
	public function testRedactIsGuarded(): void {
		$this->caseLoaded(false);
		$this->redaction->expects($this->never())->method('applyRedaction');

		$response = $this->controller(['field' => 'bsn', 'ground' => 'art-15-4'])->redact('case-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	/**
	 * A redaction the write service refuses is a 400 with its message.
	 *
	 * @return void
	 */
	public function testARefusedRedactionIs400(): void {
		$this->caseLoaded();
		$this->redaction->method('applyRedaction')->willThrowException(new RuntimeException('Unknown field'));

		$response = $this->controller(['field' => 'bsn', 'ground' => 'art-15-4'])->redact('case-1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'Unknown field'], $response->getData());
	}

	/**
	 * Bundle generation on a missing case is a 404 and no bundle is made.
	 *
	 * @return void
	 */
	public function testGenerateBundleOnAMissingCaseIs404(): void {
		$this->accessor->method('load')->willReturn(null);
		$this->bundles->expects($this->never())->method('generate');

		$response = $this->controller()->generateBundle('case-x');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['error' => 'Not found or not authorised.'], $response->getData());
	}

	/**
	 * An anonymous download is refused with 401 before the case is loaded.
	 *
	 * @return void
	 */
	public function testDownloadRefusesAnAnonymousCaller(): void {
		$this->accessor->expects($this->never())->method('load');

		$response = $this->controller(['token' => 't'], true)->downloadBundle('case-1');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}

	/**
	 * The regulator dossier answers the assembled dossier, and a failing assembly is a 400.
	 *
	 * @return void
	 */
	public function testDossierAnswersTheAssemblyOrA400(): void {
		$this->caseLoaded();
		$this->bundles->method('assembleRegulatorDossier')->with('case-1')->willReturnOnConsecutiveCalls(
			['documents' => 2],
			$this->throwException(new RuntimeException('Bundle not generated'))
		);

		$first = $this->controller()->dossier('case-1');
		$second = $this->controller()->dossier('case-1');

		$this->assertSame(Http::STATUS_OK, $first->getStatus());
		$this->assertSame(['documents' => 2], $first->getData());
		$this->assertSame(Http::STATUS_BAD_REQUEST, $second->getStatus());
		$this->assertSame(['error' => 'Bundle not generated'], $second->getData());
	}

	/**
	 * A case that vanishes between the guard and the action is answered 404, not acted on.
	 *
	 * @return void
	 */
	public function testIdentityVerifyOnACaseGoneAfterTheGuardIs404(): void {
		$case = $this->createMock(ObjectEntity::class);
		$case->method('getObject')->willReturn(['handler' => 'handler1']);
		$this->accessor->method('load')->willReturnOnConsecutiveCalls($case, null);
		$this->access->method('mayAct')->willReturn(true);
		$this->accessor->expects($this->never())->method('save');

		$response = $this->controller()->identityVerify('case-1');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}
}
