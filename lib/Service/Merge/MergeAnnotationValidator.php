<?php

/**
 * OpenRegister MergeAnnotationValidator
 *
 * Validates the shape of an `x-openregister-merge` schema annotation,
 * returning a list of `{code, message}` errors. Mirrors the contract of
 * {@see \OCA\OpenRegister\Service\Survivorship\SurvivorshipAnnotationValidator}:
 * an empty array means valid; the caller (SchemaMapper) degrades any error to
 * a non-fatal warning so a malformed merge block never aborts a schema
 * import — the schema still stores objects, merges simply fall back to the
 * documented defaults.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Merge
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

namespace OCA\OpenRegister\Service\Merge;

/**
 * Shape validator for the `x-openregister-merge` annotation.
 *
 * @spec openspec/changes/mdm-merge-engine/tasks.md#1.2
 */
class MergeAnnotationValidator
{
    /**
     * Validate the `x-openregister-merge` annotation in a schema shape.
     *
     * @param array<string, mixed> $schema Shape with `properties` and `x-openregister-merge`.
     *
     * @return array<int, array{code: string, message: string}> Errors; empty when valid or absent.
     *
     * @spec openspec/changes/mdm-merge-engine/tasks.md#1.2
     */
    public function validate(array $schema): array
    {
        $annotation = ($schema['x-openregister-merge'] ?? null);
        if ($annotation === null) {
            return [];
        }

        if (is_array($annotation) === false) {
            return [
                [
                    'code'    => 'merge.not-object',
                    'message' => 'x-openregister-merge must be an object.',
                ],
            ];
        }

        $errors = [];

        if (array_key_exists('reversalWindowDays', $annotation) === true) {
            $window = $annotation['reversalWindowDays'];
            if (is_int($window) === false || $window <= 0) {
                $errors[] = [
                    'code'    => 'merge.invalid-reversal-window',
                    'message' => 'x-openregister-merge "reversalWindowDays" must be a positive integer.',
                ];
            }
        }

        $errors = array_merge($errors, $this->validateStringFields(annotation: $annotation));
<<<<<<< HEAD
=======
        $errors = array_merge($errors, $this->validateSourceLink(annotation: $annotation));
>>>>>>> origin/development

        return $errors;
    }//end validate()

    /**
<<<<<<< HEAD
=======
     * Validate an optional `sourceLink` block. A `reverseFk` mode requires
     * `sourceSchema` and `referenceField`; anything else is accepted (embedded
     * mode). Errors are non-fatal (surfaced as warnings by the caller).
     *
     * @param array<string, mixed> $annotation Merge annotation.
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
                    'code'    => 'merge.source-link-not-object',
                    'message' => 'x-openregister-merge "sourceLink" must be an object.',
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
                'code'    => 'merge.source-link-reverse-fk-incomplete',
                'message' => 'x-openregister-merge reverseFk "sourceLink" requires "sourceSchema" and "referenceField".',
            ];
        }

        return $errors;
    }//end validateSourceLink()

    /**
>>>>>>> origin/development
     * Validate the optional string-valued fields, when present.
     *
     * @param array<string, mixed> $annotation Merge annotation.
     *
     * @return array<int, array{code: string, message: string}>
     *
     * @spec openspec/changes/mdm-merge-engine/tasks.md#1.2
     */
    private function validateStringFields(array $annotation): array
    {
        $errors = [];

        $stringFields = [
            'sourceLinkField',
            'entityType',
            'statusField',
            'survivorStatus',
            'mergedStatus',
        ];

        foreach ($stringFields as $field) {
            if (array_key_exists($field, $annotation) === false) {
                continue;
            }

            if (is_string($annotation[$field]) === false) {
                $errors[] = [
                    'code'    => 'merge.field-not-string',
                    'message' => sprintf('x-openregister-merge "%s" must be a string.', $field),
                ];
            }
        }

        return $errors;
    }//end validateStringFields()
}//end class
