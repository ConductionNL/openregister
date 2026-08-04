<?php

/**
 * Registers and schemas as a shareable configuration type.
 *
 * The foundational case: a register and its schemas are themselves shareable
 * configuration. OpenRegister already knows how to package them — the
 * `ConfigurationService` exports a register (and its schemas) as a portable,
 * slug-keyed OpenAPI document, and imports one back. This type is a thin adapter
 * that exposes that proven export/import through the one federated-config seam,
 * so a register travels the same way a flow, a case type or a theme does.
 *
 * Publishing a register carries its structure, not its data: objects are
 * included only when the selection asks for it, and never any secret.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Config\Types
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Config\Types;

use OCA\OpenRegister\Service\Config\IShareableConfigType;
use OCA\OpenRegister\Service\ConfigurationService;
use OCA\OpenRegister\Service\RegisterService;
use Throwable;
use UnexpectedValueException;

/**
 * Serialises and installs registers (with their schemas) through the engine.
 */
class RegisterSchemaShareableConfigType implements IShareableConfigType
{
    /**
     * Constructor.
     *
     * @param RegisterService      $registerService Loads the register to share.
     * @param ConfigurationService $configService   Exports and imports the bundle.
     */
    public function __construct(
        private readonly RegisterService $registerService,
        private readonly ConfigurationService $configService
    ) {

    }//end __construct()

    /**
     * The type id.
     *
     * @return string The id.
     */
    public function getId(): string
    {
        return 'openregister.registers';

    }//end getId()

    /**
     * The display name.
     *
     * @return string The name.
     */
    public function getDisplayName(): string
    {
        return 'Registers & schemas';

    }//end getDisplayName()

    /**
     * The discovery topic.
     *
     * @return string The topic.
     */
    public function getTopic(): string
    {
        return 'openregister-register';

    }//end getTopic()

    /**
     * Package a register (and its schemas) into a portable bundle.
     *
     * `$selection` is `{register: id|slug, includeObjects?: bool}`. The bundle is
     * the slug-keyed OpenAPI document the ConfigurationService already produces —
     * portable and instance-independent by construction.
     *
     * @param array $selection `{register, includeObjects?}`.
     *
     * @return array The exported configuration bundle.
     *
     * @throws UnexpectedValueException When no register is named or found.
     *
     * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
     */
    public function serialise(array $selection): array
    {
        $ref = trim((string) ($selection['register'] ?? ''));
        if ($ref === '') {
            throw new UnexpectedValueException('Sharing registers needs a register.');
        }

        try {
            $register = $this->registerService->find(id: $ref);
        } catch (Throwable $e) {
            throw new UnexpectedValueException(sprintf('No register "%s".', $ref));
        }

        return $this->configService->exportConfig(
            input: $register,
            includeObjects: (bool) ($selection['includeObjects'] ?? false)
        );

    }//end serialise()

    /**
     * Install a register bundle into this instance.
     *
     * Reuses the app-import path (idempotent, slug-matched, version-gated) — the
     * same one the shipped register descriptors use.
     *
     * @param array $bundle A configuration bundle produced by this type.
     *
     * @return array The import result (`{registers, schemas, ...}`).
     *
     * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
     */
    public function deserialise(array $bundle): array
    {
        $version = (string) ($bundle['info']['version'] ?? '1.0.0');

        return $this->configService->importFromApp(
            appId: 'openregister',
            data: $bundle,
            version: $version,
            force: false
        );

    }//end deserialise()
}//end class
