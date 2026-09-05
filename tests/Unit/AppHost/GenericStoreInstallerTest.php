<?php

/**
 * Tests for the AppHost GenericStoreInstaller and StoreManifest.
 *
 * The installer is the half of the store plane that WRITES, so its two
 * defences are asserted directly rather than assumed:
 *
 *   - the `installable` allowlist, which is what stops a remote registry
 *     naming any schema the app owns;
 *   - the identity strip, without which "install" is an overwrite primitive
 *     because ObjectService resolves its target FROM the payload.
 *
 * Both have negative controls: a refused component must still let the allowed
 * ones through, and an empty allowlist must refuse everything rather than
 * permit everything.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\AppHost
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\AppHost;

use OCA\OpenRegister\AppHost\Store\GenericStoreInstaller;
use OCA\OpenRegister\AppHost\Store\StoreManifest;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\AppHost\Store\GenericStoreInstaller
 * @covers \OCA\OpenRegister\AppHost\Store\StoreManifest
 */
class GenericStoreInstallerTest extends TestCase {
	/** @var ObjectService&MockObject */
	private $objectService;

	/** @var IAppConfig&MockObject */
	private $appConfig;

	/** @var LoggerInterface&MockObject */
	private $logger;

	private GenericStoreInstaller $installer;

	/**
	 * Build the installer over mocked collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		// onlyMethods, never addMethods: addMethods INVENTS a method the class
		// does not have, so a rename would leave this suite green over an
		// endpoint that fatals.
		$this->objectService = $this->getMockBuilder(ObjectService::class)
			->disableOriginalConstructor()
			->onlyMethods(['saveObject'])
			->getMock();
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->installer = new GenericStoreInstaller(
			objectService: $this->objectService,
			appConfig: $this->appConfig,
			logger: $this->logger
		);
	}//end setUp()

	/**
	 * A store manifest that allows two schemas.
	 *
	 * @param array<int, string> $installable Allowed schema slugs.
	 *
	 * @return StoreManifest
	 */
	private function store(array $installable = ['caseType', 'workflowTemplate']): StoreManifest {
		return StoreManifest::fromManifest(
			appId: 'dossiq',
			manifest: ['store' => [
				'schema' => 'case-type-template',
				'register' => 'store',
				'installable' => $installable,
			]]
		);
	}//end store()

	/**
	 * An allowed component is written into the app's OWN register.
	 *
	 * @return void
	 */
	public function testWritesAnAllowedComponentIntoTheAppsRegister(): void {
		$this->objectService->expects($this->once())
			->method('saveObject')
			->with(
				$this->equalTo(['title' => 'Enforcement']),
				$this->anything(),
				$this->equalTo('dossiq'),
				$this->equalTo('caseType')
			)
			->willReturn($this->createMock(ObjectEntity::class));

		$result = $this->installer->install(
			store: $this->store(),
			item: ['components' => [['schema' => 'caseType', 'object' => ['title' => 'Enforcement']]]]
		);

		$this->assertTrue($result['success']);
		$this->assertSame('installed', $result['components'][0]['status']);
	}//end testWritesAnAllowedComponentIntoTheAppsRegister()

	/**
	 * 🔴 The identity strip. Without it an install REPLACES a live object,
	 * because ObjectService reads `@self.id`/`id` off the payload as the uuid
	 * to update, PUT-semantically.
	 *
	 * @return void
	 */
	public function testStripsEveryRemoteIdentitySoAnInstallCreates(): void {
		$captured = null;
		$this->objectService->method('saveObject')
			->willReturnCallback(function (...$args) use (&$captured) {
				$captured = $args[0];
				return $this->createMock(ObjectEntity::class);
			});

		$this->installer->install(
			store: $this->store(),
			item: ['components' => [[
				'schema' => 'caseType',
				'object' => [
					'id' => 42,
					'uuid' => 'a-live-local-uuid',
					'@self' => ['id' => 'a-live-local-uuid'],
					'title' => 'Enforcement',
				],
			]]]
		);

		$this->assertSame(['title' => 'Enforcement'], $captured);
		$this->assertArrayNotHasKey('id', (array)$captured);
		$this->assertArrayNotHasKey('uuid', (array)$captured);
		$this->assertArrayNotHasKey('@self', (array)$captured);
	}//end testStripsEveryRemoteIdentitySoAnInstallCreates()

