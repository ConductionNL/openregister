<?php

/**
 * Unit tests for the guard that binds a read to a declared purpose.
 *
 * Covers the annotation that turns doelbinding on, the read that is left
 * exactly as it was on an instance that declares nothing, the refusal that
 * reaches the trail, and the deliberate hole: a schema asking for a purpose on
 * an instance administering none cannot be satisfied by any caller, so it is
 * logged rather than used to refuse every read.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Audit
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/verwerkingsregister-api/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Audit;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ProcessingPurpose;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\Verwerkingsactiviteit;
use OCA\OpenRegister\Service\Audit\PurposeContext;
use OCA\OpenRegister\Service\Audit\PurposeGuard;
use OCA\OpenRegister\Service\Audit\PurposeRefusedException;
use OCA\OpenRegister\Service\Audit\PurposeRegistry;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class PurposeGuardTest extends TestCase {
	private function schema(mixed $annotation): Schema {
		$schema = new Schema();
		$schema->setId(9476);
		if ($annotation === null) {
			$schema->setConfiguration([]);

			return $schema;
		}

		$schema->setConfiguration([PurposeGuard::ANNOTATION => $annotation]);

		return $schema;
	}//end schema()

	private function register(mixed $annotation): Register {
		$register = new Register();
		$register->setId(19);
		if ($annotation === null) {
			$register->setConfiguration([]);

			return $register;
		}

		$register->setConfiguration([PurposeGuard::ANNOTATION => $annotation]);

		return $register;
	}//end register()

	private function purpose(): ProcessingPurpose {
		$purpose = new ProcessingPurpose();
		$purpose->setCode('brp-adresonderzoek');
		$purpose->setUuid('purpose-uuid');
		$purpose->setStatus(ProcessingPurpose::STATUS_ACTIVE);

		return $purpose;
	}//end purpose()

	private function activity(): Verwerkingsactiviteit {
		$activity = new Verwerkingsactiviteit();
		$activity->setUuid('activity-uuid');
		$activity->setCode('VA-01');

		return $activity;
	}//end activity()

	private function context(?string $declared): PurposeContext {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn('');
		$request->method('getParam')->willReturn(null);

		$context = new PurposeContext($request);
		$context->setDeclared($declared);

		return $context;
	}//end context()

	private function registry(bool $administered, bool $resolves): PurposeRegistry {
		$registry = $this->createMock(PurposeRegistry::class);
		$registry->method('hasAdministeredPurposes')->willReturn($administered);

		if ($resolves === true) {
			$registry->method('requirePurpose')->willReturn(
				['purpose' => $this->purpose(), 'activity' => $this->activity()]
			);

			return $registry;
		}

		$registry->method('requirePurpose')->willThrowException(
			new PurposeRefusedException(
				rule: PurposeRefusedException::RULE_MISSING,
				reason: 'no purpose',
				purpose: null
			)
		);

		return $registry;
	}//end registry()

	public function testAReadOfAnUnannotatedSchemaIsLeftExactlyAsItWas(): void {
		$context = $this->context(null);
		$audit = $this->createMock(AuditTrailMapper::class);
		$audit->expects(self::never())->method('createPurposeRefusalEntry');

		$guard = new PurposeGuard($this->registry(true, true), $context, $audit, new NullLogger());

		self::assertNull($guard->enforce($this->register(null), $this->schema(null)));
		self::assertNull($context->accepted());
	}//end testAReadOfAnUnannotatedSchemaIsLeftExactlyAsItWas()

	public function testAnAnnotatedSchemaUnderABoundPurposeRunsAndRemembersIt(): void {
		$context = $this->context('brp-adresonderzoek');
		$audit = $this->createMock(AuditTrailMapper::class);

		$guard = new PurposeGuard($this->registry(true, true), $context, $audit, new NullLogger());

		self::assertSame('brp-adresonderzoek', $guard->enforce($this->register(null), $this->schema(true)));
		self::assertNotNull($context->accepted());
		self::assertSame('activity-uuid', $context->acceptedActivity()?->getUuid());
	}//end testAnAnnotatedSchemaUnderABoundPurposeRunsAndRemembersIt()

	public function testTheRegisterCarriesTheAnnotationWhenTheSchemaDoesNot(): void {
		$context = $this->context('brp-adresonderzoek');
		$guard = new PurposeGuard(
			$this->registry(true, true),
			$context,
			$this->createMock(AuditTrailMapper::class),
			new NullLogger()
		);

		self::assertTrue($guard->isRequired($this->register(true), $this->schema(null)));
	}//end testTheRegisterCarriesTheAnnotationWhenTheSchemaDoesNot()

	public function testAQuotedAnnotationIsStillAnAnnotation(): void {
		// JSON configuration written by hand carries "true" as often as true.
		// An annotation that silently means nothing because somebody quoted it
		// is exactly the silent no-op this change exists to prevent elsewhere.
		$guard = new PurposeGuard(
			$this->registry(true, true),
			$this->context('brp-adresonderzoek'),
			$this->createMock(AuditTrailMapper::class),
			new NullLogger()
		);

		self::assertTrue($guard->isRequired(null, $this->schema('true')));
		self::assertTrue($guard->isRequired(null, $this->schema('yes')));
		self::assertFalse($guard->isRequired(null, $this->schema('false')));
	}//end testAQuotedAnnotationIsStillAnAnnotation()

	public function testTheAnnotationSurvivesBeingSavedOnASchema(): void {
		// Schema::setConfiguration() DROPS any `x-openregister-*` key that is
		// not in ANNOTATION_VOCABULARY, silently, and returns a 200. An
		// annotation that does not round-trip would leave every unbound read
		// answering 200 while its author believed doelbinding was on.
		$schema = new Schema();
		$schema->setConfiguration([PurposeGuard::ANNOTATION => true]);

		self::assertArrayHasKey(PurposeGuard::ANNOTATION, (array)$schema->getConfiguration());
		self::assertSame([], $schema->consumeDroppedAnnotationKeys());
	}//end testTheAnnotationSurvivesBeingSavedOnASchema()

	public function testARefusedReadReachesTheTrailBeforeItIsThrown(): void {
		$audit = $this->createMock(AuditTrailMapper::class);
		$audit->expects(self::once())
			->method('createPurposeRefusalEntry')
			->with(
				self::equalTo(PurposeRefusedException::RULE_MISSING),
				self::isNull(),
				self::equalTo(19),
				self::equalTo(9476)
			)
			->willReturn(new AuditTrail());

		$guard = new PurposeGuard(
			$this->registry(true, false),
			$this->context(null),
			$audit,
			new NullLogger()
		);

		$this->expectException(PurposeRefusedException::class);
		$guard->enforce($this->register(null), $this->schema(true));
	}//end testARefusedReadReachesTheTrailBeforeItIsThrown()

	public function testARefusalThatCannotBeRecordedIsStillARefusal(): void {
		$audit = $this->createMock(AuditTrailMapper::class);
		$audit->method('createPurposeRefusalEntry')->willThrowException(new \RuntimeException('trail down'));

		$guard = new PurposeGuard(
			$this->registry(true, false),
			$this->context(null),
			$audit,
			new NullLogger()
		);

		$this->expectException(PurposeRefusedException::class);
		$guard->enforce($this->register(null), $this->schema(true));
	}//end testARefusalThatCannotBeRecordedIsStillARefusal()

	public function testAnAnnotationWithNoAdministeredPurposesDoesNotBrickTheSchema(): void {
		// Refusing here would refuse every read of this schema with no value
		// any caller could possibly name. It is logged instead, and the
		// annotation starts working the moment a purpose is administered.
		$guard = new PurposeGuard(
			$this->registry(false, true),
			$this->context(null),
			$this->createMock(AuditTrailMapper::class),
			new NullLogger()
		);

		self::assertFalse($guard->isRequired(null, $this->schema(true)));
		self::assertNull($guard->enforce(null, $this->schema(true)));
	}//end testAnAnnotationWithNoAdministeredPurposesDoesNotBrickTheSchema()
}//end class
