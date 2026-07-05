<?php

/**
 * OpenRegister SurvivorshipAnnotationValidator
 *
 * Validates the shape of an `x-openregister-survivorship` schema annotation,
 * returning a list of `{code, message}` errors. Mirrors the contract of
 * {@see \OCA\OpenRegister\Service\Quality\QualityAnnotationValidator}: an
 * empty array means valid; the caller (SchemaMapper) degrades any error to a
 * non-fatal warning so a malformed survivorship block never aborts a schema
 * import — the schema still stores objects, the golden record simply is not
 * materialised.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Survivorship
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Survivorship;

/**
 * Shape validator for the `x-openregister-survivorship` annotation.
 *
 * @spec openspec/changes/mdm-survivorship-engine/tasks.md#3.2
 */
class SurvivorshipAnnotationValidator
{
    /**
     * Default ordered tier list (weakest first) when the annotation omits one.
     *
     * @var array<int, string>
     */
    private const DEFAULT_TIER_ORDER = ['discard', 'bronze', 'silver', 'gold'];

    /**
     * Default tier for an uncontested source with no matching trust row.
     *
     * @var string
     */
    private const DEFAULT_TIER = 'bronze';

    /**
     * Default tier that is always excluded from the golden record.
     *
     * @var string
     */
    private const DEFAULT_DISCARD_TIER = 'discard';

