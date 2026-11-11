<?php

/**
 * Golden characterisation tests for the purpose and token attribution of an audit row.
 *
 * Pins, on the code before the two attributions shared their context lookup and
 * their summary merge: a row is stamped only when the request-scoped context
 * resolves, is of the right class and has something to attribute (allowed), and is
 * left exactly as it was otherwise (refused: an unregistered or wrong-typed context,
 * no accepted purpose, an empty purpose code, a throwing or unattributable token).
 * The merge keeps every other summary key — integer keys included — in place.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Audit
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/enhanced-audit-trail/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Audit;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\ProcessingPurpose;
use OCA\OpenRegister\Db\Verwerkingsactiviteit;
use OCA\OpenRegister\Service\Audit\NamedIdentifier;
use OCA\OpenRegister\Service\Audit\PurposeAttribution;
use OCA\OpenRegister\Service\Audit\PurposeContext;
use OCA\OpenRegister\Service\Audit\TokenAttribution;
use OCA\OpenRegister\Service\Audit\TokenContext;
use OCA\OpenRegister\Service\Audit\TokenIdentity;
use OCA\OpenRegister\Service\Audit\TokenResolver;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

final class AuditAttributionGoldenTest extends TestCase {

	private const SUMMARY = [0 => 'first', 'tool' => ['name' => 'x'], 'purpose' => 'old', 1 => 'second', 'token' => 'old'];

	private function container(mixed $context): ContainerInterface {
		$container = $this->createMock(ContainerInterface::class);
		if ($context instanceof \Throwable) {
			$container->method('get')->willThrowException($context);
			return $container;
		}

		$container->method('get')->willReturn($context);

		return $container;
	}

	private function row(): AuditTrail {
		$row = new AuditTrail();
		$row->setResultSummary(self::SUMMARY);
		$row->setProcessingActivityId(null);

		return $row;
	}

	private function purposeContext(?string $code, bool $withActivity = true): PurposeContext {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn('');
		$request->method('getParam')->willReturn(null);
		$context = new PurposeContext($request);

		$purpose = new ProcessingPurpose();
		$purpose->setCode($code);
		$activity = null;
		if ($withActivity === true) {
			$activity = new Verwerkingsactiviteit();
			$activity->setUuid('activity-uuid');
			$activity->setCode('VA-01');
		}

		$context->accept($purpose, $activity);

		return $context;
	}

	private function tokenContext(?TokenIdentity $identity, bool $throws = false): TokenContext {
		$resolver = $this->createMock(TokenResolver::class);
		if ($throws === true) {
			$resolver->method('resolve')->willThrowException(new RuntimeException('resolver down'));
			return new TokenContext($resolver);
		}

		$resolver->method('resolve')->willReturn(null);
		$context = new TokenContext($resolver);
		$context->claim($identity);

		return $context;
	}

	private function token(): TokenIdentity {
		return new TokenIdentity(
			'app-password',
			new NamedIdentifier('4711', 'Leverancier koppeling'),
			new NamedIdentifier('svc-leverancier', 'Koppeling leverancier'),
			new NamedIdentifier('consumer-uuid', 'Leverancier BV')
		);
	}

	public function testThePurposeIsStampedAndMergedInPlace(): void {
		$row = $this->row();
		(new PurposeAttribution($this->container($this->purposeContext('brp-adresonderzoek'))))->apply($row);

		$this->assertSame('brp-adresonderzoek', $row->getPurpose());
		$this->assertSame('activity-uuid', $row->getProcessingActivityId());
		$this->assertSame(
			[
				0 => 'first',
				'tool' => ['name' => 'x'],
				'purpose' => ['code' => 'brp-adresonderzoek', 'activity' => 'VA-01', 'activityUuid' => 'activity-uuid'],
				1 => 'second',
				'token' => 'old',
			],
			$row->getResultSummary()
		);

		$fresh = new AuditTrail();
		(new PurposeAttribution($this->container($this->purposeContext('code', false))))->apply($fresh);
		$this->assertSame(['purpose' => ['code' => 'code', 'activity' => null, 'activityUuid' => null]], $fresh->getResultSummary());
		$this->assertNull($fresh->getProcessingActivityId());
	}

	public function testThePurposeIsRefusedLeavingTheRowAsItWas(): void {
		$cases = [
			'unregistered' => new RuntimeException('not registered'),
			'wrong type' => new \stdClass(),
			'empty code' => $this->purposeContext(''),
			'null code' => $this->purposeContext(null),
		];
		foreach ($cases as $name => $context) {
			$row = $this->row();
			(new PurposeAttribution($this->container($context)))->apply($row);

			$this->assertNull($row->getPurpose(), $name);
			$this->assertNull($row->getProcessingActivityId(), $name);
			$this->assertSame(self::SUMMARY, $row->getResultSummary(), $name);
		}
	}

	public function testTheTokenIsStampedAndMergedInPlace(): void {
		$row = $this->row();
		(new TokenAttribution($this->container($this->tokenContext($this->token()))))->apply($row);

		$this->assertSame('Leverancier BV', $row->getConsumer());
		$this->assertSame(
			[0 => 'first', 'tool' => ['name' => 'x'], 'purpose' => 'old', 1 => 'second', 'token' => $this->token()->toArray()],
			$row->getResultSummary()
		);
	}

	public function testTheTokenIsRefusedLeavingTheRowAsItWas(): void {
		$cases = [
			'unregistered' => new RuntimeException('not registered'),
			'wrong type' => new \stdClass(),
			'no identity' => $this->tokenContext(null),
			'unattributable' => $this->tokenContext(new TokenIdentity('app-password')),
			'throwing resolver' => $this->tokenContext(null, true),
		];
		foreach ($cases as $name => $context) {
			$row = $this->row();
			(new TokenAttribution($this->container($context)))->apply($row);

			$this->assertNull($row->getConsumer(), $name);
			$this->assertSame(self::SUMMARY, $row->getResultSummary(), $name);
		}
	}
}//end class
