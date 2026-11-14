<?php

/**
 * ImportTranslationGlossaryRegister: materialises the translation glossary register.
 *
 * `lib/Settings/translation_glossary_register.json` declares the
 * `translation-glossary` register with its `translation-term` and
 * `translation-style-guide` schemas and a few example entries. Shipping the
 * JSON alone does nothing at runtime (ADR-005 Rule 1): without this step the
 * register never exists and GlossaryService finds no terms to apply.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Repair
 * @package  OCA\OpenRegister\Repair
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/ai-translation-with-a-glossary/specs
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Repair;

use OCA\OpenRegister\Service\Configuration\DescriptorConfigIdsInterface;

/**
 * Imports the translation glossary register descriptor idempotently.
 *
 * @spec openspec/changes/ai-translation-with-a-glossary/specs
 *
 * @psalm-suppress UnusedClass Instantiated by the NC repair framework (appinfo/info.xml).
 */
class ImportTranslationGlossaryRegister extends AbstractRegisterImportStep {
	/**
	 * App-relative descriptor path.
	 *
	 * @var string
	 */
	protected const REGISTER_PATH = '/lib/Settings/translation_glossary_register.json';

	/**
	 * Descriptor version passed to the importer's version_compare gate.
	 *
	 * @var string
	 */
	protected const REGISTER_VERSION = '1.0.0';

	/**
	 * Name of the register in the step's output lines.
	 *
	 * @var string
	 */
	protected const LABEL = 'Translation-glossary';

	/**
	 * Output line after a successful import.
	 *
	 * @var string
	 */
	protected const IMPORTED = 'Translation-glossary register imported (translation-term + translation-style-guide)';

	/**
	 * Configuration identity for this descriptor, so its version gate moves on its own.
	 *
	 * @var string
	 */
	protected const CONFIG_APP_ID = DescriptorConfigIdsInterface::TRANSLATION_GLOSSARY;

	/**
	 * Get the name of this repair step.
	 *
	 * @return string The step name.
	 *
	 * @spec openspec/changes/ai-translation-with-a-glossary/specs
	 */
	public function getName(): string {
		return 'Import OpenRegister translation-glossary register (glossary terms and style guides for AI translation)';
	}//end getName()
}//end class
