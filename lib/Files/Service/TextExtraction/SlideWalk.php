<?php

/**
 * OpenRegister slide walk state
 *
 * The state one PresentationSlideParser::parseSlide() call carries while it
 * walks a slide's shape tree: the slide's relationships, how many groups deep
 * the walk is, and the titles, body paragraphs and pictures found so far.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\TextExtraction
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/text-extraction-presentation/spec.md#requirement-each-slide-carries-its-title-and-its-body-text-in-shape-order-req-pptx-002
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Service\TextExtraction;

/**
 * Mutable accumulator for one slide walk.
 *
 * @spec openspec/specs/text-extraction-presentation/spec.md#requirement-each-slide-carries-its-title-and-its-body-text-in-shape-order-req-pptx-002
 */
final class SlideWalk {

	/**
	 * The slide's relationships, by id.
	 *
	 * @var array<string, array{type: string, target: string, external: bool}>
	 */
	public readonly array $relationships;

	/**
	 * How many groups deep the walk currently is.
	 *
	 * @var int
	 */
	public int $depth = 0;

	/**
	 * The title texts found so far, one per title shape.
	 *
	 * @var list<string>
	 */
	public array $titles = [];

	/**
	 * The body paragraphs found so far, in shape order.
	 *
	 * @var list<string>
	 */
	public array $body = [];

	/**
	 * The pictures found so far, in shape order.
	 *
	 * @var list<array{target: string, external: bool, name: string, description: string}>
	 */
	public array $images = [];

	/**
	 * Start a walk over a slide with the given relationships.
	 *
	 * @param array<string, array{type: string, target: string, external: bool}> $relationships The slide's relationships.
	 */
	public function __construct(array $relationships) {
		$this->relationships = $relationships;
	}//end __construct()
}//end class