	/**
	 * A schema the manifest does not allow is refused, and nothing is written.
	 *
	 * @return void
	 */
	public function testRefusesASchemaTheManifestDoesNotAllow(): void {
		$this->objectService->expects($this->never())->method('saveObject');

		$result = $this->installer->install(
			store: $this->store(),
			item: ['components' => [['schema' => 'case', 'object' => ['title' => 'A real record']]]]
		);

		$this->assertFalse($result['success']);
		$this->assertSame('refused', $result['components'][0]['status']);
	}//end testRefusesASchemaTheManifestDoesNotAllow()

	/**
	 * A partial install is an OUTCOME: the allowed half still lands.
	 *
	 * @return void
	 */
	public function testARefusalDoesNotAbortTheAllowedComponents(): void {
		$this->objectService->expects($this->once())
			->method('saveObject')
			->willReturn($this->createMock(ObjectEntity::class));

		$result = $this->installer->install(
			store: $this->store(),
			item: ['components' => [
				['schema' => 'caseType', 'object' => ['title' => 'Config']],
				['schema' => 'case', 'object' => ['title' => 'A record']],
			]]
		);

		$this->assertFalse($result['success']);
		$statuses = array_column($result['components'], 'status', 'schema');
		$this->assertSame('installed', $statuses['caseType']);
		$this->assertSame('refused', $statuses['case']);
	}//end testARefusalDoesNotAbortTheAllowedComponents()

	/**
	 * NEGATIVE CONTROL ON THE ALLOWLIST. An empty list must mean "install
	 * nothing", never "install anything" — an app that declares a store and
	 * forgets the allowlist must get refusals, not an open door.
	 *
	 * @return void
	 */
	public function testAnEmptyAllowlistRefusesEverything(): void {
		$this->objectService->expects($this->never())->method('saveObject');

		$result = $this->installer->install(
			store: $this->store(installable: []),
			item: ['components' => [['schema' => 'caseType', 'object' => ['title' => 'Config']]]]
		);

		$this->assertFalse($result['success']);
		$this->assertSame('refused', $result['components'][0]['status']);
	}//end testAnEmptyAllowlistRefusesEverything()

	/**
	 * A registry may ship the component list as a JSON string.
	 *
	 * @return void
	 */
	public function testAcceptsAJsonEncodedComponentList(): void {
		$this->objectService->expects($this->once())
			->method('saveObject')
			->willReturn($this->createMock(ObjectEntity::class));

		$result = $this->installer->install(
			store: $this->store(),
			item: ['components' => json_encode([['schema' => 'caseType', 'object' => ['title' => 'Config']]])]
		);

		$this->assertTrue($result['success']);
	}//end testAcceptsAJsonEncodedComponentList()

	/**
	 * A JSON string that decodes to something that is not a list yields no
	 * components rather than a fatal. A registry controls this value.
	 *
	 * @return void
	 */
	public function testAJsonStringThatIsNotAListYieldsNoComponents(): void {
		$this->objectService->expects($this->never())->method('saveObject');

		foreach (['"just a string"', '42', 'not json at all'] as $payload) {
			$result = $this->installer->install(store: $this->store(), item: ['components' => $payload]);
			$this->assertFalse($result['success']);
			$this->assertSame([], $result['components']);
		}
	}//end testAJsonStringThatIsNotAListYieldsNoComponents()

	/**
	 * Entries in the component list that are not objects are dropped, so a
	 * registry cannot make the installer read a string as a component.
	 *
	 * @return void
	 */
	public function testNonObjectComponentEntriesAreDropped(): void {
		$this->objectService->expects($this->once())
			->method('saveObject')
			->willReturn($this->createMock(ObjectEntity::class));

		$result = $this->installer->install(
			store: $this->store(),
			item: ['components' => [
				'a bare string',
				['schema' => 'caseType', 'object' => ['title' => 'Config']],
				42,
			]]
		);

		$this->assertCount(1, $result['components']);
		$this->assertTrue($result['success']);
	}//end testNonObjectComponentEntriesAreDropped()

	/**
	 * A component whose `object` is present but not an array is refused rather
	 * than passed to the write path.
	 *
	 * @return void
	 */
	public function testAComponentWhoseObjectIsNotAnArrayIsRefused(): void {
		$this->objectService->expects($this->never())->method('saveObject');

		$result = $this->installer->install(
			store: $this->store(),
			item: ['components' => [['schema' => 'caseType', 'object' => 'a string']]]
		);

		$this->assertFalse($result['success']);
		$this->assertSame('refused', $result['components'][0]['status']);
	}//end testAComponentWhoseObjectIsNotAnArrayIsRefused()

