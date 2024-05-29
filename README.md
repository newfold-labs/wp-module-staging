<a href="https://newfold.com/" target="_blank">
    <img src="https://newfold.com/content/experience-fragments/newfold/site-header/master/_jcr_content/root/header/logo.coreimg.svg/1621395071423/newfold-digital.svg" alt="Newfold Logo" title="Newfold Digital" align="right" 
height="42" />
</a>

# WordPress staging Module

[![Version Number](https://img.shields.io/github/v/release/newfold-labs/wp-module-staging?color=21a0ed&labelColor=333333)](https://github.com/newfold-labs/wp-module-staging/releases)
[![Lint](https://github.com/newfold-labs/wp-module-staging/actions/workflows/lint.yml/badge.svg?branch=main)](https://github.com/newfold-labs/wp-module-staging/actions/workflows/lint.yml)
[![License](https://img.shields.io/github/license/newfold-labs/wp-module-staging?labelColor=333333&color=666666)](https://raw.githubusercontent.com/newfold-labs/wp-module-staging/master/LICENSE)

Newfold module for staging functionality in brand plugins.

## Module Responsibilities

- Adds a staging page in the brand plugin app and navigation.
- Displays an indicator of current environment (production or staging) in the staging page.
- On staging, add an indicator to the WordPress admin bar showing the current environment is staging.
- The staging page displays details about the production and a staging site if it exists.
  - Production site details are the url - which matches the home url of the website.
  - Staging site details are the url and creation date. 
- On the production site, selecting the staging site will switch to that environment.
- On the staging site, selecting the production site will switch to that environment.
- On the production site, the production section of the staging page includes a button to create a staging site if one doesn't already exist.
- On the production site, the production section of the staging page includes a button to clone to staging if a staging site already exists.
- On the production site, the staging section of the staging page includes a button to delete the staging site if one exist
- On the staging site, the staging section of the staging page includes a button to deploy the staging site to production.
  - This deploy button includes options to deploy:
    - All changes
    - Database only
    - Files only

## Critical Paths

- A user can create a staging site from production.
- A user can clone the current production site to an existing staging site.
- A user can switch to staging from production.
- A user is notified clearly they are on a staging site.
- A user can switch to production from staging.
- A user can deploy staging changes to a production site.
- A user can delete a staging site from production.

## Installation

### 1. Add the Newfold Satis to your `composer.json`.

 ```bash
 composer config repositories.newfold composer https://newfold-labs.github.io/satis
 ```

### 2. Require the `newfold-labs/wp-module-staging` package.

 ```bash
 composer require newfold-labs/wp-module-staging
 ```

### 3. Instantiate the Features singleton to load all features.

```
Features::getInstance();
```

[More on NewFold WordPress Modules](https://github.com/newfold-labs/wp-module-loader)
[More on the NewFold Features Modules](https://github.com/newfold-labs/wp-module-features)
