<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\OpenRegister\Tests\Unit\Service;

use Error;
use Exception;
use OCA\DAV\CardDAV\CardDavBackend;
use OCA\OpenRegister\Db\ContactLink;
use OCA\OpenRegister\Db\ContactLinkMapper;
use OCA\OpenRegister\Service\ContactService;
use OCA\OpenRegister\Service\LinkSessionRuntime;
use OCP\IURLGenerator;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Pins what unlinkContact() does per branch: the CardDAV reads and writes, the
 * logged warning with its context, and the link-row delete, in call order.
 *
 * @covers \OCA\OpenRegister\Service\ContactService
 * @uses   \OCA\OpenRegister\Db\ContactLink
 */
class ContactServiceUnlinkGoldenTest extends TestCase {

	/**
	 * @var list<array>
	 */
	private array $calls = [];

	/**
	 * The service over one scenario.
	 *
	 * @param string         $card The card data, '' for no card.
	 * @param Throwable|null $fail What getCard throws, or null.
	 *
	 * @return ContactService
	 */
	private function service(string $card, ?Throwable $fail): ContactService {
		$cardDav = $this->createMock(CardDavBackend::class);
		$cardDav->method('getCard')->willReturnCallback(function ($addressbookId, $uri) use ($card, $fail) {
			$this->calls[] = ['card.get', $addressbookId, $uri];
			if ($fail !== null) {
				throw $fail;
			}
			if ($card === '') {
				return false;
			}
			return ['carddata' => $card];
		});
		$cardDav->method('updateCard')->willReturnCallback(function ($addressbookId, $uri, $data) {
			$this->calls[] = ['card.update', $addressbookId, $uri, str_replace("\r\n", "\n", $data)];
			return 'etag';
		});

		$mapper = $this->getMockBuilder(ContactLinkMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['find', 'delete'])
			->getMock();
		$mapper->method('find')->willReturnCallback(function ($id) {
			$this->calls[] = ['link.find', $id];
			$link = new ContactLink();
			$link->setId($id);
			$link->setObjectUuid('obj-1');
			$link->setAddressbookId(3);
			$link->setContactUri('jan.vcf');
			$link->setRole('old');
			if ($id === 2) {
				$link->setUserId('alice');
			}
			return $link;
		});
		$mapper->method('delete')->willReturnCallback(function (ContactLink $link) {
			$this->calls[] = ['link.delete', $link->getId()];
			return $link;
		});

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(function ($message, $context = []) {
			$this->calls[] = ['log.warning', $message, $context];
		});

		return new ContactService(
			$mapper,
			$cardDav,
			new LinkSessionRuntime($this->createMock(IUserSession::class), $logger),
			$this->createMock(IURLGenerator::class)
		);
	}

	/**
	 * The scenarios: link id, card data, what getCard throws, and the expected calls.
	 *
	 * @return array<string,array>
	 */
	public static function scenarios(): array {
		$card = "BEGIN:VCARD\nVERSION:3.0\nUID:u1\nFN:Jan\nX-OPENREGISTER-ROLE:old\nX-OPENREGISTER-OBJECT:obj-1\nEND:VCARD\n";
		$warning = static fn (string $message): array => [
			'log.warning',
			'Failed to clean vCard properties (link row will still be removed): ' . $message,
			['linkId' => 1, 'addressbookId' => 3, 'contactUri' => 'jan.vcf'],
		];

		return [
			'vcard cleaned' => [1, $card, null, [
				['link.find', 1],
				['card.get', 3, 'jan.vcf'],
				['card.update', 3, 'jan.vcf', "BEGIN:VCARD\nVERSION:3.0\nUID:u1\nFN:Jan\nEND:VCARD\n"],
				['link.delete', 1],
			]],
			'card gone' => [1, '', null, [
				['link.find', 1],
				['card.get', 3, 'jan.vcf'],
				['link.delete', 1],
			]],
			'user link has no card' => [2, $card, null, [
				['link.find', 2],
				['link.delete', 2],
			]],
			'card read fails' => [1, $card, new Exception('dav down'), [
				['link.find', 1],
				['card.get', 3, 'jan.vcf'],
				$warning('dav down'),
				['link.delete', 1],
			]],
			'card read errors' => [1, $card, new Error('fatal'), [
				['link.find', 1],
				['card.get', 3, 'jan.vcf'],
				$warning('fatal'),
				['link.delete', 1],
			]],
		];
	}

	/**
	 * Every branch of unlinkContact() keeps its calls, in order.
	 *
	 * @param int            $linkId   The link id.
	 * @param string         $card     The card data.
	 * @param Throwable|null $fail     What getCard throws.
	 * @param array          $expected The calls, in order.
	 *
	 * @dataProvider scenarios
	 *
	 * @return void
	 */
	public function testUnlinkContact(int $linkId, string $card, ?Throwable $fail, array $expected): void {
		$this->service($card, $fail)->unlinkContact($linkId, 'obj-1');

		$this->assertSame($expected, $this->calls);
	}
}
