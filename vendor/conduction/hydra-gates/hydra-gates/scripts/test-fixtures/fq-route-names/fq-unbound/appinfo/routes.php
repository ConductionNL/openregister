<?php
// SPDX-License-Identifier: EUPL-1.2
//
// THE CONTROL HALF of fq-bound. Same app, same three bound fully-qualified
// routes, plus two that must still be reported:
//
//   OCA\Fixture\AppHost\Controller\GenericGadget#run
//       fully qualified, under this app's own namespace, and bound NOWHERE.
//       lib/AppInfo/Application.php does not name it. At runtime this is a
//       QueryException -> HintException "App fixture is not enabled", i.e. a
//       500 on every request. If _di_binds_fq_controller() ever loosens into
//       "a fully-qualified name is unverifiable, skip it", this goes quiet and
//       the suite goes red.
//
//   Settings\Widget#absentMethod
//       the class IS on disk and IS opened — the method is not on it. Only
//       reachable at all because the resolver stopped flattening `Settings\…`
//       names; before 2026-08-08 this route resolved to
//       lib/Controller/SettingsWidgetController.php and was reported as a
//       missing CLASS, which is a different (and wrong) defect.
return [
    'routes' => [
        ['name' => 'OCA\Fixture\AppHost\Controller\GenericDashboard#page', 'url' => '/', 'verb' => 'GET'],
        ['name' => 'OCA\Fixture\AppHost\Controller\GenericDashboard#catchAll', 'url' => '/{path}', 'verb' => 'GET', 'requirements' => ['path' => '.+'], 'defaults' => ['path' => '']],
        ['name' => 'OCA\Fixture\AppHost\Controller\GenericHealth#index', 'url' => '/api/health', 'verb' => 'GET'],

        ['name' => 'OCA\Fixture\AppHost\Controller\GenericGadget#run', 'url' => '/api/gadgets/run', 'verb' => 'POST'],

        ['name' => 'Settings\Widget#show', 'url' => '/api/widgets/{id}', 'verb' => 'GET'],
        ['name' => 'Settings\Widget#absentMethod', 'url' => '/api/widgets/absent', 'verb' => 'GET'],
    ],
];
