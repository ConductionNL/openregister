<?php

/**
 * Unit tests for BulkEventCandidate — the register/schema key a bulk save groups events by.
 *
 * The register entity wins over the row register id; without either the register part is empty; without a schema the schema part is empty.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object\SaveObjects
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object\SaveObjects;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\SaveObjects\BulkEventCandidate;
use PHPUnit\Framework\TestCase;

final class BulkEventCandidateTest extends TestCase {

	private function schema(int $id): Schema {
		$schema = new Schema();
		$schema->setId($id);
		return $schema;
	}

	public function testTheRegisterEntityWinsOverTheRowRegisterId(): void {
		$register = new Register();
		$register->setId(5);

		$this->assertSame('5/9', (new BulkEventCandidate($this->schema(9), $register, 7))->key());
	}

	public function testWithoutARegisterEntityTheRowRegisterIdIsUsed(): void {
		$this->assertSame('7/9', (new BulkEventCandidate($this->schema(9), null, 7))->key());
	}

	public function testWithoutAnyRegisterOrSchemaThosePartsAreEmpty(): void {
		$this->assertSame('/9', (new BulkEventCandidate($this->schema(9), null, null))->key());
		$this->assertSame('7/', (new BulkEventCandidate(null, null, 7))->key());
	}
}
