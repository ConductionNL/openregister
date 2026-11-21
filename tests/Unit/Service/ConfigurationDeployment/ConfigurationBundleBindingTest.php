<?php

/**
 * Unit tests for binding a subject to a configuration bundle, unbinding it and reading its bundle.
 *
 * A rebind to another bundle is refused by name rather than applied, a repeat
 * bind is answered with the existing binding, and an empty bundle or subject
 * is a malformed request.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ConfigurationDeployment
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\ConfigurationDeployment;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\ConfigurationBinding;
use OCA\OpenRegister\Db\ConfigurationBindingMapperInterface;
use OCA\OpenRegister\Db\ConfigurationValueMapper;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationBundleService;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationDraftsInterface;
use OCA\OpenRegister\Service\ConfigurationDeployment\DeploymentRefusedException;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConfigurationBundleService::class)]
#[UsesClass(ConfigurationBinding::class)]
#[UsesClass(DeploymentRefusedException::class)]
final class ConfigurationBundleBindingTest extends TestCase {

	private ConfigurationBindingMapperInterface&MockObject $bindings;

	private ConfigurationBundleService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->bindings = $this->createMock(ConfigurationBindingMapperInterface::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$this->service = new ConfigurationBundleService(
			$this->bindings,
			$this->createMock(ConfigurationValueMapper::class),
			$this->createMock(ConfigurationDraftsInterface::class),
			$session
		);
	}//end setUp()

	private function binding(string $bundle, string $subject): ConfigurationBinding {
		$binding = new ConfigurationBinding();
		$binding->setBundle($bundle);
		$binding->setSubject($subject);
		$binding->setSubjectType(ConfigurationBinding::TYPE_SCHEMA);

		return $binding;
	}//end binding()

	public function testANewBindingIsCreatedTrimmedAndAttributed(): void {
		$created = $this->binding('zaak-defaults', 'zaaktype-1');
		$this->bindings->method('findBySubject')->with('zaaktype-1')->willReturn(null);
		$this->bindings->expects($this->once())->method('createFromArray')->with(
			[
				'bundle' => 'zaak-defaults',
				'subject' => 'zaaktype-1',
				'subjectType' => ConfigurationBinding::TYPE_ROLE,
				'createdBy' => 'admin',
			]
		)->willReturn($created);

		$this->assertSame($created, $this->service->bind(' zaak-defaults ', ' zaaktype-1 ', ConfigurationBinding::TYPE_ROLE));
	}//end testANewBindingIsCreatedTrimmedAndAttributed()

	public function testBindingAgainToTheSameBundleAnswersTheExistingBinding(): void {
		$existing = $this->binding('zaak-defaults', 'zaaktype-1');
		$this->bindings->method('findBySubject')->willReturn($existing);
		$this->bindings->expects($this->never())->method('createFromArray');

		$this->assertSame($existing, $this->service->bind('zaak-defaults', 'zaaktype-1'));
	}//end testBindingAgainToTheSameBundleAnswersTheExistingBinding()

	public function testARebindToAnotherBundleIsRefusedByName(): void {
		$this->bindings->method('findBySubject')->willReturn($this->binding('old-bundle', 'zaaktype-1'));
		$this->bindings->expects($this->never())->method('createFromArray');

		try {
			$this->service->bind('new-bundle', 'zaaktype-1');
			$this->fail('A rebind was applied.');
		} catch (DeploymentRefusedException $e) {
			$this->assertSame(DeploymentRefusedException::REASON_STATE, $e->getReason());
			$this->assertSame(409, $e->getStatusCode());
			$this->assertSame(
				'"zaaktype-1" already follows the bundle "old-bundle"; unbind it before binding it to "new-bundle"',
				$e->getMessage()
			);
		}
	}//end testARebindToAnotherBundleIsRefusedByName()

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function blankArguments(): array {
		return ['no bundle' => ['  ', 'zaaktype-1'], 'no subject' => ['zaak-defaults', '']];
	}//end blankArguments()

	#[\PHPUnit\Framework\Attributes\DataProvider('blankArguments')]
	public function testABlankBundleOrSubjectIsABadRequest(string $bundle, string $subject): void {
		$this->bindings->expects($this->never())->method('findBySubject');

		try {
			$this->service->bind($bundle, $subject);
			$this->fail('A blank binding was accepted.');
		} catch (DeploymentRefusedException $e) {
			$this->assertSame(DeploymentRefusedException::REASON_INVALID, $e->getReason());
			$this->assertSame(400, $e->getStatusCode());
			$this->assertSame('a binding needs a bundle and a subject', $e->getMessage());
		}
	}//end testABlankBundleOrSubjectIsABadRequest()

	public function testUnbindRemovesTheBinding(): void {
		$existing = $this->binding('zaak-defaults', 'zaaktype-1');
		$this->bindings->method('findBySubject')->with('zaaktype-1')->willReturn($existing);
		$this->bindings->expects($this->once())->method('remove')->with($existing);

		$this->assertTrue($this->service->unbind('zaaktype-1'));
	}//end testUnbindRemovesTheBinding()

	public function testUnbindingAnUnboundSubjectRemovesNothing(): void {
		$this->bindings->method('findBySubject')->willReturn(null);
		$this->bindings->expects($this->never())->method('remove');

		$this->assertFalse($this->service->unbind('zaaktype-1'));
	}//end testUnbindingAnUnboundSubjectRemovesNothing()

	public function testBundleForNamesTheFollowedBundleOrNull(): void {
		$this->bindings->method('findBySubject')->willReturnMap(
			[
				['zaaktype-1', $this->binding('zaak-defaults', 'zaaktype-1')],
				['zaaktype-2', null],
			]
		);

		$this->assertSame('zaak-defaults', $this->service->bundleFor('zaaktype-1'));
		$this->assertNull($this->service->bundleFor('zaaktype-2'));
	}//end testBundleForNamesTheFollowedBundleOrNull()
}//end class
