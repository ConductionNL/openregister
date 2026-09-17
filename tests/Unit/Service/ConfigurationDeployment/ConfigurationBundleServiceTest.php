<?php

/**
 * Unit tests for configuration bundles, their exceptions and the matrix copy.
 *
 * The four things a bundle has to get right, and the way each one fails
 * quietly if it does not:
 *
 * - **A bundle is not copied onto its subjects.** Forty schemas bound to one
 *   bundle read the bundle's value through the chain. If any of them held its
 *   own copy, the fortieth would drift and nobody would see it.
 * - **An exception is listed as an exception.** A subject overriding one value
 *   looks identical to a compliant one unless the listing says so by name.
 * - **A rebind is refused, not silently applied.** Rebinding two hundred
 *   zaaktypen by accident is the failure the capability exists to prevent.
 * - **A copied matrix lands as a draft.** A copy that goes live is the act
 *   nobody reviews today, which is D-6's whole point.
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
use OCA\OpenRegister\Db\ConfigurationBindingMapper;
use OCA\OpenRegister\Db\ConfigurationDeploymentMapper;
use OCA\OpenRegister\Db\ConfigurationDraft;
use OCA\OpenRegister\Db\ConfigurationValue;
use OCA\OpenRegister\Db\ConfigurationValueMapper;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationBundleService;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationDraftService;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationExplainer;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationLayer;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationValueStore;
use OCA\OpenRegister\Service\ConfigurationDeployment\DeploymentRefusedException;
use OCP\IAppConfig;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class ConfigurationBundleServiceTest extends TestCase {

	private function row(string $layer, ?string $ref, string $key, mixed $value): ConfigurationValue {
		$row = new ConfigurationValue();
		$row->setUuid('value-'.$key.'-'.(string)$ref);
		$row->setLayer($layer);
		$row->setLayerRef($ref);
		$row->setConfigKey($key);
		$row->setConfigValue(['value' => $value]);

		return $row;
	}//end row()

	private function binding(string $bundle, string $subject): ConfigurationBinding {
		$binding = new ConfigurationBinding();
		$binding->setUuid('binding-'.$subject);
		$binding->setBundle($bundle);
		$binding->setSubject($subject);
		$binding->setSubjectType(ConfigurationBinding::TYPE_SCHEMA);
		$binding->setCreatedBy('admin');

		return $binding;
	}//end binding()

	/**
	 * A value mapper answering from an in-memory table of rows.
	 *
	 * @param array<int, ConfigurationValue> $rows The rows this instance holds.
	 *
	 * @return ConfigurationValueMapper&MockObject The double.
	 */
	private function valueMapper(array $rows): ConfigurationValueMapper&MockObject {
		$mapper = $this->createMock(ConfigurationValueMapper::class);

		// The double models the REAL mapper's semantics, including the one that
		// bit: on findAtLayer a null reference means the instance address, not
		// "any reference". A double that read null as "any" would have let
		// listBundles() pass here while finding no bundle at all in a database.
		$mapper->method('findAtLayer')->willReturnCallback(
			static function (string $layer, ?string $ref, ?string $prefix = null) use ($rows): array {
				return array_values(
					array_filter(
						$rows,
						static function (ConfigurationValue $row) use ($layer, $ref, $prefix): bool {
							if ($row->getLayer() !== $layer || $row->getLayerRef() !== $ref) {
								return false;
							}

							if ($prefix !== null && $prefix !== '') {
								return str_starts_with((string)$row->getConfigKey(), $prefix);
							}

							return true;
						}
					)
				);
			}
		);

		$mapper->method('findAllAtLayer')->willReturnCallback(
			static fn (string $layer): array => array_values(
				array_filter($rows, static fn (ConfigurationValue $row): bool => $row->getLayer() === $layer)
			)
		);

		$mapper->method('findAtAddress')->willReturnCallback(
			static function (string $layer, ?string $ref, string $key) use ($rows): ?ConfigurationValue {
				foreach ($rows as $row) {
					if ($row->getLayer() === $layer && $row->getLayerRef() === $ref && $row->getConfigKey() === $key) {
						return $row;
					}
				}

				return null;
			}
		);

		return $mapper;
	}//end valueMapper()

	private function bindingMapper(array $bindings): ConfigurationBindingMapper&MockObject {
		$mapper = $this->createMock(ConfigurationBindingMapper::class);

		$mapper->method('findBySubject')->willReturnCallback(
			static function (string $subject) use ($bindings): ?ConfigurationBinding {
				foreach ($bindings as $binding) {
					if ($binding->getSubject() === $subject) {
						return $binding;
					}
				}

				return null;
			}
		);
		$mapper->method('findByBundle')->willReturnCallback(
			static fn (string $bundle): array => array_values(
				array_filter($bindings, static fn (ConfigurationBinding $b): bool => $b->getBundle() === $bundle)
			)
		);
		$mapper->method('findAllBindings')->willReturn($bindings);

		return $mapper;
	}//end bindingMapper()

	private function session(): IUserSession&MockObject {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);

		return $session;
	}//end session()

	private function service(
		array $rows,
		array $bindings,
		?ConfigurationDraftService $drafts = null
	): ConfigurationBundleService {
		return new ConfigurationBundleService(
			$this->bindingMapper($bindings),
			$this->valueMapper($rows),
			($drafts ?? $this->createMock(ConfigurationDraftService::class)),
			$this->session()
		);
	}//end service()

	public function testTwoHundredCaseTypesStayInStepBecauseNoneOfThemHoldsACopy(): void {
		// One bundle row, three bound schemas, no subject rows at all. The
		// value each schema reads comes from the bundle, so changing that one
		// row changes all three: there is nothing else to change.
		$rows = [$this->row(ConfigurationLayer::BUNDLE, 'zaaktype-standaard', 'notification.assigned', ['channel' => 'mail'])];
		$bindings = [
			$this->binding('zaaktype-standaard', 'melding'),
			$this->binding('zaaktype-standaard', 'bezwaar'),
			$this->binding('zaaktype-standaard', 'vergunning'),
		];

		$store = new ConfigurationValueStore($this->valueMapper($rows), $this->createMock(IAppConfig::class), 'openregister');
		$explainer = new ConfigurationExplainer(
			$store,
			$this->createMock(ConfigurationDeploymentMapper::class),
			$this->bindingMapper($bindings)
		);

		foreach (['melding', 'bezwaar', 'vergunning'] as $subject) {
			// The caller names only the subject. The bundle is resolved from
			// the binding, which is the difference between a chain that works
			// and one that needs every caller to know the topology.
			$answer = $explainer->explain('notification.assigned', null, null, $subject);

			$this->assertSame(['channel' => 'mail'], $answer['value']);
			$this->assertSame(ConfigurationLayer::BUNDLE, $answer['layer']);
			$this->assertSame('zaaktype-standaard', $answer['layerRef']);
		}
	}//end testTwoHundredCaseTypesStayInStepBecauseNoneOfThemHoldsACopy()

	public function testASubjectOverridingOneValueWinsAndIsListedAsAnException(): void {
		$rows = [
			$this->row(ConfigurationLayer::BUNDLE, 'zaaktype-standaard', 'notification.assigned', ['channel' => 'mail']),
			$this->row(ConfigurationLayer::BUNDLE, 'zaaktype-standaard', 'lifecycle.retention', ['days' => 365]),
			$this->row(ConfigurationLayer::SUBJECT, 'bezwaar', 'notification.assigned', ['channel' => 'none']),
		];
		$bindings = [
			$this->binding('zaaktype-standaard', 'melding'),
			$this->binding('zaaktype-standaard', 'bezwaar'),
		];

		$listed = $this->service($rows, $bindings)->bindingsOf('zaaktype-standaard');

		$bySubject = array_column($listed, null, 'subject');
		$this->assertFalse($bySubject['melding']['overriding']);
		$this->assertTrue($bySubject['bezwaar']['overriding']);
		$this->assertSame(['notification.assigned'], $bySubject['bezwaar']['overrides']);

		// And the override actually wins, which is what makes it an exception
		// rather than a row in a table nobody honours.
		$store = new ConfigurationValueStore($this->valueMapper($rows), $this->createMock(IAppConfig::class), 'openregister');
		$explainer = new ConfigurationExplainer(
			$store,
			$this->createMock(ConfigurationDeploymentMapper::class),
			$this->bindingMapper($bindings)
		);
		$answer = $explainer->explain('notification.assigned', null, null, 'bezwaar');

		$this->assertSame(['channel' => 'none'], $answer['value']);
		$this->assertSame(ConfigurationLayer::SUBJECT, $answer['layer']);
	}//end testASubjectOverridingOneValueWinsAndIsListedAsAnException()

	public function testOneMailRelayAtTheInstanceAppliesToASchemaThatSetsNone(): void {
		// REQ-CAD-005, first scenario. A gemeente configures one mail relay,
		// not one per zaaktype, and the explainer names where it came from.
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('hasKey')->willReturn(true);
		$appConfig->method('getValueString')->willReturn('{"relay":"smtp.gemeente.nl"}');

		$store = new ConfigurationValueStore($this->valueMapper([]), $appConfig, 'openregister');
		$explainer = new ConfigurationExplainer(
			$store,
			$this->createMock(ConfigurationDeploymentMapper::class),
			$this->bindingMapper([$this->binding('zaaktype-standaard', 'melding')])
		);

		$answer = $explainer->explain('integration.mail', 'zaken', null, 'melding');

		$this->assertSame(['relay' => 'smtp.gemeente.nl'], $answer['value']);
		$this->assertSame(ConfigurationLayer::INSTANCE, $answer['layer']);
		$this->assertTrue($answer['predatesFirstDeployment']);
		$this->assertStringContainsString('instance', $answer['explanation']);
	}//end testOneMailRelayAtTheInstanceAppliesToASchemaThatSetsNone()

	public function testASubjectAlreadyFollowingAnotherBundleIsRefusedByName(): void {
		$service = $this->service([], [$this->binding('zaaktype-standaard', 'bezwaar')]);

		$this->expectException(DeploymentRefusedException::class);
		$this->expectExceptionMessage('already follows the bundle "zaaktype-standaard"');

		$service->bind('zaaktype-licht', 'bezwaar');
	}//end testASubjectAlreadyFollowingAnotherBundleIsRefusedByName()

	public function testRebindingASubjectToTheBundleItAlreadyFollowsChangesNothing(): void {
		$bindings = [$this->binding('zaaktype-standaard', 'bezwaar')];
		$mapper = $this->bindingMapper($bindings);
		$mapper->expects($this->never())->method('createFromArray');

		$service = new ConfigurationBundleService(
			$mapper,
			$this->valueMapper([]),
			$this->createMock(ConfigurationDraftService::class),
			$this->session()
		);

		$this->assertSame('zaaktype-standaard', $service->bind('zaaktype-standaard', 'bezwaar')->getBundle());
	}//end testRebindingASubjectToTheBundleItAlreadyFollowsChangesNothing()

	public function testACopiedMatrixLandsAsDraftsAndNeverAsLiveValues(): void {
		$rows = [
			$this->row(ConfigurationLayer::SUBJECT, 'behandelaar', 'permission.read', true),
			$this->row(ConfigurationLayer::SUBJECT, 'behandelaar', 'permission.write', true),
			$this->row(ConfigurationLayer::SUBJECT, 'behandelaar', 'lifecycle.retention', ['days' => 30]),
		];

		$staged = [];
		$drafts = $this->createMock(ConfigurationDraftService::class);
		$drafts->method('draftValue')->willReturnCallback(
			static function (string $set, string $layer, ?string $ref, string $key, mixed $value) use (&$staged): ConfigurationDraft {
				$staged[] = ['set' => $set, 'layer' => $layer, 'ref' => $ref, 'key' => $key, 'value' => $value];
				return new ConfigurationDraft();
			}
		);

		$copied = $this->service($rows, [], $drafts)
			->copyMatrix('set-1', 'permission.', 'behandelaar', 'meelezer');

		// Two permission keys copied, and the lifecycle key left where it was:
		// a copy that takes more than it was asked for cannot be reviewed
		// against what was asked.
		$this->assertCount(2, $copied);
		$this->assertSame(['permission.read', 'permission.write'], array_column($staged, 'key'));
		$this->assertSame(['meelezer', 'meelezer'], array_column($staged, 'ref'));
		$this->assertSame(['set-1', 'set-1'], array_column($staged, 'set'));
	}//end testACopiedMatrixLandsAsDraftsAndNeverAsLiveValues()

	public function testACopyOntoItselfIsRefused(): void {
		$this->expectException(DeploymentRefusedException::class);
		$this->expectExceptionMessage('both the source and the target');

		$this->service([], [])->copyMatrix('set-1', 'permission.', 'behandelaar', 'behandelaar');
	}//end testACopyOntoItselfIsRefused()

	public function testAPrefixThatIsNotAPrefixIsRefused(): void {
		$this->expectException(DeploymentRefusedException::class);
		$this->expectExceptionMessage('ending in a dot');

		$this->service([], [])->copyMatrix('set-1', 'permission', 'behandelaar', 'meelezer');
	}//end testAPrefixThatIsNotAPrefixIsRefused()

	public function testCopyingAMatrixThatIsNotThereIsRefusedRatherThanSilentlyEmpty(): void {
		$this->expectException(DeploymentRefusedException::class);
		$this->expectExceptionMessage('nothing to copy');

		$this->service([], [])->copyMatrix('set-1', 'permission.', 'behandelaar', 'meelezer');
	}//end testCopyingAMatrixThatIsNotThereIsRefusedRatherThanSilentlyEmpty()

	public function testABundleExistsBecauseSomethingReferencesIt(): void {
		$rows = [
			$this->row(ConfigurationLayer::BUNDLE, 'zaaktype-standaard', 'notification.assigned', ['channel' => 'mail']),
			$this->row(ConfigurationLayer::BUNDLE, 'zaaktype-licht', 'lifecycle.retention', ['days' => 90]),
			$this->row(ConfigurationLayer::SUBJECT, 'bezwaar', 'notification.assigned', ['channel' => 'none']),
		];
		$bindings = [
			$this->binding('zaaktype-standaard', 'melding'),
			$this->binding('zaaktype-standaard', 'bezwaar'),
		];

		$bundles = array_column($this->service($rows, $bindings)->listBundles(), null, 'name');

		// The bundle zaaktype-licht has no binding yet and is still a bundle, because a
		// bundle you cannot see until somebody follows it is a bundle nobody
		// can bind anything to.
		$this->assertArrayHasKey('zaaktype-licht', $bundles);
		$this->assertSame(0, $bundles['zaaktype-licht']['subjects']);
		$this->assertSame(2, $bundles['zaaktype-standaard']['subjects']);
		$this->assertSame(1, $bundles['zaaktype-standaard']['overriding']);
		$this->assertSame(['notification.assigned'], $bundles['zaaktype-standaard']['keys']);
	}//end testABundleExistsBecauseSomethingReferencesIt()
}//end class
