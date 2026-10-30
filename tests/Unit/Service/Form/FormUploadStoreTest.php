<?php

/**
 * Upload tokens hold bytes only, are checked against the property's file rules, and expire.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-upload-tokens-must-hold-bytes-only-and-expire
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Form;

use OCA\OpenRegister\Exception\FormSubmitRefusedException;
use OCA\OpenRegister\Service\File\FilePropertyRules;
use OCA\OpenRegister\Service\Form\FormUploadStore;
use OCA\OpenRegister\Tests\Unit\Service\Form\Fakes\MemoryAppData;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use OCP\ITempManager;
use PHPUnit\Framework\TestCase;

/**
 * Issue, refuse, materialise, claim and purge.
 *
 * @covers \OCA\OpenRegister\Service\Form\FormUploadStore
 * @uses \OCA\OpenRegister\Service\File\FilePropertyRules
 * @uses \OCA\OpenRegister\Exception\FormSubmitRefusedException
 * @uses \OCA\OpenRegister\Service\Form\FormUploadClaims
 */
class FormUploadStoreTest extends TestCase {

	private int $now = 1_760_000_000;

	private MemoryAppData $appData;

	private FormUploadStore $store;

	/**
	 * @var array<int, string>
	 */
	private array $temporary = [];

	protected function setUp(): void {
		$this->appData = new MemoryAppData();
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(fn (): int => $this->now);
		$temp = $this->createMock(ITempManager::class);
		$temp->method('getTemporaryFile')->willReturnCallback(
			function (): string {
				$path = (string)tempnam(sys_get_temp_dir(), 'or-form-test');
				$this->temporary[] = $path;

				return $path;
			}
		);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []): string => $parameters === [] ? $text : vsprintf($text, $parameters)
		);

		$this->store = new FormUploadStore(appData: $this->appData, time: $time, temp: $temp, rules: new FilePropertyRules(), l10n: $l10n);
	}//end setUp()

	protected function tearDown(): void {
		foreach ($this->temporary as $path) {
			if (is_file($path) === true) {
				unlink($path);
			}
		}
	}//end tearDown()

	/**
	 * A PHP upload array for some bytes.
	 *
	 * @return array{name: string, type: string, tmp_name: string, error: int, size: int}
	 */
	private function upload(string $bytes, string $type = 'application/pdf', int $size = -1): array {
		$path = (string)tempnam(sys_get_temp_dir(), 'or-form-upload');
		file_put_contents($path, $bytes);
		$this->temporary[] = $path;

		return ['name' => 'bewijs.pdf', 'type' => $type, 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => $size < 0 ? strlen($bytes) : $size];
	}//end upload()

	/**
	 * A file within the rules gets a token that expires in 24 hours.
	 */
	public function testAFileWithinTheRulesGetsAToken(): void {
		$issued = $this->store->issue(formId: 'form-1', property: 'bijlage', rule: ['type' => 'file', 'maxSize' => 1024], file: $this->upload('%PDF-1.7'));

		$this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $issued['token']);
		$this->assertSame($this->now + 86_400, $issued['expiresAt']);
	}//end testAFileWithinTheRulesGetsAToken()

	/**
	 * Spec scenario: an oversized file is refused at upload and no token is issued.
	 */
	public function testAnOversizedFileIsRefused(): void {
		$rule = ['type' => 'file', 'fileConfiguration' => ['maxSize' => 10]];

		try {
			$this->store->issue(formId: 'form-1', property: 'bijlage', rule: $rule, file: $this->upload('x', size: 12 * 1024 * 1024));
			$this->fail('An oversized file was given a token.');
		} catch (FormSubmitRefusedException $refused) {
			$this->assertSame(422, $refused->getStatus());
			$this->assertSame('file-too-large', $refused->getFindings()[0]['code']);
		}

		$this->assertSame([], ($this->appData->folders[FormUploadStore::FOLDER] ?? null)?->files ?? []);
	}//end testAnOversizedFileIsRefused()

	/**
	 * A property without a size rule still has a cap: the endpoint is public.
	 */
	public function testAPropertyWithoutAMaxSizeHasADefaultCap(): void {
		try {
			$this->store->issue(formId: 'form-1', property: 'bijlage', rule: ['type' => 'file'], file: $this->upload('x', size: FormUploadStore::DEFAULT_MAX_BYTES + 1));
			$this->fail('A file over the default cap was given a token.');
		} catch (FormSubmitRefusedException $refused) {
			$this->assertSame('file-too-large', $refused->getFindings()[0]['code']);
		}

		$issued = $this->store->issue(formId: 'form-1', property: 'bijlage', rule: ['type' => 'file'], file: $this->upload('x', size: 1024));
		$this->assertNotSame('', $issued['token']);
	}//end testAPropertyWithoutAMaxSizeHasADefaultCap()

	/**
	 * A type the property does not allow is refused; an items rule of an array property counts.
	 */
	public function testADisallowedTypeIsRefused(): void {
		$this->expectException(FormSubmitRefusedException::class);
		$this->store->issue(
			formId: 'form-1',
			property: 'bijlagen',
			rule: ['type' => 'array', 'items' => ['type' => 'file', 'allowedTypes' => ['image/png']]],
			file: $this->upload('%PDF')
		);
	}//end testADisallowedTypeIsRefused()

	/**
	 * A property that holds no files takes no upload.
	 */
	public function testAPropertyThatHoldsNoFilesTakesNoUpload(): void {
		$this->expectException(FormSubmitRefusedException::class);
		$this->store->issue(formId: 'form-1', property: 'title', rule: ['type' => 'string'], file: $this->upload('x'));
	}//end testAPropertyThatHoldsNoFilesTakesNoUpload()

	/**
	 * A failed PHP upload is refused.
	 */
	public function testAFailedUploadIsRefused(): void {
		$file = $this->upload('x');
		$file['error'] = UPLOAD_ERR_PARTIAL;

		$this->expectException(FormSubmitRefusedException::class);
		$this->store->issue(formId: 'form-1', property: 'bijlage', rule: ['type' => 'file'], file: $file);
	}//end testAFailedUploadIsRefused()

	/**
	 * A token becomes an upload array the save path reads, for its own form only.
	 */
	public function testATokenMaterialisesForItsOwnFormOnly(): void {
		$issued = $this->store->issue(formId: 'form-1', property: 'bijlage', rule: ['type' => 'file'], file: $this->upload('%PDF-1.7 bytes'));

		$file = $this->store->materialise(formId: 'form-1', token: $issued['token']);
		$this->assertSame('bijlage', $file['property']);
		$this->assertSame('bewijs.pdf', $file['name']);
		$this->assertSame('%PDF-1.7 bytes', file_get_contents($file['tmp_name']));
		$this->assertSame(UPLOAD_ERR_OK, $file['error']);

		$this->expectException(FormSubmitRefusedException::class);
		$this->store->materialise(formId: 'form-2', token: $issued['token']);
	}//end testATokenMaterialisesForItsOwnFormOnly()

	/**
	 * A claimed token is gone; an unknown or expired one is refused.
	 */
	public function testAClaimedOrExpiredTokenIsRefused(): void {
		$claimed = $this->store->issue(formId: 'f', property: 'bijlage', rule: ['type' => 'file'], file: $this->upload('a'))['token'];
		$this->store->claim(tokens: [$claimed]);

		$expired = $this->store->issue(formId: 'f', property: 'bijlage', rule: ['type' => 'file'], file: $this->upload('b'))['token'];
		$this->now += 86_401;

		foreach ([$claimed, $expired, 'not-a-token'] as $token) {
			try {
				$this->store->materialise(formId: 'f', token: $token);
				$this->fail('Token ' . $token . ' materialised.');
			} catch (FormSubmitRefusedException $refused) {
				$this->assertSame('upload-token-unknown', $refused->getFindings()[0]['code']);
			}
		}
	}//end testAClaimedOrExpiredTokenIsRefused()

	/**
	 * The purge deletes unclaimed expired tokens, bytes and record, and counts tokens.
	 */
	public function testThePurgeCountsExpiredTokens(): void {
		$this->store->issue(formId: 'f', property: 'bijlage', rule: ['type' => 'file'], file: $this->upload('a'));
		$this->now += 50_000;
		$kept = $this->store->issue(formId: 'f', property: 'bijlage', rule: ['type' => 'file'], file: $this->upload('b'))['token'];
		$this->now += 40_000;

		$this->assertSame(1, $this->store->purge());
		$this->assertCount(2, $this->appData->folders[FormUploadStore::FOLDER]->files);
		$this->assertSame('b', file_get_contents($this->store->materialise(formId: 'f', token: $kept)['tmp_name']));
		$this->assertSame(0, $this->store->purge());
	}//end testThePurgeCountsExpiredTokens()

	/**
	 * The purge deletes the bytes of an upload whose record was never written, once a token's lifetime has passed.
	 *
	 * issue() writes the bytes first, so a failed second write leaves a `.bin` no record points
	 * at. A young one may still be an upload in flight and stays. Goes red without the `.bin` pass.
	 */
	public function testThePurgeDeletesAnOrphanedBytesFile(): void {
		$this->store->issue(formId: 'f', property: 'bijlage', rule: ['type' => 'file'], file: $this->upload('a'));
		$folder = $this->appData->folders[FormUploadStore::FOLDER];
		$folder->newFile('0123456789abcdef0123456789abcdef.bin', 'orphan');
		$folder->newFile('fedcba9876543210fedcba9876543210.bin', 'in flight');
		$folder->getFile('0123456789abcdef0123456789abcdef.bin')->mtime = ($this->now - FormUploadStore::TTL);
		$folder->getFile('fedcba9876543210fedcba9876543210.bin')->mtime = ($this->now - 60);

		$this->assertSame(1, $this->store->purge());
		$this->assertFalse($folder->fileExists('0123456789abcdef0123456789abcdef.bin'), 'The orphan is gone.');
		$this->assertTrue($folder->fileExists('fedcba9876543210fedcba9876543210.bin'), 'A young bytes file may still be in flight.');
		$this->assertCount(3, $folder->files, 'The live token (bytes and record) and the young file stay.');
	}//end testThePurgeDeletesAnOrphanedBytesFile()

	/**
	 * A token claimed for a draft is satisfied for that draft, that property and that form only, until it expires, and the purge clears the record.
	 */
	public function testAClaimedTokenIsSatisfiedForItsDraftOnly(): void {
		$token = $this->store->issue(formId: 'form-1', property: 'bijlage', rule: ['type' => 'file', 'maxSize' => 1024], file: $this->upload('%PDF-1.7'))['token'];
		$this->store->claim(tokens: [$token], draftId: 'draft-1');

		$this->assertNull($this->store->redeem(formId: 'form-1', property: 'bijlage', token: $token, draftId: 'draft-1'));
		foreach ([['form-1', 'bijlage', 'draft-2'], ['form-1', 'other', 'draft-1'], ['form-2', 'bijlage', 'draft-1'], ['form-1', 'bijlage', null]] as [$form, $property, $draft]) {
			try {
				$this->store->redeem(formId: $form, property: $property, token: $token, draftId: $draft);
				$this->fail('A claimed token was redeemed by the wrong asker.');
			} catch (FormSubmitRefusedException $refused) {
				$this->assertSame(422, $refused->getStatus());
			}
		}

		$this->now += 86_401;
		$this->assertSame(1, $this->store->purge(), 'The expired record is removed.');
		$this->assertSame(0, $this->store->purge());
	}//end testAClaimedTokenIsSatisfiedForItsDraftOnly()

	/**
	 * A final submit (no draft) leaves no record behind.
	 */
	public function testAFinalClaimLeavesNoRecord(): void {
		$token = $this->store->issue(formId: 'form-1', property: 'bijlage', rule: ['type' => 'file', 'maxSize' => 1024], file: $this->upload('%PDF-1.7'))['token'];
		$this->store->claim(tokens: [$token]);

		$this->expectException(FormSubmitRefusedException::class);
		$this->store->redeem(formId: 'form-1', property: 'bijlage', token: $token, draftId: 'draft-1');
	}//end testAFinalClaimLeavesNoRecord()

	/**
	 * An unissued token, one for another property, and a live one are told apart; the plans' tokens are claimed together.
	 */
	public function testRedeemRefusesAnotherPropertyAndClaimPlannedClaimsEveryToken(): void {
		$one = $this->store->issue(formId: 'form-1', property: 'bijlage', rule: ['type' => 'file', 'maxSize' => 1024], file: $this->upload('a'))['token'];
		$two = $this->store->issue(formId: 'form-1', property: 'bijlage', rule: ['type' => 'file', 'maxSize' => 1024], file: $this->upload('b'))['token'];

		$this->assertSame('bewijs.pdf', $this->store->redeem(formId: 'form-1', property: 'bijlage', token: $one)['name']);
		try {
			$this->store->redeem(formId: 'form-1', property: 'andere', token: $one);
			$this->fail('A token for another property was redeemed.');
		} catch (FormSubmitRefusedException $refused) {
			$this->assertSame('upload-token-unknown', $refused->getFindings()[0]['code']);
		}

		$this->store->claimPlanned(plans: [['tokens' => [$one]], ['tokens' => [$two]]], draftId: 'draft-1');
		$this->assertNull($this->store->redeem(formId: 'form-1', property: 'bijlage', token: $one, draftId: 'draft-1'));
		$this->assertNull($this->store->redeem(formId: 'form-1', property: 'bijlage', token: $two, draftId: 'draft-1'));
		$this->store->claimPlanned(plans: []);
	}//end testRedeemRefusesAnotherPropertyAndClaimPlannedClaimsEveryToken()
}//end class
