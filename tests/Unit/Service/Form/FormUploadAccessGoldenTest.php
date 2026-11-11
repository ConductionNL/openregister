<?php

/**
 * Golden test: who may redeem an upload token, reuse an Idempotency-Key, or create through a form.
 *
 * Pins the allowed and the refused outcome of every condition the upload
 * store, the claim records, the idempotency store and the destination's
 * create grant decide on, so a refactor of those units cannot widen or
 * narrow what a resident's request reaches.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-upload-tokens-must-hold-bytes-only-and-expire
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Form;

use OCA\OpenRegister\Service\Form\FormDestination;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\FormSubmitRefusedException;
use OCA\OpenRegister\Service\File\FilePropertyRules;
use OCA\OpenRegister\Service\Form\FormDestinationValidator;
use OCA\OpenRegister\Service\Form\FormFieldRules;
use OCA\OpenRegister\Service\Form\FormFileStorage;
use OCA\OpenRegister\Service\Form\FormIdempotencyStore;
use OCA\OpenRegister\Service\Form\FormUploadClaim;
use OCA\OpenRegister\Service\Form\FormUploadClaims;
use OCA\OpenRegister\Service\Form\FormUploadStore;
use OCA\OpenRegister\Service\Form\IdempotentRequest;
use OCA\OpenRegister\Tests\Unit\Service\Form\Fakes\MemoryAppData;
use OCA\OpenRegister\Tests\Unit\Service\Form\Fakes\MemoryLockingProvider;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use OCP\ITempManager;
use PHPUnit\Framework\TestCase;

/**
 * Allowed and refused, for each condition.
 */
class FormUploadAccessGoldenTest extends TestCase {

	private int $now = 1_760_000_000;

	private MemoryAppData $appData;

	private ITimeFactory $time;

	private FormUploadStore $store;

	private FormIdempotencyStore $keys;

	/**
	 * @var array<int, string>
	 */
	private array $temporary = [];

	protected function setUp(): void {
		$this->appData = new MemoryAppData();
		$this->time = $this->createMock(ITimeFactory::class);
		$this->time->method('getTime')->willReturnCallback(fn (): int => $this->now);
		$temp = $this->createMock(ITempManager::class);
		$temp->method('getTemporaryFile')->willReturnCallback(
			function (): string {
				$path = (string)tempnam(sys_get_temp_dir(), 'or-golden');
				$this->temporary[] = $path;

				return $path;
			}
		);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$this->store = new FormUploadStore(storage: new FormFileStorage(appData: $this->appData, time: $this->time), temp: $temp, rules: new FilePropertyRules(), l10n: $l10n);
		$this->keys = new FormIdempotencyStore(storage: new FormFileStorage(appData: $this->appData, time: $this->time), locks: new MemoryLockingProvider(), l10n: $l10n);
	}//end setUp()

	protected function tearDown(): void {
		foreach ($this->temporary as $path) {
			if (is_file($path) === true) {
				unlink($path);
			}
		}
	}//end tearDown()

	/**
	 * An upload array for bytes.
	 *
	 * @return array<string, mixed>
	 */
	private function upload(string $bytes, string $type = 'application/pdf', ?int $size = null, int $error = UPLOAD_ERR_OK): array {
		$path = (string)tempnam(sys_get_temp_dir(), 'or-golden-up');
		file_put_contents($path, $bytes);
		$this->temporary[] = $path;

		return ['name' => '../x/bewijs.pdf', 'type' => $type, 'tmp_name' => $path, 'error' => $error, 'size' => ($size ?? strlen($bytes))];
	}//end upload()

	/**
	 * The refusal code a call throws, or 'allowed'.
	 */
	private function outcome(callable $call): string {
		try {
			$call();
		} catch (FormSubmitRefusedException $refused) {
			return $refused->getStatus() . ':' . ($refused->getFindings()[0]['code'] ?? '');
		}

		return 'allowed';
	}//end outcome()

