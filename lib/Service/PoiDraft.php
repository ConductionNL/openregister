<?php

/**
 * OpenRegister Poi Draft
 *
 * A Nextcloud Maps point of interest that is about to be created for an object.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author	Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link	  https://www.OpenRegister.app
 *
 * @spec openspec/specs/generic-integrations/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

/**
 * The name, position, category and comment of a POI to create.
 */
final class PoiDraft {

	/**
	 * Latitude.
	 *
	 * @var float
	 */
	private float $lat = 0.0;

	/**
	 * Longitude.
	 *
	 * @var float
	 */
	private float $lng = 0.0;

	/**
	 * Category; null for none.
	 *
	 * @var string|null
	 */
	private ?string $category = null;

	/**
	 * Comment; null for none.
	 *
	 * @var string|null
	 */
	private ?string $comment = null;

	/**
	 * Constructor.
	 *
	 * @param string $name POI name as given.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function __construct(
		public readonly string $name,
	) {
	}//end __construct()

	/**
	 * A copy at the given position.
	 *
	 * @param float $lat Latitude.
	 * @param float $lng Longitude.
	 *
	 * @return self The copy.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function locatedAt(float $lat, float $lng): self {
		$copy      = clone $this;
		$copy->lat = $lat;
		$copy->lng = $lng;
		return $copy;
	}//end locatedAt()

	/**
	 * A copy with the given category and comment.
	 *
	 * @param string|null $category Category; null for none.
	 * @param string|null $comment  Comment; null for none.
	 *
	 * @return self The copy.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function withNotes(?string $category, ?string $comment): self {
		$copy           = clone $this;
		$copy->category = $category;
		$copy->comment  = $comment;
		return $copy;
	}//end withNotes()

	/**
	 * Latitude.
	 *
	 * @return float The latitude.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function lat(): float {
		return $this->lat;
	}//end lat()

	/**
	 * Longitude.
	 *
	 * @return float The longitude.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function lng(): float {
		return $this->lng;
	}//end lng()

	/**
	 * Category.
	 *
	 * @return string|null The category.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function category(): ?string {
		return $this->category;
	}//end category()

	/**
	 * Comment.
	 *
	 * @return string|null The comment.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function comment(): ?string {
		return $this->comment;
	}//end comment()
}//end class
