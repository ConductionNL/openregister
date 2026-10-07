## ADDED Requirements

### Requirement: An object activity links to the owning app's detail page

An object activity SHALL link to the detail route the owning app registered
in the deep link registry for that register and schema, made absolute. Only
an object whose schema no app claims SHALL link to the OpenRegister view.

#### Scenario: A pipelinq client update

- GIVEN pipelinq registers `/apps/pipelinq/clients/{uuid}` for `pipelinq::client`
- WHEN a client is updated
- THEN the activity links to `/apps/pipelinq/clients/<uuid>`

#### Scenario: An unclaimed schema

- GIVEN no app registered a route for the schema
- WHEN an object of it is updated
- THEN the activity links to `/apps/openregister/#/registers/<id>/schemas/<id>/objects/<uuid>`