	public function testWhatAnUploadIsRefusedFor(): void {
		$file = ['type' => 'file', 'maxSize' => 1024, 'allowedTypes' => ['application/pdf']];
		$cases = [
			'allowed' => fn () => $this->store->issue(formId: 'f', property: 'p', rule: $file, file: $this->upload('%PDF')),
			'not a file' => fn () => $this->store->issue(formId: 'f', property: 'p', rule: ['type' => 'string'], file: $this->upload('%PDF')),
			'failed' => fn () => $this->store->issue(formId: 'f', property: 'p', rule: $file, file: $this->upload('%PDF', error: UPLOAD_ERR_PARTIAL)),
			'too large' => fn () => $this->store->issue(formId: 'f', property: 'p', rule: $file, file: $this->upload('%PDF', size: 1025)),
			'at the limit' => fn () => $this->store->issue(formId: 'f', property: 'p', rule: $file, file: $this->upload('%PDF', size: 1024)),
			'default cap' => fn () => $this->store->issue(formId: 'f', property: 'p', rule: ['type' => 'file'], file: $this->upload('%PDF', size: FormUploadStore::DEFAULT_MAX_BYTES + 1)),
			'within default cap' => fn () => $this->store->issue(formId: 'f', property: 'p', rule: ['type' => 'file'], file: $this->upload('%PDF', size: FormUploadStore::DEFAULT_MAX_BYTES)),
			'wrong type' => fn () => $this->store->issue(formId: 'f', property: 'p', rule: $file, file: $this->upload('%PDF', type: 'text/html')),
		];
		$outcomes = array_map(fn (callable $call): string => $this->outcome(call: $call), $cases);

		$this->assertSame(
			[
				'allowed' => 'allowed',
				'not a file' => '422:property-holds-no-files',
				'failed' => '422:upload-failed',
				'too large' => '422:file-too-large',
				'at the limit' => 'allowed',
				'default cap' => '422:file-too-large',
				'within default cap' => 'allowed',
				'wrong type' => '422:file-type-not-allowed',
			],
			$outcomes
		);
	}//end testWhatAnUploadIsRefusedFor()

	public function testWhoMayRedeemAToken(): void {
		$token = $this->store->issue(formId: 'f', property: 'p', rule: ['type' => 'file'], file: $this->upload('%PDF'))['token'];
		$file = $this->store->redeem(claim: new FormUploadClaim(formId: 'f', property: 'p', draftId: null), token: $token);

		$this->assertSame(['name' => 'bewijs.pdf', 'type' => 'application/pdf', 'error' => UPLOAD_ERR_OK, 'size' => 4], array_diff_key($file, ['tmp_name' => true]));
		$this->assertSame('%PDF', file_get_contents($file['tmp_name']));
		$this->assertSame('422:upload-token-unknown', $this->outcome(fn () => $this->store->redeem(claim: new FormUploadClaim(formId: 'other', property: 'p', draftId: null), token: $token)));
		$this->assertSame('422:upload-token-unknown', $this->outcome(fn () => $this->store->redeem(claim: new FormUploadClaim(formId: 'f', property: 'q', draftId: null), token: $token)));
		$this->assertSame('422:upload-token-unknown', $this->outcome(fn () => $this->store->redeem(claim: new FormUploadClaim(formId: 'f', property: 'p', draftId: null), token: 'not-a-token')));
		$this->assertSame('422:upload-token-unknown', $this->outcome(fn () => $this->store->redeem(claim: new FormUploadClaim(formId: 'f', property: 'p', draftId: null), token: str_repeat('a', 32))));
		$this->now += FormUploadStore::TTL;
		$this->assertSame('422:upload-token-unknown', $this->outcome(fn () => $this->store->redeem(claim: new FormUploadClaim(formId: 'f', property: 'p', draftId: null), token: $token)));
	}//end testWhoMayRedeemAToken()

