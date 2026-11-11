<?php

/**
 * Characterisation golden for the listing and create-and-link paths of ContactService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Service\ContactPlacement;
use DateTime;
use OCA\DAV\CardDAV\CardDavBackend;
use OCA\OpenRegister\Db\ContactLink;
use OCA\OpenRegister\Db\ContactLinkMapperInterface;
use OCA\OpenRegister\Service\ContactService;
use OCA\OpenRegister\Service\LinkSessionRuntime;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Pins results, CardDAV and mapper calls and log lines of getContactsForObject()
 * and createAndLinkContact(), compared with
 * tests/Unit/Service/fixtures/contact-service-listing-golden.json.
 * Run with UPDATE_GOLDEN=1 to re-record.
 *
 * @covers \OCA\OpenRegister\Service\ContactService
 * @uses   \OCA\OpenRegister\Db\ContactLink
 */
class ContactServiceListingGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/contact-service-listing-golden.json';

	private array $calls = [];

	private static function cards(): array {
		return [
			'full.vcf' => "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:uid-1\r\nFN:Jan\r\nTEL:+31 6 1\r\nORG:Gemeente;Zaken\r\nEND:VCARD\r\n",
			'bare.vcf' => "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:uid-2\r\nFN:Bare\r\nEND:VCARD\r\n",
		];
	}//end cards()

	private static function world(array $over): array {
		return $over + [
			'uid' => 'alice',
			'links' => [],
			'updateThrows' => false,
			'addressbooks' => [1 => ['id' => 1, 'uri' => 'contacts'], 2 => ['id' => 2, 'uri' => ''], 3 => ['id' => 3]],
			'abThrows' => false,
			'userBooks' => [['id' => 1, 'uri' => 'contacts']],
		];
	}//end world()

	private static function cases(): array {
		$fresh = ['contactUid' => 'uid-1', 'addressbookId' => 1, 'contactUri' => 'full.vcf', 'phone' => 'cached', 'linkedAt' => 'recent'];
		$stale = ['contactUid' => 'uid-1', 'addressbookId' => 1, 'contactUri' => 'full.vcf', 'phone' => 'cached', 'linkedAt' => 'old'];
		$uncached = ['contactUid' => 'uid-2', 'addressbookId' => 1, 'contactUri' => 'bare.vcf', 'linkedAt' => 'recent'];
		$neverLinked = ['contactUid' => 'uid-3', 'addressbookId' => 2, 'contactUri' => 'gone.vcf', 'org' => 'X', 'linkedAt' => null];
		$userLink = ['userId' => 'bob', 'contactUid' => 'u-bob', 'addressbookId' => 1, 'linkedAt' => 'old'];
		$noCoords = ['contactUid' => 'uid-4', 'addressbookId' => null, 'contactUri' => null, 'avatarUrl' => 'a', 'linkedAt' => 'recent'];
		$noUid = ['contactUid' => '', 'addressbookId' => 1, 'contactUri' => 'full.vcf', 'phone' => 'p', 'linkedAt' => 'recent'];
		$oddBook = ['contactUid' => 'uid-5', 'addressbookId' => 3, 'contactUri' => 'full.vcf', 'phone' => 'p', 'linkedAt' => 'recent'];
		$noBook = ['contactUid' => 'uid-6', 'addressbookId' => 9, 'contactUri' => 'full.vcf', 'phone' => 'p', 'linkedAt' => 'recent'];
		$all = [$fresh, $stale, $uncached, $neverLinked, $userLink, $noCoords, $noUid, $oddBook, $noBook];
		$contact = ['displayName' => 'Piet', 'fullName' => 'ignored', 'phone' => '06', 'email' => 'p@x.nl', 'org' => 'Org', 'role' => 'owner'];
		return [
			'list none' => ['getContactsForObject', ['obj-1'], []],
			'list all' => ['getContactsForObject', ['obj-1'], ['links' => $all]],
			'list update fails' => ['getContactsForObject', ['obj-1'], ['links' => [$stale, $uncached], 'updateThrows' => true]],
			'list addressbook throws' => ['getContactsForObject', ['obj-1'], ['links' => [$fresh], 'abThrows' => true]],
			'create no user' => ['createAndLinkContact', [new ContactPlacement('obj-1', 3, 7, 'owner'), $contact], ['uid' => null]],
			'create no addressbook' => ['createAndLinkContact', [new ContactPlacement('obj-1', 3, 7, 'owner'), $contact], ['userBooks' => []]],
			'create full' => ['createAndLinkContact', [new ContactPlacement('obj-1', 3, 7, 'owner'), $contact], []],
			'create minimal' => ['createAndLinkContact', [new ContactPlacement('obj-1', 3, null, null), []], []],
			'create full name fallback' => ['createAndLinkContact', [new ContactPlacement('obj-1', 3, null, null), ['fullName' => 'Klaas', 'email' => '', 'phone' => '0', 'org' => null]], []],
		];
	}//end cases()

	public function testListingAndCreateMatchGolden(): void {
		$actual = [];
		foreach (self::cases() as $name => [$method, $args, $over]) {
			$this->calls = [];
			$service = $this->makeService(self::world($over));
			try {
				$result = $this->normalise($service->$method(...$args));
			} catch (Throwable $e) {
				$result = ['throws' => get_class($e), 'message' => $e->getMessage(), 'code' => $e->getCode()];
			}

			$actual[$name] = ['result' => $result, 'calls' => $this->calls];
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$expected = json_decode((string)file_get_contents(self::FIXTURE), true);
		foreach ($expected as $name => $outcome) {
			$this->assertSame($outcome, $actual[$name] ?? null, $name);
		}

		$this->assertSame(array_keys($expected), array_keys($actual));
	}//end testListingAndCreateMatchGolden()

	public function record(string $call): void {
		$this->calls[] = $this->normalise($call);
	}//end record()

	/**
	 * Clock timestamps and the random contact uid become markers.
	 */
	public function normalise(mixed $value): mixed {
		if ($value instanceof ContactLink) {
			$value = $value->jsonSerialize();
		}

		if (is_array($value) === true) {
			return array_map(fn ($v) => $this->normalise($v), $value);
		}

		if (is_string($value) === true) {
			$value = (string)preg_replace('/\b[0-9A-F]{32}\b/', 'RANDUID', $value);
			return (string)preg_replace_callback(
				'/\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d[+-]\d\d:\d\d/',
				fn (array $m) => abs(strtotime($m[0]) - time()) < 3600 ? 'NOW' : $m[0],
				$value
			);
		}

		return $value;
	}//end normalise()

	private function linkRow(array $spec): ContactLink {
		$link = new ContactLink();
		$link->setObjectUuid('obj-1');
		foreach ($spec as $field => $value) {
			if ($field === 'linkedAt') {
				continue;
			}

			$link->{'set' . ucfirst($field)}($value);
		}

		if ($spec['linkedAt'] === 'recent') {
			$link->setLinkedAt(new DateTime('-10 seconds'));
		}

		if ($spec['linkedAt'] === 'old') {
			$link->setLinkedAt(new DateTime('@1600000000'));
		}

		$link->resetUpdatedFields();
		return $link;
	}//end linkRow()

	private function makeService(array $w): ContactService {
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($w['uid'] !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($w['uid']);
		}

		$session->method('getUser')->willReturn($user);
		$cardDav = $this->createMock(CardDavBackend::class);
		$cardDav->method('getCard')->willReturnCallback(
			function ($book, $uri) {
				$this->record('getCard(' . $book . ', ' . $uri . ')');
				$data = self::cards()[$uri] ?? null;
				return $data === null ? false : ['carddata' => $data];
			}
		);
		$cardDav->method('getAddressBookById')->willReturnCallback(
			function ($id) use ($w) {
				$this->record('getAddressBookById(' . $id . ')');
				if ($w['abThrows'] === true) {
					throw new RuntimeException('dav down');
				}

				return $w['addressbooks'][$id] ?? null;
			}
		);
		$cardDav->method('getAddressBooksForUser')->willReturnCallback(
			function ($principal) use ($w) {
				$this->record('getAddressBooksForUser(' . $principal . ')');
				return $w['userBooks'];
			}
		);
		$cardDav->method('createCard')->willReturnCallback(
			function ($book, $uri, $data) {
				$this->record('createCard(' . $book . ', ' . $uri . ', ' . json_encode($data) . ')');
				return 'etag';
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('debug')->willReturnCallback(fn (string $m) => $this->record('debug ' . $m));
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRoute')->willReturnCallback(fn (string $route) => '/index.php/apps/contacts/');
		$mapper = $this->createMock(ContactLinkMapperInterface::class);
		$mapper->method('findByObjectUuid')->willReturnCallback(
			fn (string $uuid) => $this->record('findByObjectUuid(' . $uuid . ')') ?? array_map(fn ($s) => $this->linkRow($s), $w['links'])
		);
		$mapper->method('countByObjectUuid')->willReturnCallback(
			fn (string $uuid) => $this->record('countByObjectUuid(' . $uuid . ')') ?? count($w['links'])
		);
		$mapper->method('update')->willReturnCallback(
			function ($link) use ($w) {
				$this->record('update(' . json_encode($this->normalise($link)) . ')');
				if ($w['updateThrows'] === true) {
					throw new RuntimeException('db down');
				}

				return $link;
			}
		);
		$mapper->method('insert')->willReturnCallback(fn ($link) => $this->record('insert(' . json_encode($this->normalise($link)) . ')') ?? $link);
		return new ContactService($mapper, $cardDav, new LinkSessionRuntime($session, $logger), $urls);
	}//end makeService()
}//end class
