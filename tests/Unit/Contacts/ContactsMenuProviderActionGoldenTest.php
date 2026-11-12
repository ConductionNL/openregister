<?php

declare(strict_types=1);

/**
 * ContactsMenuProvider action golden test.
 *
 * Pins the exact actions (icon, label, URL, priority) the provider injects and
 * the context it hands to the deep-link resolver, so the internal reshaping of
 * the helper chain cannot change what a user sees in the contacts popup.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Contacts
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 */

namespace Unit\Contacts;

use OCA\OpenRegister\Contacts\ContactsMenuProvider;
use OCA\OpenRegister\Contacts\Support\ContactsMenuPlatform;
use OCA\OpenRegister\Contacts\Support\ContactsMenuSources;
use OCA\OpenRegister\Service\ContactMatchingService;
use OCA\OpenRegister\Service\DeepLinkRegistryService;
use OCP\Contacts\ContactsMenu\IActionFactory;
use OCP\Contacts\ContactsMenu\IEntry;
use OCP\Contacts\ContactsMenu\ILinkAction;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Golden test for the actions ContactsMenuProvider injects.
 */
class ContactsMenuProviderActionGoldenTest extends TestCase {

	/**
	 * Run the provider for one contact and record every action and resolver call.
	 *
	 * @param array       $matches  Matches the matcher returns.
	 * @param string      $fullName Contact full name.
	 * @param array       $urls     Deep-link URLs by uuid (missing = null).
	 * @param array       $icons    Deep-link icons by schema id (missing = null).
	 *
	 * @return array{actions: list<array>, resolveUrl: list<array>, priorities: list<int>}
	 */
	private function processContact(array $matches, string $fullName, array $urls, array $icons): array {
		$matching = $this->createMock(ContactMatchingService::class);
		$matching->method('matchContact')->willReturn($matches);
		$matching->method('getRelatedObjectCounts')->willReturn(['Zaken' => 2, 'Leads' => 1]);

		$record = ['actions' => [], 'resolveUrl' => [], 'priorities' => []];
		$deepLinks = $this->createMock(DeepLinkRegistryService::class);
		$deepLinks->method('resolveUrl')->willReturnCallback(
			static function (int $registerId, int $schemaId, array $object, array $context) use (&$record, $urls) {
				$record['resolveUrl'][] = [$registerId, $schemaId, $object['uuid'], $context];
				return $urls[$object['uuid']] ?? null;
			}
		);
		$deepLinks->method('resolveIcon')->willReturnCallback(
			static fn (int $registerId, int $schemaId) => $icons[$schemaId] ?? null
		);

		$factory = $this->createMock(IActionFactory::class);
		$factory->method('newLinkAction')->willReturnCallback(
			function (string $icon, string $name, string $href, string $appId) use (&$record) {
				$record['actions'][] = [$icon, $name, $href, $appId];
				$action = $this->createMock(ILinkAction::class);
				$action->method('setPriority')->willReturnCallback(
					static function (int $priority) use (&$record): void {
						$record['priorities'][] = $priority;
					}
				);
				return $action;
			}
		);

		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRouteAbsolute')->willReturn('http://nc/apps/openregister/');
		$urlGenerator->method('imagePath')->willReturn('/img/app-dark.svg');
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);

		$entry = $this->createMock(IEntry::class);
		$entry->method('getEMailAddresses')->willReturn(['jan+test@example.nl']);
		$entry->method('getFullName')->willReturn($fullName);
		$entry->method('getProperty')->willReturnCallback(
			static fn (string $key) => $key === 'UID' ? 'uid-7' : null
		);
		$entry->expects($this->exactly(count($matches) + 1))->method('addAction');

		$provider = new ContactsMenuProvider(
			new ContactsMenuSources($matching, $deepLinks),
			new ContactsMenuPlatform($factory, $urlGenerator, $l10n, $this->createMock(LoggerInterface::class))
		);
		$provider->process($entry);

		return $record;
	}//end processContact()

	/**
	 * The badge and every entity action carry pinned icon, label, URL and priority.
	 *
	 * @return void
	 */
	public function testActionsArePinned(): void {
		$matches = [
			['uuid' => 'u-1', 'register' => ['id' => 3], 'schema' => ['id' => 4], 'title' => 'Zaak 1'],
			['uuid' => 'u 2', 'register' => ['id' => '5'], 'schema' => ['id' => 6]],
		];
		$record = $this->processContact($matches, 'Jan', ['u-1' => 'https://deep/u-1'], [4 => '/deep.svg']);

		$this->assertSame(
			[
				['/img/app-dark.svg', '2 Zaken, 1 Leads', 'http://nc/apps/openregister/#/search?_search=jan%2Btest%40example.nl', 'openregister'],
				['/deep.svg', 'View in OpenRegister (Zaak 1)', 'https://deep/u-1', 'openregister'],
				['/img/app-dark.svg', 'View in OpenRegister (Unknown)', 'http://nc/apps/openregister/#/objects/u+2', 'openregister'],
			],
			$record['actions']
		);
		$this->assertSame([0, 10, 10], $record['priorities']);
		$context = ['contactId' => 'uid-7', 'contactEmail' => 'jan+test@example.nl', 'contactName' => 'Jan'];
		$this->assertSame(
			[[3, 4, 'u-1', $context], [5, 6, 'u 2', $context]],
			$record['resolveUrl']
		);
	}//end testActionsArePinned()

	/**
	 * A contact without a name hands an empty contactName and a match without uuid an empty uuid.
	 *
	 * @return void
	 */
	public function testMissingNameAndUuidBecomeEmptyStrings(): void {
		$matches = [['register' => [], 'schema' => [], 'title' => 'T']];
		$record = $this->processContact($matches, '', [], []);

		$this->assertSame(
			[[0, 0, '', ['contactId' => 'uid-7', 'contactEmail' => 'jan+test@example.nl', 'contactName' => '']]],
			$record['resolveUrl']
		);
		$this->assertSame('http://nc/apps/openregister/#/objects/', $record['actions'][1][2]);
	}//end testMissingNameAndUuidBecomeEmptyStrings()
}//end class
