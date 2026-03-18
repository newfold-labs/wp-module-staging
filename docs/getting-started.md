---
name: wp-module-staging
title: Getting started
description: Prerequisites, install, and run.
updated: 2025-03-18
---

# Getting started

## Prerequisites

- **PHP** 7.3+.
- **Composer.** Runtime dependencies are provided by the host plugin; this repo uses require-dev for lint and tests.

## Install

```bash
composer install
```

## Run tests

```bash
composer run test
composer run test-coverage
```

## Lint

```bash
composer run lint
composer run fix
```

## Using in a host plugin

1. Add `newfold-labs/wp-module-staging` as a dependency (and any runtime deps the module expects from the host).
2. The module registers with the loader. See [integration.md](integration.md).
