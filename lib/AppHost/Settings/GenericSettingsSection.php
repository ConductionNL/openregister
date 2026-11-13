<?php

/**
 * OpenRegister AppHost — Generic Settings Section
 *
 * Engine-owned generalisation of the per-app `SettingsSection`. Defines the
 * leaf app's section in the Nextcloud admin settings, parameterised by id,
 * display name, icon file, priority and the owning app id (for the icon path),
 * passed as a {@see SettingsPlacement} plus a {@see SectionAppearance}.
 *
 * NC instantiates the section by the class name in info.xml
 * `<settings><admin-section>`, so the leaf keeps a one-line subclass.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Settings
 * @package  OCA\OpenRegister\AppHost\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\AppHost\Settings;

use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

/**
 * Generic admin settings section for AppHost-adopting apps.
 *
 * @spec openspec/changes/apphost-boilerplate-controllers/tasks.md#task-2.4
 */
class GenericSettingsSection implements IIconSection {
	/**
	 * The section identifier.
	 *
	 * @var string
	 */
	protected readonly string $sectionId;

	/**
	 * Human-readable section display name.
	 *
	 * @var string
	 */
	protected readonly string $name;

	/**
	 * Owning app id (for the icon image path).
	 *
	 * @var string
	 */
	protected readonly string $appId;

	/**
	 * Icon file name within the app's img/ dir.
	 *
	 * @var string
	 */
	protected readonly string $iconFile;

	/**
	 * Section ordering priority.
	 *
	 * @var integer
	 */
	protected readonly int $priority;

	/**
	 * Constructor.
	 *
	 * @param SettingsPlacement $placement    Owning app id, section id and the section's priority.
	 * @param SectionAppearance $appearance   Display name and icon file.
	 * @param IURLGenerator     $urlGenerator URL generator (icon path).
	 */
	public function __construct(
		SettingsPlacement $placement,
		SectionAppearance $appearance,
		protected readonly IURLGenerator $urlGenerator,
	) {
		$this->sectionId = $placement->sectionId;
		$this->appId     = $placement->appId;
		$this->priority  = $placement->priority;
		$this->name      = $appearance->name;
		$this->iconFile  = $appearance->iconFile;
	}//end __construct()

	/**
	 * The section identifier.
	 *
	 * @return string
	 */
	public function getID(): string {
		return $this->sectionId;
	}//end getID()

	/**
	 * The display name of this section.
	 *
	 * @return string
	 */
	public function getName(): string {
		return $this->name;
	}//end getName()

	/**
	 * The ordering priority for this section.
	 *
	 * @return int
	 */
	public function getPriority(): int {
		return $this->priority;
	}//end getPriority()

	/**
	 * The icon path for this section.
	 *
	 * @return string
	 */
	public function getIcon(): string {
		return $this->urlGenerator->imagePath(appName: $this->appId, file: $this->iconFile);
	}//end getIcon()
}//end class