	/**
	 * Every list-shaped key coerces a non-array to an empty list rather than
	 * throwing. A manifest is hand-edited JSON; a scalar where a list belongs
	 * must degrade to "declared nothing", and for `installable` that means
	 * refusing every install.
	 *
	 * @return void
	 */
	public function testScalarsWhereListsBelongCoerceToEmpty(): void {
		$store = StoreManifest::fromManifest(
			appId: 'dossiq',
			manifest: ['store' => [
				'schema' => 't',
				'installable' => 'caseType',
				'kinds' => 42,
				'builtIn' => 'nope',
			]]
		);

		$this->assertSame([], $store->installable);
		$this->assertSame([], $store->kinds);
		$this->assertSame([], $store->builtIn);
		$this->assertFalse($store->isInstallable(slug: 'caseType'));
	}//end testScalarsWhereListsBelongCoerceToEmpty()

	/**
	 * The declared kinds survive the same coercion as the allowlist.
	 *
	 * @return void
	 */
	public function testKindsAreCoercedToUniqueNonEmptyStrings(): void {
		$store = StoreManifest::fromManifest(
			appId: 'dossiq',
			manifest: ['store' => ['schema' => 't', 'kinds' => ['case-type', 'case-type', '', 7, 'flow']]]
		);

		$this->assertSame(['case-type', 'flow'], array_values($store->kinds));
	}//end testKindsAreCoercedToUniqueNonEmptyStrings()

	/**
	 * A write that throws is reported per component, not as a 500.
	 *
	 * @return void
	 */
	public function testAFailedWriteIsReportedNotThrown(): void {
		$this->objectService->method('saveObject')
			->willThrowException(new RuntimeException('constraint'));

		$result = $this->installer->install(
			store: $this->store(),
			item: ['components' => [['schema' => 'caseType', 'object' => ['title' => 'Config']]]]
		);

		$this->assertFalse($result['success']);
		$this->assertSame('error', $result['components'][0]['status']);
		// The registry's internals never reach the browser.
		$this->assertStringNotContainsString('constraint', $result['components'][0]['message']);
	}//end testAFailedWriteIsReportedNotThrown()

	/**
	 * An item declaring nothing installable reports no success.
	 *
	 * @return void
	 */
	public function testAnItemWithNoComponentsIsNotASuccess(): void {
		$result = $this->installer->install(store: $this->store(), item: []);

		$this->assertFalse($result['success']);
		$this->assertSame([], $result['components']);
	}//end testAnItemWithNoComponentsIsNotASuccess()

	/**
	 * An app that declares no `store` block is DISABLED, not defaulted. The
	 * word Store promises a registry (ADR-080 Decision 4) and the engine must
	 * not promise it on an app's behalf.
	 *
	 * @return void
	 */
	public function testAManifestWithNoStoreBlockIsDisabled(): void {
		$store = StoreManifest::fromManifest(appId: 'keepiq', manifest: ['version' => '1.0.0']);

		$this->assertFalse($store->enabled);
		$this->assertFalse($store->isInstallable(slug: 'anything'));
	}//end testAManifestWithNoStoreBlockIsDisabled()

	/**
	 * Card fields fall back to the fleet's published names, and an app that
	 * declares its own gets those instead.
	 *
	 * @return void
	 */
	public function testCardFieldsDefaultAndCanBeOverridden(): void {
		$byDefault = StoreManifest::fromManifest(
			appId: 'dossiq',
			manifest: ['store' => ['schema' => 't']]
		);
		$this->assertSame(StoreManifest::DEFAULT_CARD_FIELDS, $byDefault->cardFields);

		// An EMPTY map is a declaration of nothing, not a declaration of
		// emptiness: falling through to the defaults keeps a store rendering
		// cards rather than seven blank ones.
		$empty = StoreManifest::fromManifest(
			appId: 'dossiq',
			manifest: ['store' => ['schema' => 't', 'cardFields' => []]]
		);
		$this->assertSame(StoreManifest::DEFAULT_CARD_FIELDS, $empty->cardFields);

		$own = StoreManifest::fromManifest(
			appId: 'dossiq',
			manifest: ['store' => ['schema' => 't', 'cardFields' => ['slug' => 'ref']]]
		);
		$this->assertSame(['slug' => 'ref'], $own->cardFields);
	}//end testCardFieldsDefaultAndCanBeOverridden()

