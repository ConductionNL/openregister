<?php

/**
 * Characterisation test pinning the observable shape of domain exceptions (second set).
 *
 * Written on the code before the constructors were promoted and narrowed
 * (ADR-011): message, code, previous, getters and array bodies for every
 * argument a production caller passes.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Exception
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.openregister.app
 */

declare(strict_types=1);

namespace Unit\Exception;

use OCA\OpenRegister\Exception\ArchivalImmutableException;
use OCA\OpenRegister\Exception\BrokenReference;
use OCA\OpenRegister\Exception\CircularReferenceException;
use OCA\OpenRegister\Exception\ObjectExistsException;
use OCA\OpenRegister\Exception\PdfAnonymisationException;
use OCA\OpenRegister\Exception\ReferenceCycle;
use OCA\OpenRegister\Exception\ReferenceValidationException;
use OCA\OpenRegister\Exception\SanitizationException;
use OCA\OpenRegister\Exception\SchemaNotInRegisterException;
use OCA\OpenRegister\Exception\SharedMasterDataWriteException;
use OCA\OpenRegister\Exception\ValidationException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DomainExceptionShapeCharacterisationTwoTest extends TestCase {

	public function testArchivalImmutable(): void {
		$prev = new RuntimeException('root');
		$e    = new ArchivalImmutableException('call_log', 'purge', $prev);
		self::assertSame(
			'SCHEMA_ARCHIVAL_IMMUTABLE: Schema "call_log" declares x-openregister-archival; '
			. 'user-driven purge operations are not permitted. Rows expire automatically '
			. 'via the ArchivalRetentionTask cron.',
			$e->getMessage()
		);
		self::assertSame(403, $e->getCode());
		self::assertSame($prev, $e->getPrevious());
		self::assertSame('call_log', $e->getSchemaIdentifier());
		self::assertSame('purge', $e->toResponseBody()['operation']);

		$default = new ArchivalImmutableException(schemaIdentifier: 's');
		self::assertSame('delete', $default->toResponseBody()['operation']);
	}//end testArchivalImmutable()

	public function testObjectExists(): void {
		$default = new ObjectExistsException();
		self::assertSame('An object with this identifier already exists', $default->getMessage());
		self::assertSame(409, $default->getCode());
		self::assertNull($default->getUuid());

		$prev   = new RuntimeException('root');
		$custom = new ObjectExistsException('taken', 'u-1', 400, $prev);
		self::assertSame('taken', $custom->getMessage());
		self::assertSame('u-1', $custom->getUuid());
		self::assertSame(400, $custom->getCode());
		self::assertSame($prev, $custom->getPrevious());
	}//end testObjectExists()

	public function testCircularReference(): void {
		$cycle = [['schema' => 'person', 'uuid' => 'a']];
		$e     = new CircularReferenceException(
			referenceCycle: new ReferenceCycle(referencedUuid: 'a', targetSchemaSlug: 'person', cycle: $cycle),
			code: 422
		);
		self::assertInstanceOf(ValidationException::class, $e);
		self::assertSame("Circular reference detected for object 'a' in schema 'person'", $e->getMessage());
		self::assertSame(422, $e->getCode());
		self::assertNull($e->getPrevious());
		self::assertNull($e->getErrors());
		self::assertSame('a', $e->getReferencedUuid());
		self::assertSame('person', $e->getTargetSchemaSlug());
		self::assertSame($cycle, $e->getCycle());
		self::assertSame(
			[
				'referencedUuid' => 'a',
				'targetSchemaSlug' => 'person',
				'cycle' => $cycle,
				'message' => $e->getMessage(),
				'code' => 422,
			],
			$e->toArray()
		);

		$default = new CircularReferenceException(
			referenceCycle: new ReferenceCycle(referencedUuid: 'b', targetSchemaSlug: 'org')
		);
		self::assertSame(422, $default->getCode());
		self::assertSame([], $default->getCycle());
	}//end testCircularReference()

	public function testReferenceValidation(): void {
		$reference = new BrokenReference(
			propertyName: 'owner',
			referencedUuid: 'u-9',
			targetSchemaSlug: 'person',
			targetRegister: 'crm'
		);
		$prev      = new RuntimeException('root');
		$e         = new ReferenceValidationException(reference: $reference, code: 422, previous: $prev);
		self::assertSame("Referenced object 'u-9' not found in schema 'person' for property 'owner'", $e->getMessage());
		self::assertSame(422, $e->getCode());
		self::assertSame($prev, $e->getPrevious());
		self::assertSame('owner', $e->getPropertyName());
		self::assertSame('u-9', $e->getReferencedUuid());
		self::assertSame('person', $e->getTargetSchemaSlug());
		self::assertSame('crm', $e->getTargetRegister());
		self::assertSame(
			[
				'propertyName' => 'owner',
				'referencedUuid' => 'u-9',
				'targetSchemaSlug' => 'person',
				'targetRegister' => 'crm',
				'message' => $e->getMessage(),
				'code' => 422,
			],
			$e->toArray()
		);

		$custom = new ReferenceValidationException(reference: $reference, message: 'Custom');
		self::assertSame('Custom', $custom->getMessage());
		self::assertSame(422, $custom->getCode());

		$empty = new ReferenceValidationException(reference: $reference, message: '');
		self::assertSame("Referenced object 'u-9' not found in schema 'person' for property 'owner'", $empty->getMessage());
	}//end testReferenceValidation()

	public function testValidation(): void {
		$prev = new RuntimeException('root');
		$e    = new ValidationException('invalid', 400, $prev);
		self::assertSame('invalid', $e->getMessage());
		self::assertSame(400, $e->getCode());
		self::assertSame($prev, $e->getPrevious());
		self::assertNull($e->getErrors());

		$default = new ValidationException(message: 'x');
		self::assertSame(0, $default->getCode());
	}//end testValidation()

	public function testPdfAnonymisation(): void {
		$default = new PdfAnonymisationException(reason: PdfAnonymisationException::REASON_ENCRYPTED_PDF);
		self::assertSame('PDF anonymisation failed: encrypted_pdf', $default->getMessage());
		self::assertSame(0, $default->getCode());
		self::assertSame('encrypted_pdf', $default->getReason());
		self::assertSame([], $default->getDiagnostic());

		$prev   = new RuntimeException('root');
		$custom = new PdfAnonymisationException(
			reason: PdfAnonymisationException::REASON_INTERNAL_ERROR,
			message: 'boom',
			diagnostic: ['stage' => 'extract'],
			previous: $prev
		);
		self::assertSame('boom', $custom->getMessage());
		self::assertSame(['stage' => 'extract'], $custom->getDiagnostic());
		self::assertSame($prev, $custom->getPrevious());
	}//end testPdfAnonymisation()

	public function testSanitization(): void {
		$default = new SanitizationException(reason: SanitizationException::REASON_ENCRYPTED);
		self::assertSame('Office document sanitisation failed: encrypted', $default->getMessage());
		self::assertSame(0, $default->getCode());
		self::assertSame('encrypted', $default->getReason());
		self::assertSame('encrypted', $default->reason);

		$prev   = new RuntimeException('root');
		$custom = new SanitizationException(reason: 'internal', message: 'bad zip', previous: $prev);
		self::assertSame('bad zip', $custom->getMessage());
		self::assertSame($prev, $custom->getPrevious());
	}//end testSanitization()

	public function testSharedMasterDataWrite(): void {
		$bare = new SharedMasterDataWriteException(holderUuid: 'org-1');
		self::assertSame(
			'This register is shared master data held by org-1. Your organisation reads it and cannot change it. '
			. 'Ask org-1 to make the change.',
			$bare->getMessage()
		);
		self::assertSame(403, $bare->getCode());
		self::assertSame('org-1', $bare->getHolderUuid());
		self::assertNull($bare->getHolderName());
		self::assertSame('register', $bare->getResourceType());

		$full = new SharedMasterDataWriteException(
			holderUuid: 'org-1',
			holderName: 'Gemeente',
			resourceType: 'schema',
			resourceTitle: 'Persoon'
		);
		self::assertSame(
			'This schema "Persoon" is shared master data held by Gemeente. Your organisation reads it and cannot '
			. 'change it. Ask Gemeente to make the change.',
			$full->getMessage()
		);
		self::assertSame('Gemeente', $full->getHolderName());
		self::assertSame('schema', $full->getResourceType());

		$emptyName = new SharedMasterDataWriteException(holderUuid: 'org-2', holderName: '', resourceTitle: '');
		self::assertStringContainsString('This register is shared master data held by org-2.', $emptyName->getMessage());
		self::assertSame('', $emptyName->getHolderName());
	}//end testSharedMasterDataWrite()

	public function testSchemaNotInRegister(): void {
		$empty = new SchemaNotInRegisterException(
			schemaSlug: 'person',
			registerId: 7,
			registerSlug: 'crm',
			candidatesElsewhere: 2,
			registerSchemaCount: 0
		);
		self::assertSame(
			'Schema slug "person" cannot resolve because register "crm" (id 7) carries no schemas at all. '
			. '2 schema(s) elsewhere on this instance carry this slug, and resolving to one of '
			. 'them would bind data to a register its owner did not choose. '
			. 'Run `occ openregister:registers:relink-schemas` to inspect and repair the linkage.',
			$empty->getMessage()
		);
		self::assertSame(7, $empty->getRegisterId());
		self::assertSame('crm', $empty->getRegisterSlug());
		self::assertSame('person', $empty->getSchemaSlug());
		self::assertSame(2, $empty->getCandidatesElsewhere());
		self::assertSame(0, $empty->getRegisterSchemaCount());

		$unnamed = new SchemaNotInRegisterException(schemaSlug: 'x', registerSchemaCount: 14);
		self::assertSame(
			'Schema slug "x" is not carried by the named register, which carries 14 schema(s). 0 schema(s) elsewhere '
			. 'on this instance carry this slug; none of them is served here, because naming a register '
			. 'makes it a boundary. If the linkage was lost, run '
			. '`occ openregister:registers:relink-schemas` to inspect and repair it.',
			$unnamed->getMessage()
		);
		self::assertNull($unnamed->getRegisterId());
		self::assertNull($unnamed->getRegisterSlug());

		$noSlug = new SchemaNotInRegisterException(schemaSlug: 'x', registerId: 3);
		self::assertStringContainsString('register "?" (id 3)', $noSlug->getMessage());
	}//end testSchemaNotInRegister()
}//end class
