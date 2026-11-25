---
sidebar_position: 9
title: Share a configuration
description: Publish a configuration set or a flow to the Marketplace, and install one another organisation shared.
---

# Share a configuration

A **configuration** is the setup that makes one way of working hold together: the
registers, schemas, views, flows, sources and mappings an app needs, rather than the
records stored in them. Open Register lets you share a configuration with other
organisations through the **Marketplace**, and install what they shared, without
copying files by hand.

Every shared configuration is signed by the instance that published it. Your
administrator decides whose signatures this instance accepts, where it may install
from, and who may publish or install. See [Trust settings](#trust-settings-for-administrators)
below.

## Goal

By the end you will have published a configuration from this instance, and installed
one that someone else published.

## Prerequisites

- An account in a group that may publish and install shared configuration. When your
  administrator has not limited this, every signed-in user may.
- A GitHub credential to publish with. Publishing writes a repository on GitHub, and
  the credential stays with the credential broker: the app never sees the token.
- For installing: your administrator has trusted the publisher's key, or has allowed
  unsigned bundles (see below). Without either, every install is refused.

## What can be shared

The Marketplace offers the kinds of configuration this instance knows about:

| Kind | What it carries |
| --- | --- |
| Configuration set | A whole app's worth of registers, schemas, objects, views, flows, sources and mappings, installed as one unit |
| Flows | One or more flows, with the nodes they use |
| NL Design theme | A theme's token set. Only offered while the app that owns themes is installed |

Other apps add their own kinds, such as case types or publication types, by marking
their schemas as shareable. They appear in the same list.

What is never shared: record IDs, owners, organisations, timestamps, and secrets.
A shared configuration names the credentials it needs, never their values; the
installing organisation supplies its own.

## Publish a configuration

1. Open **Marketplace** at the bottom of the Open Register navigation.
2. Click **Publish**. The **Publish to the marketplace** dialog opens.
3. Pick the **Kind** of configuration, then tick what it should carry under
   **What the template carries**.
4. Give it a **Title**, a **Description** a stranger can understand, a **Version** and
   the **Publisher** name others will see.
5. Click **Publish**.

Open Register signs the bundle with this instance's key, creates the GitHub
repository when it does not exist yet, tags it so it can be found, and writes the
bundle into it. If you are not allowed to publish, the dialog says so and nothing is
written.

Publish again with a higher version when you change the configuration. Instances
that installed an earlier version keep it until someone there updates it.

## Install a configuration

1. Open **Marketplace**.
2. Search, or use **Filter by kind** to narrow the list.
3. Open an item to read what it carries and who published it.
4. Click **Install**.

Before anything is written, Open Register checks:

- **the source**: the repository must be on the allowed sources, when your
  administrator set any;
- **the signature**: a bundle changed after it was signed is always refused, and the
  signing key must be trusted;
- **your rights**: you must be allowed to install, and only an administrator may
  install a bundle that lets data cross organisations.

When one of these fails, the install is refused and nothing changes. When it passes,
everything the configuration carries is installed together. The **Installed** list
shows what came from the Marketplace, and **Updates available** shows where a newer
version was published. An installed version stays as it is until you click
**Update**.

## Trust settings (for administrators)

Administrators manage trust under **Administration settings → Open Register →
Shared configuration trust**:

- **Allowed sources**: GitHub owners or repositories to install from, separated by
  commas. An owner such as `ConductionNL` covers all of its repositories. An empty
  list allows every source.
- **Trusted publisher keys**: the public keys of the instances whose bundles you
  accept. With no key, every bundle is refused unless the next switch is on.
- **Allow bundles that no trusted key signed**: accepts unsigned bundles, and bundles
  signed by an unknown key, while no key is trusted. Set the install groups first:
  with an empty install list, any signed-in user could then install anything.
- **Groups that may publish** / **Groups that may install**: group IDs, separated by
  commas. An empty list lets every signed-in user act. Administrators always may.
- **Public key of this instance**: copy it and send it to the organisations that want
  to trust what you publish. They add it to their own trusted keys.

The same settings are available through the API; see
[Federated configuration sharing](../../federated-configuration.md).