	/**
	 * The allowlist is coerced: non-strings and blanks are dropped and
	 * duplicates collapse, so a hand-edited manifest cannot smuggle a truthy
	 * non-string past `in_array(..., true)` and silently allow nothing.
	 *
	 * @return void
	 */
	public function testTheAllowlistIsCoercedToUniqueNonEmptyStrings(): void {
		$store = StoreManifest::fromManifest(
			appId: 'dossiq',
			manifest: ['store' => ['schema' => 't', 'installable' => ['caseType', 'caseType', '', '  ', 42, null, 'flow']]]
		);

		$this->assertSame(['caseType', 'flow'], array_values($store->installable));
		$this->assertTrue($store->isInstallable(slug: 'caseType'));
		$this->assertFalse($store->isInstallable(slug: ''));
	}//end testTheAllowlistIsCoercedToUniqueNonEmptyStrings()

	/**
	 * Built-in items keep only the entries that are objects. A registry-shaped
	 * string in the list would otherwise reach the page and render as a card
	 * with no title.
	 *
	 * @return void
	 */
	public function testBuiltInKeepsOnlyObjectEntries(): void {
		$store = StoreManifest::fromManifest(
			appId: 'dossiq',
			manifest: ['store' => ['schema' => 't', 'builtIn' => [
				['slug' => 'a', 'title' => 'A'],
				'not-an-object',
				['slug' => 'b', 'title' => 'B'],
			]]]
		);

		$this->assertCount(2, $store->builtIn);
		$this->assertSame(['a', 'b'], array_column($store->builtIn, 'slug'));
	}//end testBuiltInKeepsOnlyObjectEntries()

	/**
	 * A `store` key that is not an object disables the store rather than
	 * half-configuring one.
	 *
	 * @return void
	 */
	public function testAMalformedStoreKeyDisablesTheStore(): void {
		foreach ([null, 'yes', 42, []] as $block) {
			$store = StoreManifest::fromManifest(appId: 'dossiq', manifest: ['store' => $block]);
			if (is_array($block) === true) {
				// An empty OBJECT is a declaration, so it stays enabled — and
				// its empty allowlist refuses every install.
				$this->assertTrue($store->enabled);
				$this->assertFalse($store->isInstallable(slug: 'anything'));
				continue;
			}

			$this->assertFalse($store->enabled);
		}
	}//end testAMalformedStoreKeyDisablesTheStore()

	/**
	 * The local register defaults to the app id, and an app whose register
	 * slug differs says so. A schema slug is not unique across the fleet, so
	 * an unscoped write can land in another app's register.
	 *
	 * @return void
	 */
	public function testLocalRegisterDefaultsToTheAppIdAndCanBeOverridden(): void {
		$byDefault = StoreManifest::fromManifest(
			appId: 'buildiq',
			manifest: ['store' => ['schema' => 'application-template']]
		);
		$this->assertSame('buildiq', $byDefault->localRegister);

		$explicit = StoreManifest::fromManifest(
			appId: 'buildiq',
			manifest: ['store' => ['schema' => 'application-template', 'localRegister' => 'openbuild']]
		);
		$this->assertSame('openbuild', $explicit->localRegister);
	}//end testLocalRegisterDefaultsToTheAppIdAndCanBeOverridden()

	/**
	 * 🔴 A loosened install posture does NOT widen the allowlist.
	 *
	 * `installAuth` decides WHO may install; `installable` decides WHAT an
	 * install may write. The two keys sit side by side in the same block and
	 * read like a pair. They are not one: relaxing the gate on the door does
	 * not enlarge the room.
	 *
	 * @return void
	 */
	public function testAnAuthenticatedStoreStillRefusesADisallowedSchema(): void {
		$manifest = StoreManifest::fromManifest('demo', [
			'store' => [
				'schema' => 'template',
				'installAuth' => 'authenticated',
				'installable' => ['caseType'],
			],
		]);

		$this->assertTrue($manifest->isInstallable('caseType'));
		$this->assertFalse(
			$manifest->isInstallable('case'),
			'A weaker install posture must not widen what may be written.'
		);
	}

	/**
	 * An empty allowlist still refuses everything, whatever the posture.
	 *
	 * @return void
	 */
	public function testAnAuthenticatedStoreWithAnEmptyAllowlistRefusesEverything(): void {
		$manifest = StoreManifest::fromManifest('demo', [
			'store' => ['schema' => 'template', 'installAuth' => 'authenticated'],
		]);

		$this->assertFalse($manifest->isInstallable('caseType'));
		$this->assertFalse($manifest->isInstallable('anything'));
	}

