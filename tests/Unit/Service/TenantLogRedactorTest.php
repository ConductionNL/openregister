<?php

/**
 * TenantLogRedactorTest — the token never reaches the log, and the line that
 * cannot be cleared never gets written.
 *
 * The drop path is the one worth the most care, because it is the one that
 * fails silently in the wrong direction. A redactor that fails OPEN writes the
 * secret and reports success, and nothing downstream can tell that apart from a
 * clean line. So every refusal case below asserts BOTH halves: nothing was
 * emitted, and the counter moved.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/several-legal-entities-in-one-instance/specs/tenant-isolation-audit/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Service\TenantLogRedactor;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\TenantLogRedactor
 */
class TenantLogRedactorTest extends TestCase {

	/**
	 * The in-memory app config the redactor reads and writes.
	 *
	 * @var array<string, mixed>
	 */
	private array $config = [];

	/**
	 * Build a redactor over an app config that actually stores what it is given.
	 *
	 * A mock that swallowed the counter write would make the drop assertions
	 * pass whether or not the counter ever moved, which is precisely the
	 * property those assertions exist to check.
	 *
	 * @return TenantLogRedactor The redactor under test.
	 */
	private function redactor(): TenantLogRedactor {
		$appConfig = $this->createMock(IAppConfig::class);

		$appConfig->method('getValueInt')->willReturnCallback(
			function (string $app, string $key, int $default = 0): int {
				return (int)($this->config[$key] ?? $default);
			}
		);
		$appConfig->method('setValueInt')->willReturnCallback(
			function (string $app, string $key, int $value): bool {
				$this->config[$key] = $value;
				return true;
			}
		);
		$appConfig->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = ''): string {
				return (string)($this->config[$key] ?? $default);
			}
		);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->config[$key] = $value;
				return true;
			}
		);

		return new TenantLogRedactor(appConfig: $appConfig);

	}//end redactor()

	public function testATokenNamedByItsKeyIsRedacted(): void {
		$line = $this->redactor()->line(
			context: [
				'organisation' => 'org-a',
				'token' => 'abc123-this-is-the-secret',
				'refreshToken' => 'def456',
				'Authorization' => 'Bearer zzz',
			]
		);

		$this->assertNotNull($line);
		$serialised = json_encode($line);
		$this->assertStringNotContainsString('abc123-this-is-the-secret', $serialised);
		$this->assertStringNotContainsString('def456', $serialised);
		$this->assertStringNotContainsString('zzz', $serialised);
		$this->assertSame('org-a', $line['organisation']);

	}//end testATokenNamedByItsKeyIsRedacted()

	public function testABearerTokenPastedIntoAMessageIsRedacted(): void {
		$line = $this->redactor()->line(
			context: [
				'organisation' => 'org-a',
				'message' => 'request failed with Authorization: Bearer sk-live-4f8a9c2d1e7b',
			]
		);

		$this->assertNotNull($line);
		$this->assertStringNotContainsString('sk-live-4f8a9c2d1e7b', $line['message']);
		$this->assertStringContainsString(TenantLogRedactor::REDACTED, $line['message']);
		$this->assertStringContainsString('request failed', $line['message']);

	}//end testABearerTokenPastedIntoAMessageIsRedacted()

	public function testAJwtIsRedactedWhateverTheKeyIsCalled(): void {
		$jwt = 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.dBjftJeZ4CVPmB92K27uhbUJU1p1r_wW1gFWFOEjXk';

		$line = $this->redactor()->line(context: ['detail' => 'session ' . $jwt . ' expired']);

		$this->assertNotNull($line);
		$this->assertStringNotContainsString($jwt, $line['detail']);

	}//end testAJwtIsRedactedWhateverTheKeyIsCalled()

	public function testACredentialInAUrlIsRedacted(): void {
		$line = $this->redactor()->line(context: ['url' => 'https://svc:hunter2@example.org/api']);

		$this->assertNotNull($line);
		$this->assertStringNotContainsString('hunter2', $line['url']);

	}//end testACredentialInAUrlIsRedacted()

	public function testASecretNestedInsideTheContextIsRedacted(): void {
		$line = $this->redactor()->line(
			context: [
				'organisation' => 'org-a',
				'request' => ['headers' => ['authorization' => 'Bearer nested-secret']],
			]
		);

		$this->assertNotNull($line);
		$this->assertStringNotContainsString('nested-secret', json_encode($line));

	}//end testASecretNestedInsideTheContextIsRedacted()

	public function testAnUnreadableValueDropsTheLineAndMovesTheCounter(): void {
		$redactor = $this->redactor();
		$before = $redactor->droppedLines();

		$handle = fopen('php://memory', 'rb');
		$line = $redactor->line(context: ['organisation' => 'org-a', 'stream' => $handle]);
		fclose($handle);

		$this->assertNull($line, 'A payload the redactor cannot read must not be written.');
		$this->assertSame(($before + 1), $redactor->droppedLines());

	}//end testAnUnreadableValueDropsTheLineAndMovesTheCounter()

	public function testAContextNestedDeeperThanTheRedactorReadsIsDropped(): void {
		$redactor = $this->redactor();
		$before = $redactor->droppedLines();

		$deep = 'leaf';
		for ($i = 0; $i < 12; $i++) {
			$deep = ['down' => $deep];
		}

		$this->assertNull($redactor->line(context: ['payload' => $deep]));
		$this->assertSame(($before + 1), $redactor->droppedLines());

	}//end testAContextNestedDeeperThanTheRedactorReadsIsDropped()

	public function testABrokenEncodingDropsTheLine(): void {
		$redactor = $this->redactor();
		$before = $redactor->droppedLines();

		// Invalid UTF-8. A pattern cannot be matched reliably against it, so
		// the redactor cannot establish that it is clean.
		$this->assertNull($redactor->line(context: ['blob' => "\xC3\x28 raw"]));
		$this->assertSame(($before + 1), $redactor->droppedLines());

	}//end testABrokenEncodingDropsTheLine()

	public function testACleanLineDoesNotMoveTheDropCounter(): void {
		$redactor = $this->redactor();
		$before = $redactor->droppedLines();

		$this->assertNotNull($redactor->line(context: ['organisation' => 'org-a', 'entityId' => 7]));
		$this->assertSame($before, $redactor->droppedLines(), 'A clean line must not be counted as a drop.');

	}//end testACleanLineDoesNotMoveTheDropCounter()

	public function testThePseudonymIsStableOneWayAndDistinctPerUser(): void {
		$redactor = $this->redactor();

		$first = $redactor->pseudonym(userId: 'jan');
		$second = $redactor->pseudonym(userId: 'jan');
		$other = $redactor->pseudonym(userId: 'fatima');

		$this->assertSame($first, $second, 'One person must read as one reference, or a log cannot be followed.');
		$this->assertNotSame($first, $other);
		$this->assertStringNotContainsString('jan', $first);
		$this->assertStringStartsWith('actor:', $first);

	}//end testThePseudonymIsStableOneWayAndDistinctPerUser()

	public function testAnAnonymousActorIsNamedAsSuchRatherThanHashedToNothing(): void {
		$redactor = $this->redactor();

		$this->assertSame('actor:anonymous', $redactor->pseudonym(userId: null));
		$this->assertSame('actor:anonymous', $redactor->pseudonym(userId: ''));

	}//end testAnAnonymousActorIsNamedAsSuchRatherThanHashedToNothing()

	public function testRedactReturnsAnExplicitRefusalMarkerRatherThanAnEmptyContext(): void {
		$redactor = $this->redactor();

		$handle = fopen('php://memory', 'rb');
		$result = $redactor->redact(context: ['stream' => $handle]);
		fclose($handle);

		// An empty array would read downstream as "there was nothing to say".
		// The marker says "there was something, and it could not be cleared",
		// which is a different fact and the one an auditor needs.
		$this->assertSame(['redactionFailed' => true], $result);

	}//end testRedactReturnsAnExplicitRefusalMarkerRatherThanAnEmptyContext()

	public function testTheRedactorWorksWithoutAnAppConfig(): void {
		// A mapper that has no app config still gets the refusal, just not the
		// persisted counter. The refusal is the safety property; the counter is
		// the record of it.
		$redactor = new TenantLogRedactor();

		$handle = fopen('php://memory', 'rb');
		$this->assertNull($redactor->line(context: ['stream' => $handle]));
		fclose($handle);

		$this->assertNotNull($redactor->line(context: ['organisation' => 'org-a']));
		$this->assertStringStartsWith('actor:', $redactor->pseudonym(userId: 'jan'));

	}//end testTheRedactorWorksWithoutAnAppConfig()
}//end class
