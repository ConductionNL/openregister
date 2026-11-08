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
use OCP\IURLGenerator;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Pins what updateRole() does per branch: the CardDAV reads and writes, the logged
 * warning, the mapper update and what escapes, in call order.
 *
 * @covers \OCA\OpenRegister\Service\ContactService
 * @uses   \OCA\OpenRegister\Db\ContactLink
 */
class ContactServiceUpdateRoleGoldenTest extends TestCase {

	/**
	 * @var list<array>
	 */
	private array $calls = [];

	/**
	 * The service over one scenario.
	 *
	 * @param string    $card  The card data, '' for no card.
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
			->onlyMethods(['find', 'update'])
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
		$mapper->method('update')->willReturnCallback(function (ContactLink $link) {
			$this->calls[] = ['link.update', $link->getId(), $link->getRole()];
			return $link;
		});

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(function ($message) {
			$this->calls[] = ['log.warning', $message];
		});

		return new ContactService(
			$mapper,
			$cardDav,
			$this->createMock(IUserSession::class),
			$logger,
			$this->createMock(IURLGenerator::class)
		);
	}

	/**
	 * The scenarios: link id, card data, what getCard throws, the object scope.
	 *
	 * @return array<string,array>
	 */
	public static function scenarios(): array {
		$card = "BEGIN:VCARD\nVERSION:3.0\nUID:u1\nFN:Jan\nX-OPENREGISTER-ROLE:old\nX-OPENREGISTER-OBJECT:obj-1\nEND:VCARD\n";
		return [
			'vcard retagged' => [1, $card, null, 'obj-1'],
			'vcard retagged unscoped' => [1, $card, null, null],
			'card gone' => [1, '', null, 'obj-1'],
			'user link has no card' => [2, $card, null, 'obj-1'],
			'card read fails' => [1, $card, new Exception('dav down'), 'obj-1'],
			'card read errors' => [1, $card, new Error('fatal'), 'obj-1'],
			'other object' => [1, $card, null, 'obj-2'],
		];
	}

	/**
	 * Every branch of updateRole() keeps its calls and outcome.
	 *
	 * @param int            $linkId     The link id.
	 * @param string         $card       The card data.
	 * @param Throwable|null $fail       What getCard throws.
	 * @param string|null    $objectUuid The object scope.
	 *
	 * @dataProvider scenarios
	 *
	 * @return void
	 */
	public function testUpdateRole(int $linkId, string $card, ?Throwable $fail, ?string $objectUuid): void {
		$service = $this->service($card, $fail);
		try {
			$link = $service->updateRole($linkId, 'applicant', $objectUuid);
			$this->calls[] = ['result', $link->getId(), $link->getRole()];
		} catch (Throwable $e) {
			$this->calls[] = ['thrown', get_class($e), $e->getMessage(), $e->getCode()];
		}

		$expected = [
			'vcard retagged' => [
				['link.find', 1],
				['card.get', 3, 'jan.vcf'],
				['card.update', 3, 'jan.vcf', "BEGIN:VCARD\nVERSION:3.0\nUID:u1\nFN:Jan\nX-OPENREGISTER-OBJECT:obj-1\nX-OPENREGISTER-ROLE:applicant\nEND:VCARD\n"],
				['link.update', 1, 'applicant'],
				['result', 1, 'applicant'],
			],
			'card gone' => [
				['link.find', 1],
				['card.get', 3, 'jan.vcf'],
				['link.update', 1, 'applicant'],
				['result', 1, 'applicant'],
			],
			'user link has no card' => [
				['link.find', 2],
				['link.update', 2, 'applicant'],
				['result', 2, 'applicant'],
			],
			'card read fails' => [
				['link.find', 1],
				['card.get', 3, 'jan.vcf'],
				['log.warning', 'Failed to update vCard role: dav down'],
				['link.update', 1, 'applicant'],
				['result', 1, 'applicant'],
			],
			'card read errors' => [
				['link.find', 1],
				['card.get', 3, 'jan.vcf'],
				['thrown', Error::class, 'fatal', 0],
			],
			'other object' => [
				['link.find', 1],
				['thrown', Exception::class, 'Contact link not found', 404],
			],
		];
		$expected['vcard retagged unscoped'] = $expected['vcard retagged'];

		$this->assertSame($expected[$this->dataName()], $this->calls);
	}
}
