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
		$this->installer = new GenericStoreInstaller(
			objectService: $this->objectService,
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
}//end class
