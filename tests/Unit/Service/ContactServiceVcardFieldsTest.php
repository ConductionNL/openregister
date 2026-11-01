<?php

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace Unit\Service;

use OCA\DAV\CardDAV\CardDavBackend;
use OCA\OpenRegister\Db\ContactLinkMapper;
use OCA\OpenRegister\Service\ContactService;
use OCP\IURLGenerator;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Characterization test for ContactService::extractVcardFields().
 *
 * Each case is a set of CardDAV coordinates, a contact uid and what the
 * CardDAV backend answers; the phone, organisation and avatar URL the
 * service derives from it are pinned exactly.
 *
 * @covers \OCA\OpenRegister\Service\ContactService
 */
class ContactServiceVcardFieldsTest extends TestCase {

	private const CARD_START = "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:u1\r\nFN:Jan\r\n";
	private const CARD_END   = "END:VCARD\r\n";

	/**
	 * @return array<string, array{0: ?int, 1: ?string, 2: ?string, 3: mixed, 4: array{phone: ?string, org: ?string, avatarUrl: ?string}}>
	 */
	public static function cases(): array {
		$card = static fn (string $body): array => ['carddata' => self::CARD_START . $body . self::CARD_END];
		$none = ['phone' => null, 'org' => null, 'avatarUrl' => null];
		$fallback = static fn (string $uri): array => ['phone' => null, 'org' => null, 'avatarUrl' => $uri];

		return [
			'no addressbook, uid gives the per-uid avatar route' => [null, 'jan.vcf', 'a b/c', 'unused', $fallback('/index.php/apps/contacts/photo/a%20b%2Fc')],
			'no contact uri, no uid' => [2, null, null, 'unused', $none],
			'empty contact uri, empty uid' => [2, '', '', 'unused', $none],
			'empty contact uri, uid' => [2, '', 'u9', 'unused', $fallback('/index.php/apps/contacts/photo/u9')],
			'backend throws, uid ignored' => [2, 'jan.vcf', 'u1', new RuntimeException('down'), $none],
			'card not found, uid ignored' => [2, 'jan.vcf', 'u1', false, $none],
			'card without carddata' => [2, 'jan.vcf', 'u1', ['uri' => 'jan.vcf'], $none],
			'unparseable carddata' => [2, 'jan.vcf', 'u1', ['carddata' => "BEGIN:VCARD\r\nnot a vcard"], $none],
			'empty card, uid fallback' => [2, 'jan.vcf', 'u1', $card(''), $fallback('/index.php/apps/contacts/photo/u1')],
			'empty card, uid is url-encoded' => [2, 'jan.vcf', 'a b/c', $card(''), $fallback('/index.php/apps/contacts/photo/a%20b%2Fc')],
			'empty card, no uid' => [2, 'jan.vcf', null, $card(''), $none],
			'first non-empty TEL wins' => [2, 'jan.vcf', null, $card("TEL;TYPE=HOME:\r\nTEL;TYPE=CELL:+31 6 1\r\nTEL:+31 6 2\r\n"), ['phone' => '+31 6 1', 'org' => null, 'avatarUrl' => null]],
			'single TEL' => [2, 'jan.vcf', null, $card("TEL:0201234\r\n"), ['phone' => '0201234', 'org' => null, 'avatarUrl' => null]],
			'only empty TEL' => [2, 'jan.vcf', null, $card("TEL:\r\n"), $none],
			'ORG primary component, trimmed' => [2, 'jan.vcf', null, $card("ORG:  Acme B.V. ;Engineering;Backend\r\n"), ['phone' => null, 'org' => 'Acme B.V.', 'avatarUrl' => null]],
			'ORG with empty primary' => [2, 'jan.vcf', null, $card("ORG: ;Engineering\r\n"), $none],
			'PHOTO uri, upper-case scheme' => [2, 'jan.vcf', 'u1', $card("PHOTO;VALUE=URI:HTTPS://example.com/j.png\r\n"), ['phone' => null, 'org' => null, 'avatarUrl' => 'HTTPS://example.com/j.png']],
			'PHOTO http uri' => [2, 'jan.vcf', null, $card("PHOTO;VALUE=URI:http://example.com/j.jpg\r\n"), ['phone' => null, 'org' => null, 'avatarUrl' => 'http://example.com/j.jpg']],
			'PHOTO data url keeps its raw mime-dir escaping' => [2, 'jan.vcf', 'u1', $card("PHOTO;VALUE=URI:data:image/gif;base64,R0lG\r\n"), ['phone' => null, 'org' => null, 'avatarUrl' => 'data:image/gif;base64\\,R0lG']],
			'PHOTO inline bytes with TYPE' => [2, 'jan.vcf', 'u1', $card("PHOTO;ENCODING=b;TYPE=PNG:QUJD\r\n"), ['phone' => null, 'org' => null, 'avatarUrl' => 'data:image/png;base64,QUJD']],
			'PHOTO inline bytes without TYPE' => [2, 'jan.vcf', 'u1', $card("PHOTO;ENCODING=b:QUJD\r\n"), ['phone' => null, 'org' => null, 'avatarUrl' => 'data:image/jpeg;base64,QUJD']],
			'PHOTO inline bytes with empty TYPE' => [2, 'jan.vcf', 'u1', $card("PHOTO;ENCODING=b;TYPE=:QUJD\r\n"), ['phone' => null, 'org' => null, 'avatarUrl' => 'data:image/jpeg;base64,QUJD']],
			'empty PHOTO falls back to uid route' => [2, 'jan.vcf', 'u1', $card("PHOTO;VALUE=URI:\r\n"), $fallback('/index.php/apps/contacts/photo/u1')],
			'all three fields' => [7, 'jan.vcf', 'u1', $card("TEL:+31 6 3\r\nORG:Gemeente;Burgerzaken\r\nPHOTO;VALUE=URI:https://example.com/a.jpg\r\n"), ['phone' => '+31 6 3', 'org' => 'Gemeente', 'avatarUrl' => 'https://example.com/a.jpg']],
		];
	}//end cases()

	#[DataProvider('cases')]
	public function testExtractVcardFields(?int $addressbookId, ?string $contactUri, ?string $contactUid, mixed $backend, array $expected): void {
		$cardDav = $this->createMock(CardDavBackend::class);
		if ($backend === 'unused') {
			$cardDav->expects($this->never())->method('getCard');
		} elseif ($backend instanceof RuntimeException) {
			$cardDav->expects($this->once())->method('getCard')->with($addressbookId, $contactUri)->willThrowException($backend);
		} else {
			$cardDav->expects($this->once())->method('getCard')->with($addressbookId, $contactUri)->willReturn($backend);
		}

		$service = new ContactService(
			$this->createMock(ContactLinkMapper::class),
			$cardDav,
			$this->createMock(IUserSession::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(IURLGenerator::class)
		);

		$method = new ReflectionMethod(ContactService::class, 'extractVcardFields');
		$actual = $method->invoke($service, $addressbookId, $contactUri, $contactUid);

		$this->assertSame($expected, $actual);
	}//end testExtractVcardFields()
}//end class