    /**
     * Validate the `x-openregister-survivorship` annotation in a schema shape.
     *
     * @param array<string, mixed> $schema Shape with `properties` and `x-openregister-survivorship`.
     *
     * @return array<int, array{code: string, message: string}> Errors; empty when valid or absent.
     *
     * @spec openspec/changes/mdm-survivorship-engine/tasks.md#3.2
     */
    public function validate(array $schema): array
    {
        $annotation = ($schema['x-openregister-survivorship'] ?? null);
        if ($annotation === null) {
            return [];
        }

        if (is_array($annotation) === false) {
            return [
                [
                    'code'    => 'survivorship.not-object',
                    'message' => 'x-openregister-survivorship must be an object.',
                ],
            ];
        }

        $errors = [];

<<<<<<< HEAD
        $sourceLinkField = (string) ($annotation['sourceLinkField'] ?? '');
        if ($sourceLinkField === '') {
            $errors[] = [
                'code'    => 'survivorship.missing-source-link-field',
                'message' => 'x-openregister-survivorship requires a "sourceLinkField".',
=======
        // A reverse-FK `sourceLink` block supplies the source linkage instead
        // of an embedded `sourceLinkField`; when it is present and well-formed,
        // `sourceLinkField` is not required.
        $sourceLinkErrors = $this->validateSourceLink(annotation: $annotation);
        $errors           = array_merge($errors, $sourceLinkErrors);

        $sourceLinkField = (string) ($annotation['sourceLinkField'] ?? '');
        if ($sourceLinkField === '' && $this->hasReverseFkSourceLink(annotation: $annotation) === false) {
            $errors[] = [
                'code'    => 'survivorship.missing-source-link-field',
                'message' => 'x-openregister-survivorship requires a "sourceLinkField" (or a reverse-FK "sourceLink" block).',
>>>>>>> origin/development
            ];
        }

        $errors = array_merge($errors, $this->validateTierOrder(annotation: $annotation));
        $errors = array_merge($errors, $this->validateTrustLookup(annotation: $annotation));
        $errors = array_merge($errors, $this->validateOverridesField(annotation: $annotation));

        return $errors;
    }//end validate()

    /**
<<<<<<< HEAD
=======
     * Validate an optional `sourceLink` block. A `reverseFk` mode requires
     * `sourceSchema` and `referenceField`; anything else is accepted (embedded
     * mode). Errors are non-fatal (surfaced as warnings by the caller).
     *
     * @param array<string, mixed> $annotation Survivorship annotation.
     *
     * @return array<int, array{code: string, message: string}>
     *
     * @spec openspec/changes/mdm-reverse-fk-source-resolution/tasks.md#1.1
     */
    private function validateSourceLink(array $annotation): array
    {
        $sourceLink = ($annotation['sourceLink'] ?? null);
        if ($sourceLink === null) {
            return [];
        }

        if (is_array($sourceLink) === false) {
            return [
                [
                    'code'    => 'survivorship.source-link-not-object',
                    'message' => 'x-openregister-survivorship "sourceLink" must be an object.',
                ],
            ];
        }

        if ((string) ($sourceLink['mode'] ?? 'embedded') !== 'reverseFk') {
            return [];
        }

        $errors = [];
        if ((string) ($sourceLink['sourceSchema'] ?? '') === ''
            || (string) ($sourceLink['referenceField'] ?? '') === ''
        ) {
            $errors[] = [
                'code'    => 'survivorship.source-link-reverse-fk-incomplete',
                'message' => 'x-openregister-survivorship reverseFk "sourceLink" requires "sourceSchema" and "referenceField".',
            ];
        }

        return $errors;
    }//end validateSourceLink()

    /**
     * Whether the annotation carries a well-formed reverse-FK `sourceLink`.
     *
     * @param array<string, mixed> $annotation Survivorship annotation.
     *
     * @return bool
     *
     * @spec openspec/changes/mdm-reverse-fk-source-resolution/tasks.md#1.1
     */
    private function hasReverseFkSourceLink(array $annotation): bool
    {
        $sourceLink = ($annotation['sourceLink'] ?? null);
        if (is_array($sourceLink) === false) {
            return false;
        }

        return (string) ($sourceLink['mode'] ?? 'embedded') === 'reverseFk'
            && (string) ($sourceLink['sourceSchema'] ?? '') !== ''
            && (string) ($sourceLink['referenceField'] ?? '') !== '';
    }//end hasReverseFkSourceLink()

    /**
>>>>>>> origin/development
     * Validate `tierOrder` plus `defaultTier` / `discardTier` membership.
     *
     * @param array<string, mixed> $annotation Survivorship annotation.
     *
     * @return array<int, array{code: string, message: string}>
     *
     * @spec openspec/changes/mdm-survivorship-engine/tasks.md#3.2
     */
    private function validateTierOrder(array $annotation): array
    {
        $errors = [];

        $tierOrder = ($annotation['tierOrder'] ?? self::DEFAULT_TIER_ORDER);
        if (is_array($tierOrder) === false) {
            $errors[] = [
                'code'    => 'survivorship.tier-order-not-array',
                'message' => 'x-openregister-survivorship "tierOrder" must be an array.',
            ];

            // Cannot validate defaultTier/discardTier membership against a
            // non-array tierOrder; the caller degrades the whole block anyway.
            return $errors;
        }

        $defaultTier = (string) ($annotation['defaultTier'] ?? self::DEFAULT_TIER);
        if (in_array($defaultTier, $tierOrder, true) === false) {
            $errors[] = [
                'code'    => 'survivorship.default-tier-not-in-order',
                'message' => sprintf('"defaultTier" (%s) must be present in "tierOrder".', $defaultTier),
            ];
        }

        $discardTier = (string) ($annotation['discardTier'] ?? self::DEFAULT_DISCARD_TIER);
        if (in_array($discardTier, $tierOrder, true) === false) {
            $errors[] = [
                'code'    => 'survivorship.discard-tier-not-in-order',
                'message' => sprintf('"discardTier" (%s) must be present in "tierOrder".', $discardTier),
            ];
        }

        return $errors;
    }//end validateTierOrder()

    /**
     * Validate the optional `trustLookup.keys` block.
     *
     * @param array<string, mixed> $annotation Survivorship annotation.
     *
     * @return array<int, array{code: string, message: string}>
     */
    private function validateTrustLookup(array $annotation): array
    {
        $trustLookup = ($annotation['trustLookup'] ?? null);
        if ($trustLookup === null) {
            return [];
        }

        if (is_array($trustLookup) === false) {
            return [
                [
                    'code'    => 'survivorship.trust-lookup-not-object',
                    'message' => 'x-openregister-survivorship "trustLookup" must be an object.',
                ],
            ];
        }

        $keys = ($trustLookup['keys'] ?? null);
        if ($keys === null) {
            return [];
        }

        if (is_array($keys) === false || count($keys) === 0) {
            return [
                [
                    'code'    => 'survivorship.trust-lookup-keys-invalid',
                    'message' => 'x-openregister-survivorship "trustLookup.keys" must be a non-empty array.',
                ],
            ];
        }

        return [];
    }//end validateTrustLookup()

    /**
     * Validate the optional `overridesField` key: when present it MUST be a
     * non-empty string naming the object field holding the per-object
     * attribute-override map. Absent is valid — callers default to
     * `attributeOverrides`.
     *
     * @param array<string, mixed> $annotation Survivorship annotation.
     *
     * @return array<int, array{code: string, message: string}>
     *
     * @spec openspec/changes/mdm-survivorship-override/tasks.md#1.4
     */
    private function validateOverridesField(array $annotation): array
    {
        if (array_key_exists('overridesField', $annotation) === false) {
            return [];
        }

        $overridesField = $annotation['overridesField'];
        if (is_string($overridesField) === false || $overridesField === '') {
            return [
                [
                    'code'    => 'survivorship.overrides-field-invalid',
                    'message' => 'x-openregister-survivorship "overridesField" must be a non-empty string.',
                ],
            ];
        }

        return [];
    }//end validateOverridesField()
}//end class
