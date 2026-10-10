# Now Pages

## About

This repository provides the features of the WordPress plugin _Now Pages_. The repository is used as a basis for deploying the plugin to the WordPress repository. It is not intended to run as a plugin as it is, even if that is possible for development.

[**Start demo in playground**](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/threadi/now-pages-threadi/master/.github/blueprints/blueprint.json)

### Do you want to use the plugin in your project?

Please install and activate the plugin [from the WordPress repository](https://wordpress.org/plugins/now-pages-threadi).

## AI / Agent Skills

This project ships an [Agent Skill](https://agentskills.io) so coding agents
(Cursor, Claude Code, Codex, …) get the right workflow without guesswork.

| File                              | Audience |
|-----------------------------------|----------|
| `skills/now-pages-threadi-usage/SKILL.md` | … (e.g. site operators / integrators) |

### Install the skill into your agent project

```bash
# from the plugin or package root (adjust path)
mkdir -p .agents/skills
cp -r skills/now-pages-threadi-usage .agents/skills/
```

## Usage for human developers

There is also an `AGENTS.md` in this directory for your vibecoding AI.

After checkout go through the following steps:

### By hand

Run the following commands in this order:

1. `composer install`
2. after that the plugin can be activated in WordPress.

### Using ant

1. copy _build/build.properties.dist_ to _build/build.properties_.
2. modify the _build/build.properties_ file - note the comments in the file.
3. after that the plugin can be activated in WordPress.

### Using Taskfile

1. Run this command: `task prepare`
2. after that the plugin can be activated in WordPress.

## Release

### From local environment by hand

1. `composer install`
2. `composer test-install`
3. `composer test`
4. `vendor/bin/phpstan analyse`
5. `vendor/bin/phpcbf .`
6. `vendor/bin/phpcs .`
7. Set version nummer in _readme.txt_ and _now-pages-threadi.php_.
8. Create the release ZIP with all necessary folders and files.

### From local environment with ant

1. increase the version number in _build/build.properties_.
2. execute the following command in _build/_: `ant build`
3. after that you will find a zip file in the release directory, which could be used in WordPress to install it.

### From local environment with Taskfile

1. execute the following command in the main directory: `task release -- 1.0.0` - adjust the version number.
2. after that you will find a zip file in the release directory, which could be used in WordPress to install it.

### On GitHub

1. Create a new tag with the new version number.
2. The release zip will be created by a GitHub Action.

## Translations

I recommend translating this plugin in the [WordPress Translating tool](https://translate.wordpress.org/projects/wp-plugins/now-pages-threadi/).

For manual translation I recommend to use [PoEdit](https://poedit.net/) to translate texts for this plugin.

### Generate pot-file

Run in the main directory:

`wp i18n make-pot . languages/now-pages-threadi.pot --exclude=svn/`

### Update translation-file

1. Open .po-file of the language in PoEdit.
2. Go to "Translate" > "Update from POT-file".
3. After this the new entries are added to the language-file.

### Export translation-file

1. Open .po-file of the language in PoEdit.
2. Go to "File" > "Save".
3. Upload the generated .mo-file and the .po-file to the plugin-folder languages/

## Check for WordPress Coding Standards

### Initialize

`composer install`

### Run

`vendor/bin/phpcs .`

### Repair

`vendor/bin/phpcbf .`

## Generate documentation

`vendor/bin/wp-documentor parse app --format=markdown --output=docs/hooks.md --prefix=now_pages_threadi_ --exclude=Transients.php`

## Check for WordPress VIP Coding Standards

Hint: this check runs against the VIP-GO-platform, not our target for this plugin. Many warnings can be ignored.

### Run

`vendor/bin/phpcs --extensions=php --ignore=*/vendor/*,*/node_modules/*,*/svn/*,*/tests/* --standard=WordPress-VIP-Go .`

## Check PHP compatibility

`vendor/bin/phpcs -p app --standard=PHPCompatibilityWP`

## Analyze with PHPStan

`vendor/bin/phpstan analyse`

## Check with the plugin "Plugin Check"

`wp plugin check --error-severity=7 --warning-severity=6 --include-low-severity-errors --categories=plugin_repo --format=json --slug=now-pages-threadi .`

## PHP Unit tests

### Initialize the test environment

`composer test-install`

### Run them

`composer test`

## Playwright

See: https://wordpress.github.io/wordpress-playground/guides/e2e-testing-with-playwright/

### Prepare

```
npm install
npx playwright install chromium
```

### Run

`npx playwright test`
