<?php

/**
 * Characterisation test pinning the observable shape of domain exceptions.
 *
 * Written on the code before the constructors were narrowed (ADR-011): each
 * exception keeps its message, HTTP code, getters and response body for every
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

use OCA\OpenRegister\Exception\AppendOnlyException;
use OCA\OpenRegister\Exception\ArchiveNotOfferedException;
use OCA\OpenRegister\Exception\DuplicateBlockedException;
use OCA\OpenRegister\Exception\ExportTooLargeException;
use OCA\OpenRegister\Exception\HookStoppedException;
use OCA\OpenRegister\Exception\InvalidTransitionInputException;
use OCA\OpenRegister\Exception\MergeDecisionException;
use OCA\OpenRegister\Exception\MergeNotFullyReadableException;
use OCA\OpenRegister\Exception\ReadOnlyTypeException;
use OCA\OpenRegister\Exception\UniqueHintException;
use PHPUnit\Framework\TestCase;

final class DomainExceptionShapeCharacterisationTest extends TestCase {

	public function testAppendOnlyDefaultsToUpdate(): void {
		$e = new AppendOnlyException(schemaIdentifier: 'log');
		self::assertSame(
			'SCHEMA_APPEND_ONLY: Schema "log" is append-only; update operations are not permitted.',
			$e->getMessage()
		);
		self::assertSame(405, $e->getCode());
		self::assertNull($e->getPrevious());
		self::assertSame('log', $e->getSchemaIdentifier());
		self::assertSame('update', $e->getOperation());
		self::assertSame(
			[
				'error' => 'SCHEMA_APPEND_ONLY',
				'message' => $e->getMessage(),
				'schema' => 'log',
				'operation' => 'update',
			],
			$e->toResponseBody()
		);
	}//end testAppendOnlyDefaultsToUpdate()

	public function testAppendOnlyNamesTheOperation(): void {
		$e = new AppendOnlyException('log', 'delete');
		self::assertSame(
			'SCHEMA_APPEND_ONLY: Schema "log" is append-only; delete operations are not permitted.',
			$e->getMessage()
		);
		self::assertSame('delete', $e->getOperation());
	}//end testAppendOnlyNamesTheOperation()

	public function testReadOnlyTypeOverridesTheMessage(): void {
		$e = new ReadOnlyTypeException(schemaIdentifier: 'view-rows');
		self::assertInstanceOf(AppendOnlyException::class, $e);
		self::assertSame(
			'SCHEMA_READ_ONLY: Schema "view-rows" lists the rows of a saved view; create operations are not permitted.',
			$e->getMessage()
		);
		self::assertSame(405, $e->getCode());
		self::assertSame('create', $e->getOperation());
		self::assertSame('SCHEMA_READ_ONLY', $e->toResponseBody()['error']);
		self::assertSame('view-rows', $e->toResponseBody()['schema']);

		$update = new ReadOnlyTypeException(schemaIdentifier: 'view-rows', operation: 'update');
		self::assertSame('update', $update->toResponseBody()['operation']);
	}//end testReadOnlyTypeOverridesTheMessage()

	public function testArchiveNotOffered(): void {
		$default = new ArchiveNotOfferedException();
		self::assertSame('This schema does not offer archiving', $default->getMessage());
		self::assertSame(422, $default->getCode());
		self::assertSame(ArchiveNotOfferedException::HTTP_STATUS, $default->getCode());

		$custom = new ArchiveNotOfferedException(message: 'Not here');
		self::assertSame('Not here', $custom->getMessage());
		self::assertSame(422, $custom->getCode());
	}//end testArchiveNotOffered()

	public function testDuplicateBlocked(): void {
		$default = new DuplicateBlockedException();
		self::assertSame('This object duplicates one that already exists.', $default->getMessage());
		self::assertSame(409, $default->getCode());
		self::assertSame([], $default->getMatches());

		$matches = [['uuid' => 'a'], ['uuid' => 'b']];
		$custom  = new DuplicateBlockedException('dup', $matches);
		self::assertSame('dup', $custom->getMessage());
		self::assertSame($matches, $custom->getMatches());
		self::assertSame(409, $custom->getCode());
	}//end testDuplicateBlocked()

	public function testExportTooLarge(): void {
		$e = new ExportTooLargeException(rowCount: 5001, maxRows: 5000);
		self::assertSame(
			'PDF export row count (5001) exceeds the maximum allowed (5000). '
			. 'Narrow the export with filters or use CSV/Excel export instead.',
			$e->getMessage()
		);
		self::assertSame(0, $e->getCode());
		self::assertSame(5001, $e->getRowCount());
		self::assertSame(5000, $e->getMaxRows());
		self::assertNull($e->getPrevious());
	}//end testExportTooLarge()

	public function testHookStopped(): void {
		$default = new HookStoppedException();
		self::assertSame('Operation blocked by schema hook', $default->getMessage());
		self::assertSame(0, $default->getCode());
		self::assertSame([], $default->getErrors());

		$errors = [['field' => 'status']];
		$custom = new HookStoppedException(message: 'stop', errors: $errors);
		self::assertSame('stop', $custom->getMessage());
		self::assertSame($errors, $custom->getErrors());
	}//end testHookStopped()

	public function testInvalidTransitionInput(): void {
		$default = new InvalidTransitionInputException();
		self::assertSame('Invalid transition input data', $default->getMessage());
		self::assertSame(0, $default->getCode());
		self::assertSame([], $default->getFields());

		$fields = ['reason' => 'required'];
		$custom = new InvalidTransitionInputException('bad', $fields);
		self::assertSame('bad', $custom->getMessage());
		self::assertSame($fields, $custom->getFields());
	}//end testInvalidTransitionInput()

	public function testMergeDecisionReindexesProperties(): void {
		$default = new MergeDecisionException();
		self::assertSame('The decision map does not match the preview.', $default->getMessage());
		self::assertSame(422, $default->getCode());
		self::assertSame([], $default->getProperties());

		$custom = new MergeDecisionException(message: 'm', properties: ['x' => 'title', 'y' => 'name']);
		self::assertSame('m', $custom->getMessage());
		self::assertSame(['title', 'name'], $custom->getProperties());
	}//end testMergeDecisionReindexesProperties()

	public function testMergeNotFullyReadableReindexesProperties(): void {
		$default = new MergeNotFullyReadableException();
		self::assertSame('This merge touches properties you may not read.', $default->getMessage());
		self::assertSame(403, $default->getCode());
		self::assertSame([], $default->getProperties());

		$custom = new MergeNotFullyReadableException(message: 'm', properties: [3 => 'secret']);
		self::assertSame(['secret'], $custom->getProperties());
	}//end testMergeNotFullyReadableReindexesProperties()

	public function testUniqueHintReindexesErrors(): void {
		$default = new UniqueHintException();
		self::assertSame(
			'x-openregister-unique-hint names a property this schema does not declare.',
			$default->getMessage()
		);
		self::assertSame(422, $default->getCode());
		self::assertSame([], $default->getErrors());

		$custom = new UniqueHintException('u', ['k' => 'missing']);
		self::assertSame('u', $custom->getMessage());
		self::assertSame(['missing'], $custom->getErrors());
	}//end testUniqueHintReindexesErrors()
}//end class
