<?php

/**
 * Unit tests for ObjectTranslationRequest — the data a translation reads.
 *
 * The request snapshots the object data at construction; (ObjectEntity::getObject() is typed array, so the `?? []` fallback is unreachable).
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectTranslationRequest;
use PHPUnit\Framework\TestCase;

final class ObjectTranslationRequestTest extends TestCase {

	public function testTheDataIsTheObjectsDataAtConstruction(): void {
		$object = new ObjectEntity();
		$object->setObject(['title' => 'Hallo']);

		$request = new ObjectTranslationRequest($object, 'Dutch', 'English');
		$object->setObject(['title' => 'Changed later']);

		$this->assertSame('Hallo', $request->data['title']);
	}
}
