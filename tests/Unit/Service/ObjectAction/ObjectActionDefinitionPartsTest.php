<?php

/**
 * Unit tests for the four value parts a declared action is built from.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ObjectAction;

use OCA\OpenRegister\Service\ObjectAction\ObjectActionBody;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionIdentity;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionOffer;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionRunPolicy;
use PHPUnit\Framework\TestCase;

class ObjectActionDefinitionPartsTest extends TestCase {

	public function testEachPartKeepsWhatItIsGiven(): void {
		$identity = new ObjectActionIdentity('k', ['nl' => 'L'], 'd', null);
		$offer    = new ObjectActionOffer(['bulk'], ['type' => 'object'], ['==' => [1, 1]], 'm');
		$body     = new ObjectActionBody('sync', null, 'flow', 'f');
		$policy   = new ObjectActionRunPolicy('c', [], true);

		$this->assertSame(['k', ['nl' => 'L'], 'd', null], [$identity->key, $identity->label, $identity->description, $identity->icon]);
		$this->assertSame([['bulk'], ['type' => 'object'], ['==' => [1, 1]], 'm'], [$offer->surfaces, $offer->inputs, $offer->when, $offer->whenMessage]);
		$this->assertSame(['sync', null, 'flow', 'f'], [$body->mode, $body->next, $body->kind, $body->body]);
		$this->assertSame(['c', [], true], [$policy->confirm, $policy->elevate, $policy->reversible]);
	}//end testEachPartKeepsWhatItIsGiven()
}//end class
