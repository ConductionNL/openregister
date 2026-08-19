<?php
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Fixture\Controller;

/**
 * THE ANTI-WIDENING HALF of this fixture.
 *
 * `gadget#run` is not one of the ten names Routes::standard() supplies and
 * it is not in appinfo/routes.php either — so it is a genuine 404 and
 * gate-14 invariant 1 MUST still report it. If the #223 fix had been
 * written as "an AppHost adopter is exempt from invariant 1" rather than as
 * "these ten names are declared elsewhere", this method would go quiet and
 * the gate would be retired for every ADR-040 app in the fleet.
 */
class GadgetController extends Controller
{
    #[NoAdminRequired]
    public function run(): JSONResponse
    {
        return new JSONResponse([]);
    }
}
