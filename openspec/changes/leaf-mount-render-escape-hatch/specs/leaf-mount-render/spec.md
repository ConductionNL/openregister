## ADDED Requirements

### Requirement: A render-surface leaf MAY render via a mount hand-off

A render-surface leaf MAY supply a mount pair, and a descriptor that supplies one half of the pair MUST supply the other. The mount pair is a `mount(el, props)` function and an `unmount(el)` function offered instead of the SFC tab and widget components. When present, the mount pair is the leaf's render artefact and the host renders the leaf by handing it a bare, host-owned DOM element rather than interpreting a component under the host's own Vue runtime.

The two functions SHALL travel as a pair. A descriptor MAY additionally supply the SFC tab and widget as
a same-major fast path, but the mount pair is what a cross-Vue-major host uses.
`mount` and `unmount` SHALL be keyed by the passed element so the same leaf can
be mounted into several elements on one page.

#### Scenario: A leaf registers a mount pair

- **GIVEN** a leaf descriptor declaring renderMode mount with a mount function and an unmount function
- **WHEN** the descriptor is registered
- **THEN** the registration is accepted and the leaf is available as a render-surface leaf

#### Scenario: A half mount pair is rejected

- **GIVEN** a leaf descriptor declaring renderMode mount that supplies a mount function but no unmount function
- **WHEN** the descriptor is registered
- **THEN** the registration is rejected and the rest of the registry is unaffected

### Requirement: The host MUST render a mount-mode leaf through the mount pair

The host surfaces MUST render a mount-mode leaf through its mount pair. The object sidebar, the detail page, and the dashboard MUST detect a mount-mode descriptor, render a bare host-owned container element in the position the SFC tab or widget would occupy, and call the leaf's `mount(el, props)` against that element. The host MUST NOT attempt to interpret a
mount-mode leaf as a component under its own Vue runtime.

The host MUST forward to `props` the same context it forwards to an SFC widget or
tab for that surface, including the register, the schema, the bound object id
when present, and the surface name. The leaf owns the framework instance rooted
at the element; the host owns only the element and its lifecycle.

#### Scenario: A mount-mode tab renders its own framework

- **GIVEN** a registered mount-mode leaf targeting the single-entity surface on an object
- **WHEN** the user opens that leaf's sidebar tab
- **THEN** the host renders a container element and calls the leaf mount function with the object context, and the leaf renders inside the element with its own framework

#### Scenario: A Vue-major mismatch no longer blanks the surface

- **GIVEN** a host built against one Vue major and a mount-mode leaf built against a different Vue major
- **WHEN** the leaf renders on the host object page
- **THEN** the leaf renders its content because each side runs its own framework inside the shared element

### Requirement: The mount lifecycle MUST unmount and re-mount deterministically

The host MUST call the leaf's `unmount(el)` before it removes the container
element, and MUST call it when the surface is hidden, when the host component is
destroyed, and when the bound object changes. On a bound-object change while the
surface stays visible, the host MUST call `unmount(el)` and then `mount(el, props)`
with the new context rather than mutating the live instance, because the host
cannot push new props into the leaf's own reactive tree.

The leaf MUST release the framework instance and its listeners on `unmount` so a
mount and unmount cycle leaves no leaked instance behind.

#### Scenario: Switching objects re-mounts the leaf

- **GIVEN** a mounted mount-mode leaf bound to one object
- **WHEN** the user navigates the host to a different object while the surface stays visible
- **THEN** the host unmounts the leaf for the old object and mounts it again with the new object context

#### Scenario: Hiding the surface unmounts the leaf

- **GIVEN** a mounted mount-mode leaf on an active sidebar tab
- **WHEN** the user switches away to another tab
- **THEN** the host calls the leaf unmount function before the container element is removed

### Requirement: A leaf mount failure MUST be isolated to its own container

A failure raised while a leaf mounts or unmounts MUST be caught by the host,
logged, and confined to that leaf's own container as an inline error or empty
state. One leaf's mount failure MUST NOT blank the sidebar, the detail page, the
dashboard, or any sibling leaf on the same page.

This mirrors the registry rule that one bad subscriber does not take down the
others and the server rule that a broken listener costs only its own leaf.

#### Scenario: A throwing mount does not break the page

- **GIVEN** two mount-mode leaves on one object page where the first leaf throws during mount
- **WHEN** the page renders both leaves
- **THEN** the first leaf shows an inline error in its own container and the second leaf renders normally

### Requirement: renderMode MUST be declared and correlated across the server and JS layers

A render-surface leaf MUST declare its render mode as either component or mount
on both the server descriptor and the JS registration under the shared id, and
the two values MUST be equal. The default render mode is component, which keeps
the existing SFC contract for every descriptor that does not opt in.

The server discovery surface MUST report each render-surface leaf's render mode
so a manifest app or admin UI can learn how a leaf renders without loading that
leaf's JavaScript bundle.

#### Scenario: Discovery reports the render mode

- **GIVEN** a registered mount-mode render-surface leaf
- **WHEN** a manifest app reads the OpenRegister capabilities surface
- **THEN** the leaf is reported with render mode mount without that leaf's JavaScript being loaded

#### Scenario: A render-mode mismatch across layers is a parity violation

- **GIVEN** a leaf whose server descriptor declares render mode mount but whose JS registration under the same id declares render mode component
- **WHEN** the parity check runs
- **THEN** the mismatch is reported as a parity violation

### Requirement: The SFC render path MUST remain the default for same-major and built-in leaves

A descriptor that does not declare renderMode mount MUST render through the
existing SFC path unchanged: it requires a tab and a widget component and is
interpreted under the host's own Vue runtime. The five built-in leaves and every
existing consumer descriptor MUST keep working without modification.

Render-surface parity MUST accept either shape: a component-mode descriptor is
complete when it supplies a tab and a widget, and a mount-mode descriptor is
complete when it supplies a mount and an unmount function.

#### Scenario: A built-in leaf still renders as a component

- **GIVEN** a built-in leaf registered without a render mode and supplying a tab and a widget
- **WHEN** the object page renders it
- **THEN** it renders through the existing SFC path exactly as before

#### Scenario: Parity accepts a complete mount-mode descriptor

- **GIVEN** a mount-mode descriptor that supplies a mount and an unmount function and no tab or widget
- **WHEN** the parity check runs
- **THEN** the descriptor passes parity because it declares a complete render pair for its render mode

@e2e exclude cross-Vue-major mount rendering is a build-and-runtime library concern — covered by nextcloud-vue unit tests, the parity gate, and live host-page verification