	public function testWhoAClaimRecordSatisfies(): void {
		$token = $this->store->issue(formId: 'f', property: 'p', rule: ['type' => 'file'], file: $this->upload('%PDF'))['token'];
		$this->store->claim(tokens: [$token], draftId: 'd');
		$folder = $this->appData->getFolder('form-uploads');
		$claims = new FormUploadClaims(time: $this->time, folder: $folder);

		$this->assertTrue($claims->isClaimedBy(token: $token, claim: new FormUploadClaim(formId: 'f', property: 'p', draftId: 'd')));
		$this->assertFalse($claims->isClaimedBy(token: $token, claim: new FormUploadClaim(formId: 'g', property: 'p', draftId: 'd')));
		$this->assertFalse($claims->isClaimedBy(token: $token, claim: new FormUploadClaim(formId: 'f', property: 'q', draftId: 'd')));
		$this->assertFalse($claims->isClaimedBy(token: $token, claim: new FormUploadClaim(formId: 'f', property: 'p', draftId: 'e')));
		$this->assertFalse($claims->isClaimedBy(token: $token, claim: new FormUploadClaim(formId: 'f', property: 'p', draftId: null)));
		$this->assertFalse((new FormUploadClaims(time: $this->time))->isClaimedBy(token: $token, claim: new FormUploadClaim(formId: 'f', property: 'p', draftId: 'd')));
		$this->assertFalse($claims->isClaimedBy(token: strtoupper($token), claim: new FormUploadClaim(formId: 'f', property: 'p', draftId: 'd')));
		$this->assertFalse($claims->isClaimedBy(token: str_repeat('b', 32), claim: new FormUploadClaim(formId: 'f', property: 'p', draftId: 'd')));
		$folder->newFile(str_repeat('c', 32) . FormUploadClaims::SUFFIX, 'not json');
		$this->assertFalse($claims->isClaimedBy(token: str_repeat('c', 32), claim: new FormUploadClaim(formId: 'f', property: 'p', draftId: 'd')));
		$this->now += FormUploadStore::TTL - 1;
		$this->assertTrue($claims->isClaimedBy(token: $token, claim: new FormUploadClaim(formId: 'f', property: 'p', draftId: 'd')));
		$this->now += 1;
		$this->assertFalse($claims->isClaimedBy(token: $token, claim: new FormUploadClaim(formId: 'f', property: 'p', draftId: 'd')));
	}//end testWhoAClaimRecordSatisfies()

	public function testWhoGetsARememberedAnswer(): void {
		$binding = $this->keys->bindingFor(subject: 'user:alice', payload: ['a' => 1]);
		$this->assertNull($this->keys->answerFor(new IdempotentRequest(scope: 'f', key: 'k', binding: $binding)));
		$this->keys->keep(new IdempotentRequest(scope: 'f', key: 'k', binding: $binding), ['id' => 'x']);

		$this->assertSame(['id' => 'x'], $this->keys->answerFor(new IdempotentRequest(scope: 'f', key: 'k', binding: $binding)));
		$this->assertNull($this->keys->answerFor(new IdempotentRequest(scope: 'g', key: 'k', binding: $binding)));
		$other = $this->keys->bindingFor(subject: 'user:bob', payload: ['a' => 1]);
		$this->assertSame('409:', $this->outcome(fn () => $this->keys->answerFor(new IdempotentRequest(scope: 'f', key: 'k', binding: $other))));
		$changed = $this->keys->bindingFor(subject: 'user:alice', payload: ['a' => 2]);
		$this->assertSame('409:', $this->outcome(fn () => $this->keys->answerFor(new IdempotentRequest(scope: 'f', key: 'k', binding: $changed))));
		$this->now += 86_400;
		$this->assertNull($this->keys->answerFor(new IdempotentRequest(scope: 'f', key: 'k', binding: $binding)));
		$this->assertNull($this->keys->answerFor(new IdempotentRequest(scope: 'f', key: 'k', binding: $other)));
	}//end testWhoGetsARememberedAnswer()

	public function testWhichCreateGrantOpensADestinationToAnAudience(): void {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$validator = new FormDestinationValidator(l10n: $l10n, rules: new FormFieldRules());
		$open = function (array $authorization, string $audience) use ($validator): bool {
			$schema = new Schema();
			$schema->setSlug('case');
			$schema->setProperties([]);
			$schema->setRequired([]);
			$schema->setAuthorization($authorization);

			return $validator->validate(mapping: ['fields' => []], destination: new FormDestination(schema: $schema, audience: $audience)) === [];
		};

		$this->assertTrue($open([], 'public'));
		$this->assertTrue($open(['create' => ['public']], 'public'));
		$this->assertTrue($open(['create' => [['group' => 'public']]], 'public'));
		$this->assertFalse($open(['create' => ['authenticated']], 'public'));
		$this->assertTrue($open(['create' => ['authenticated']], 'authenticated'));
		$this->assertTrue($open(['create' => ['public']], 'authenticated'));
		$this->assertFalse($open(['create' => ['admin', ['group' => 'staff'], ['group' => ['public']], 7]], 'authenticated'));
		$this->assertFalse($open(['read' => ['public']], 'public'));
		$this->assertTrue($open(['read' => ['admin']], ''));
	}//end testWhichCreateGrantOpensADestinationToAnAudience()
}//end class