	/**
	 * An allowlisted config key is written into the DECLARING app's namespace.
	 *
	 * @return void
	 */
	public function testAnAllowlistedConfigKeyIsWritten(): void {
		$store = StoreManifest::fromManifest('integriq', [
			'store' => ['schema' => 'catalog_item', 'configurable' => ['enableSoapAdapter']],
		]);
		$this->appConfig->expects($this->once())
			->method('setValueString')
			->with('integriq', 'enableSoapAdapter', '1');

		$report = $this->installer->install($store, [
			'components' => [
				['op' => 'setAppConfig', 'key' => 'enableSoapAdapter', 'value' => true],
			],
		]);

		$this->assertTrue($report['success']);
		$this->assertSame('installed', $report['components'][0]['status']);
	}

	/**
	 * 🔴 A key outside the allowlist is refused.
	 *
	 * An app's config namespace holds registry URLs, tokens and feature flags,
	 * so an unallowlisted write is a remote actor toggling whatever it names.
	 *
	 * @return void
	 */
	public function testAConfigKeyOutsideTheAllowlistIsRefused(): void {
		$store = StoreManifest::fromManifest('integriq', [
			'store' => ['schema' => 'catalog_item', 'configurable' => ['enableSoapAdapter']],
		]);
		$this->appConfig->expects($this->never())->method('setValueString');

		$report = $this->installer->install($store, [
			'components' => [
				['op' => 'setAppConfig', 'key' => 'store_registry_token', 'value' => 'stolen'],
			],
		]);

		$this->assertFalse($report['success']);
		$this->assertSame('refused', $report['components'][0]['status']);
	}

	/**
	 * 🔴 An absent `configurable` list refuses every key.
	 *
	 * The empty-means-refuse default, matching `installable`. An app that
	 * declares a store and forgets the list gets refused config writes rather
	 * than an open door onto its own settings.
	 *
	 * @return void
	 */
	public function testAnAbsentConfigurableListRefusesEveryKey(): void {
		$store = StoreManifest::fromManifest('integriq', ['store' => ['schema' => 'catalog_item']]);
		$this->appConfig->expects($this->never())->method('setValueString');

		$report = $this->installer->install($store, [
			'components' => [['op' => 'setAppConfig', 'key' => 'anything', 'value' => true]],
		]);

		$this->assertSame('refused', $report['components'][0]['status']);
	}

	/**
	 * A structured value is refused rather than silently serialised.
	 *
	 * @return void
	 */
	public function testAStructuredConfigValueIsRefused(): void {
		$store = StoreManifest::fromManifest('integriq', [
			'store' => ['schema' => 'catalog_item', 'configurable' => ['someKey']],
		]);
		$this->appConfig->expects($this->never())->method('setValueString');

		$report = $this->installer->install($store, [
			'components' => [['op' => 'setAppConfig', 'key' => 'someKey', 'value' => ['a' => 1]]],
		]);

		$this->assertSame('refused', $report['components'][0]['status']);
	}

	/**
	 * An unknown op is refused for that component, and the rest still install.
	 *
	 * @return void
	 */
	public function testAnUnknownOpDoesNotAbortTheOtherComponents(): void {
		$store = StoreManifest::fromManifest('demo', [
			'store' => ['schema' => 'template', 'installable' => ['caseType']],
		]);
		$this->objectService->expects($this->once())->method('saveObject');

		$report = $this->installer->install($store, [
			'components' => [
				['op' => 'summonDaemon', 'schema' => 'caseType'],
				['schema' => 'caseType', 'object' => ['title' => 'A case type']],
			],
		]);

		$this->assertFalse($report['success']);
		$this->assertSame('refused', $report['components'][0]['status']);
		$this->assertSame('installed', $report['components'][1]['status']);
	}

	/**
	 * A component with no op still writes an object, exactly as before.
	 *
	 * @return void
	 */
	public function testAComponentWithNoOpStillWritesAnObject(): void {
		$store = StoreManifest::fromManifest('demo', [
			'store' => ['schema' => 'template', 'installable' => ['caseType']],
		]);
		$this->objectService->expects($this->once())->method('saveObject');

		$report = $this->installer->install($store, [
			'components' => [['schema' => 'caseType', 'object' => ['title' => 'A case type']]],
		]);

		$this->assertTrue($report['success']);
	}
}
