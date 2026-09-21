<?php

/**
 * Unit tests for naming the token behind a write on the audit row.
 *
 * Covers REQ-ATS-002 on both halves: the entry of a write made with a token
 * names the token, its owner and its consumer, and no request or response
 * payload is stored. The second half is a prohibition, so the test for it
 * asserts an ABSENCE that would survive somebody adding a body here later.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Audit
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/enhanced-audit-trail/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Audit;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Service\Audit\TokenAttribution;
use OCA\OpenRegister\Service\Audit\TokenContext;
use OCA\OpenRegister\Service\Audit\TokenIdentity;
use OCA\OpenRegister\Service\Audit\TokenResolver;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

final class TokenAttributionTest extends TestCase {
	private function context(?TokenIdentity $identity): TokenContext {
		$resolver = $this->createMock(TokenResolver::class);
		$resolver->method('resolve')->willReturn(null);

		$context = new TokenContext($resolver);
		$context->claim($identity);

		return $context;
	}//end context()

	private function attribution(?TokenContext $context): TokenAttribution {
		$container = $this->createMock(ContainerInterface::class);
		if ($context === null) {
			$container->method('get')->willThrowException(new \RuntimeException('not registered'));

			return new TokenAttribution($container);
		}

		$container->method('get')->willReturn($context);

		return new TokenAttribution($container);
	}//end attribution()

	private function supplierToken(): TokenIdentity {
		return new TokenIdentity(
			'app-password',
			'4711',
			'Leverancier koppeling',
			'svc-leverancier',
			'Koppeling leverancier',
			'consumer-uuid',
			'Leverancier BV'
		);
	}//end supplierToken()

	public function testTheEntryNamesTheTokenItsOwnerAndItsConsumer(): void {
		$entry = new AuditTrail();
		$this->attribution($this->context($this->supplierToken()))->apply($entry);

		$sealed = ($entry->getResultSummary() ?? [])['token'] ?? null;

		self::assertIsArray($sealed, 'the token belongs inside the canonical JSON, not beside it');
		self::assertSame('4711', $sealed['reference'], 'which credential');
		self::assertSame('Leverancier koppeling', $sealed['name'], 'what it is called');
		self::assertSame('svc-leverancier', $sealed['ownerUid'], 'who owns it');
		self::assertSame('Leverancier BV', $sealed['consumer'], 'which integration it belongs to');

		// The indexed projection, which is what "everything this koppeling
		// wrote last month" filters on.
		self::assertSame('Leverancier BV', $entry->getConsumer());
	}//end testTheEntryNamesTheTokenItsOwnerAndItsConsumer()

	public function testTheBeforeAndAfterSurviveTheAttribution(): void {
		// D-4: the before and after is what replaces the payload copy, so an
		// attribution that overwrote it would remove the only answer left.
		$entry = new AuditTrail();
		$entry->setChanged(['straatnaam' => ['old' => 'Kerkstraat', 'new' => 'Dorpsstraat']]);

		$this->attribution($this->context($this->supplierToken()))->apply($entry);

		self::assertSame(
			['straatnaam' => ['old' => 'Kerkstraat', 'new' => 'Dorpsstraat']],
			$entry->getChanged()
		);
	}//end testTheBeforeAndAfterSurviveTheAttribution()

	public function testNoRequestOrResponseBodyIsStored(): void {
		// The prohibition. A call carrying a body produces a row on which no
		// payload key exists anywhere, at any depth.
		$entry = new AuditTrail();
		$this->attribution($this->context($this->supplierToken()))->apply($entry);

		self::assertFalse(TokenAttribution::carriesPayload($entry));

		$summary = ($entry->getResultSummary() ?? []);
		$flattened = json_encode($summary);
		foreach (TokenAttribution::PAYLOAD_KEYS as $forbidden) {
			self::assertStringNotContainsString(
				'"' . $forbidden . '"',
				(string)$flattened,
				'the attribution wrote a payload key: ' . $forbidden
			);
		}
	}//end testNoRequestOrResponseBodyIsStored()

	public function testThePayloadCheckFindsOneNestedTwoLevelsDown(): void {
		// The control for the test above. Without this, an assertion that the
		// summary holds no payload would pass just as happily against a checker
		// that can never find one.
		$entry = new AuditTrail();
		$entry->setResultSummary(['tool' => ['invocation' => ['requestBody' => '{"bsn":"123456789"}']]]);

		self::assertTrue(TokenAttribution::carriesPayload($entry));
	}//end testThePayloadCheckFindsOneNestedTwoLevelsDown()

	public function testAnInteractiveSessionNamesNoToken(): void {
		// A browser click must not claim a token made the write. The presence
		// of the field is the signal that a machine did.
		$entry = new AuditTrail();
		$this->attribution($this->context(null))->apply($entry);

		self::assertNull($entry->getConsumer());
		self::assertArrayNotHasKey('token', ($entry->getResultSummary() ?? []));
	}//end testAnInteractiveSessionNamesNoToken()

	public function testAMechanismWithNothingBehindItIsNotAttributed(): void {
		$entry = new AuditTrail();
		$this->attribution($this->context(new TokenIdentity('app-password')))->apply($entry);

		self::assertNull($entry->getConsumer());
		self::assertArrayNotHasKey('token', ($entry->getResultSummary() ?? []));
	}//end testAMechanismWithNothingBehindItIsNotAttributed()

	public function testAnUnregisteredContextLeavesTheRowIntactRatherThanThrowing(): void {
		// Fail-soft: an audit row is evidence and must survive a bookkeeping
		// problem. A throw here would stop the save it is describing.
		$entry = new AuditTrail();
		$entry->setResultSummary(['purpose' => ['code' => 'brp-adresonderzoek']]);

		$this->attribution(null)->apply($entry);

		self::assertSame(['purpose' => ['code' => 'brp-adresonderzoek']], $entry->getResultSummary());
	}//end testAnUnregisteredContextLeavesTheRowIntactRatherThanThrowing()

	public function testThePurposeAlreadyOnTheRowIsNotErased(): void {
		$entry = new AuditTrail();
		$entry->setResultSummary(['purpose' => ['code' => 'brp-adresonderzoek']]);

		$this->attribution($this->context($this->supplierToken()))->apply($entry);

		$summary = ($entry->getResultSummary() ?? []);
		self::assertSame('brp-adresonderzoek', $summary['purpose']['code']);
		self::assertSame('Leverancier BV', $summary['token']['consumer']);
	}//end testThePurposeAlreadyOnTheRowIsNotErased()

	public function testAnEditedConsumerColumnDisagreesWithTheSealedCopy(): void {
		// The column is outside the hash, so it can be edited without breaking
		// verification. This is what makes such an edit visible.
		$entry = new AuditTrail();
		$this->attribution($this->context($this->supplierToken()))->apply($entry);

		self::assertFalse(TokenAttribution::disagrees($entry));

		$entry->setConsumer('Iemand anders BV');

		self::assertTrue(TokenAttribution::disagrees($entry));
	}//end testAnEditedConsumerColumnDisagreesWithTheSealedCopy()

	public function testTheConsumerColumnStaysOutsideTheCanonicalJson(): void {
		// The tripwire on ADR-003 Rule 4. A key added to jsonSerialize() changes
		// the canonical form of every row ever written and invalidates the whole
		// chain, so this asserts the column is NOT in it.
		$entry = new AuditTrail();
		$this->attribution($this->context($this->supplierToken()))->apply($entry);

		self::assertArrayNotHasKey('consumer', $entry->jsonSerialize());
	}//end testTheConsumerColumnStaysOutsideTheCanonicalJson()
}//end class
