<?php

/**
 * ObjectWriteTemplate: an inline template with a missing part is unresolved, and a structure or boolean inside a string renders as JSON.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow\Nodes
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow\Nodes;

use OCA\OpenRegister\Service\Flow\Nodes\ObjectWriteTemplate;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Flow\Nodes\ObjectWriteTemplate
 */
class ObjectWriteTemplateInlineBehaviourTest extends TestCase {

	public function testAnInlineTemplateWithOneMissingPartIsUnresolved(): void {
		$template = new ObjectWriteTemplate();

		self::assertSame(['resolved' => false, 'value' => null], $template->resolve('{{ name }}-{{ missing }}', ['name' => 'a']));
		self::assertSame(['resolved' => true, 'value' => 'a-7'], $template->resolve('{{ name }}-{{ n }}', ['name' => 'a', 'n' => 7]));
	}//end testAnInlineTemplateWithOneMissingPartIsUnresolved()

	public function testAStructureOrABooleanInsideAStringRendersAsJson(): void {
		self::assertSame(
			['resolved' => true, 'value' => 'tags=["x","y"] active=true'],
			(new ObjectWriteTemplate())->resolve('tags={{ tags }} active={{ active }}', ['tags' => ['x', 'y'], 'active' => true])
		);
	}//end testAStructureOrABooleanInsideAStringRendersAsJson()
}//end class
