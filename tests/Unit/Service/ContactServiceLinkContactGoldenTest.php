<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\DAV\CardDAV\CardDavBackend;
use OCA\OpenRegister\Db\ContactLink;
use OCA\OpenRegister\Db\ContactLinkMapper;
use OCA\OpenRegister\Service\ContactService;
use OCA\OpenRegister\Service\LinkSessionRuntime;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Records the CardDAV calls, mapper writes and the returned link of linkContact(), per scenario,
 * against fixtures/contact-link-contact-golden.json. Set UPDATE_GOLDEN=1 to rewrite the fixture.
 * The linkedAt timestamp is replaced by a fixed marker before comparing.
 *
 * @covers \OCA\OpenRegister\Service\ContactService
 * @uses   \OCA\OpenRegister\Db\ContactLink
 */
class ContactServiceLinkContactGoldenTest extends TestCase {

	/**
	 * @var list<array>
	 */
	private array $calls = [];

	/**
	 * Builds the service for one scenario.
	 *
	 * @param array $s Scenario settings.
	 *
	 * @return ContactService
	 */
	private function service(array $s): ContactService {
		$cardDav = $this->createMock(CardDavBackend::class);
		$cardDav->method('getCard')->willReturnCallback(function ($addressbookId, $uri) use ($s) {
			$this->calls[] = ['card.get', $addressbookId, $uri];
			if ($s['card'] === null) {
				return false;
			}
			return ['carddata' => $s['card']];
		});
		$cardDav->method('updateCard')->willReturnCallback(function ($addressbookId, $uri, $data) {
			$this->calls[] = ['card.update', $addressbookId, $uri, str_replace("\r\n", "\n", $data)];
			return 'etag';
		});

		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($s['user'] === true) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn('alice');
		}
		$session->method('getUser')->willReturn($user);

		$mapper = $this->createMock(ContactLinkMapper::class);
		$mapper->method('findByObjectAndContact')->willReturnCallback(function ($objectUuid, $contactUid) use ($s) {
			$this->calls[] = ['link.find', $objectUuid, $contactUid];
			if ($s['existing'] === false) {
				return null;
			}
			$link = new ContactLink();
			$link->setObjectUuid($objectUuid);
			$link->setContactUid($contactUid);
			$link->setSchemaId(77);
			$link->setRole('old-role');
			return $link;
		});
		foreach (['insert', 'update'] as $write) {
			$mapper->method($write)->willReturnCallback(function (ContactLink $link) use ($write) {
				$this->calls[] = ['link.' . $write, $this->row($link)];
				return $link;
			});
		}

		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRoute')->willReturnCallback(fn ($route, $args = []) => $route . '?' . http_build_query($args));
		$urls->method('getAbsoluteURL')->willReturnCallback(fn ($url) => 'https://cloud' . $url);

		return new ContactService($mapper, $cardDav, new LinkSessionRuntime($session, $this->createMock(LoggerInterface::class)), $urls);
	}

	/**
	 * The stored fields of a link, without the clock.
	 *
	 * @param ContactLink $link The link.
	 *
	 * @return array The fields.
	 */
	private function row(ContactLink $link): array {
		$row = $link->jsonSerialize();
		$row['linkedAt'] = $row['linkedAt'] === null ? null : 'CLOCK';
		return $row;
	}

	/**
	 * Scenario table.
	 *
	 * @return array<string, array>
	 */
	public static function scenarios(): array {
		$full = "BEGIN:VCARD\nVERSION:3.0\nUID:uid-9\nFN:Jan Jansen\nEMAIL:jan@example.org\nTEL:+31 6 1234\nORG:Gemeente;Burgerzaken\nEND:VCARD\n";
		$bare = "BEGIN:VCARD\nVERSION:3.0\nFN:No Uid\nEND:VCARD\n";
		$base = ['card' => $full, 'user' => true, 'existing' => false, 'role' => 'applicant', 'schema' => 12];
		return [
			'new link' => [$base],
			'new link without role or schema' => [['role' => null, 'schema' => null] + $base],
			'existing link refreshed' => [['existing' => true] + $base],
			'existing link without schema keeps it' => [['existing' => true, 'schema' => null, 'role' => null] + $base],
			'card without uid, name only' => [['card' => $bare, 'existing' => true] + $base],
			'contact missing' => [['card' => null] + $base],
			'nobody logged in' => [['user' => false] + $base],
		];
	}

	/**
	 * @dataProvider scenarios
	 */
	public function testLinkContactGolden(array $s): void {
		$service = $this->service($s);
		try {
			$link = $service->linkContact('obj-1', 5, 3, 'jan.vcf', $s['role'], $s['schema']);
			$this->calls[] = ['result', $this->row($link)];
		} catch (Throwable $e) {
			$this->calls[] = ['thrown', get_class($e), $e->getMessage(), $e->getCode()];
		}

		$file = __DIR__ . '/fixtures/contact-link-contact-golden.json';
		$name = $this->dataName();
		$all = is_file($file) === true ? json_decode(file_get_contents($file), true) : [];
		$actual = json_decode(json_encode($this->calls, JSON_UNESCAPED_UNICODE), true);
		if (getenv('UPDATE_GOLDEN') === '1') {
			$all[$name] = $actual;
			file_put_contents($file, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
		}
		$this->assertSame($all[$name], $actual);
	}
}
