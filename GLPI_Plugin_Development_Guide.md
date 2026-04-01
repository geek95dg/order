# GLPI 11.x Plugin Development Guide

> Practical, consolidated reference for building GLPI 11.x plugins.
> Every pattern, gotcha, and rule here comes from real production experience — not theory.
> Written for AI assistants and developers alike. Update this document as new patterns are discovered.

**Target:** GLPI 11.0–11.99, PHP 8.1+, MySQL 5.7+ / MariaDB 10.3+

---

## Table of Contents

1. [Plugin Structure](#1-plugin-structure)
2. [setup.php — Registration & Hooks](#2-setupphp--registration--hooks)
3. [hook.php — Database Schema & Migration](#3-hookphp--database-schema--migration)
4. [Namespaces & Autoloading](#4-namespaces--autoloading)
5. [Core Base Classes](#5-core-base-classes)
6. [Dropdowns — CommonDropdown](#6-dropdowns--commondropdown)
7. [Forms — showForm() Patterns](#7-forms--showform-patterns)
8. [Search Options — rawSearchOptions()](#8-search-options--rawsearchoptions)
9. [Tabs System](#9-tabs-system)
10. [Front Controllers](#10-front-controllers)
11. [AJAX Endpoints](#11-ajax-endpoints)
12. [Database Access](#12-database-access)
13. [Relations — CommonDBRelation](#13-relations--commondbrelation)
14. [Configuration Pages](#14-configuration-pages)
15. [GLPI Group Hierarchy](#15-glpi-group-hierarchy)
16. [GLPI Hook System](#16-glpi-hook-system)
17. [Permissions & Rights](#17-permissions--rights)
18. [Sessions & Authentication](#18-sessions--authentication)
19. [Translations (i18n)](#19-translations-i18n)
20. [Frontend (JS/CSS)](#20-frontend-jscss)
21. [File & Document Handling](#21-file--document-handling)
22. [PDF Generation](#22-pdf-generation)
23. [Notifications](#23-notifications)
24. [Logging](#24-logging)
25. [Security Checklist](#25-security-checklist)
26. [What Does NOT Work / Forbidden Patterns](#26-what-does-not-work--forbidden-patterns)
27. [Common Gotchas & Known Pitfalls](#27-common-gotchas--known-pitfalls)
28. [Useful Constants & Paths](#28-useful-constants--paths)
29. [Checklists](#29-checklists)

---

## 1. Plugin Structure

GLPI 11.x supports **two class autoloading schemes**. Both work; modern PSR-4 (`src/`) is recommended for new plugins.

### Modern layout (PSR-4 — recommended)

```
myplugin/                          # lowercase, no hyphens, no underscores
├── setup.php                      # REQUIRED — plugin registration, hooks, version
├── hook.php                       # REQUIRED — install / upgrade / uninstall
├── src/                           # PSR-4 classes (GLPI autoloads from here)
│   ├── MyItem.php                 # extends CommonDBTM
│   ├── MyItem_User.php            # extends CommonDBRelation
│   ├── MyCategory.php             # extends CommonDropdown
│   └── MyTab.php                  # extends CommonGLPI (tab-only, no DB table)
├── front/                         # User-facing pages (routing entry points)
│   ├── myitem.php                 # list view (Search::show)
│   ├── myitem.form.php            # form view (create/edit + POST handlers)
│   ├── myitem_user.form.php       # relation POST handler
│   └── config.form.php            # plugin configuration page
├── ajax/                          # AJAX endpoints
│   └── endpoint.php
├── public/                        # Static assets
│   ├── css/
│   └── js/
└── locales/                       # Gettext .po translation files
    ├── en_GB.po
    └── pl_PL.po
```

### Legacy layout (inc/ autoloader)

```
myplugin/
├── setup.php
├── hook.php
├── inc/                           # Classes auto-discovered by filename convention
│   └── feature.class.php         # Class: PluginMypluginFeature
├── front/
├── ajax/
├── public/
└── locales/
```

**No build tools.** GLPI plugins use plain PHP, CSS, and JS. No npm, no Composer, no webpack. Edit files directly.

### Naming Conventions

| Thing | Convention | Example |
|-------|-----------|---------|
| Plugin directory | lowercase alpha only | `myplugin` |
| DB tables | `glpi_plugin_{name}_{tablename}` | `glpi_plugin_myplugin_myitems` |
| Relation tables | `glpi_plugin_{name}_{item1s}_{item2s}` | `glpi_plugin_myplugin_myitems_users` |
| Rights key | `plugin_{name}_{rightname}` | `plugin_myplugin_myitem` |
| PHP namespace (PSR-4) | `GlpiPlugin\{Pluginname}` | `GlpiPlugin\Myplugin` |
| Legacy class name | `Plugin{Name}{Class}` | `PluginMypluginFeature` |
| Legacy file name | `inc/{class}.class.php` | `inc/feature.class.php` |
| Constants | `PLUGIN_{NAME}_VERSION` | `PLUGIN_MYPLUGIN_VERSION` |
| Functions | `plugin_{name}_xxx()` | `plugin_myplugin_install()` |

---

## 2. setup.php — Registration & Hooks

This file is loaded on **every GLPI page load** when the plugin is active. Keep it lightweight.

### Required Functions

```php
<?php

use GlpiPlugin\Myplugin\MyItem;
use GlpiPlugin\Myplugin\MyItem_User;
use GlpiPlugin\Myplugin\MyCategory;

define('PLUGIN_MYPLUGIN_VERSION', '1.0.0');

function plugin_version_myplugin(): array {
    return [
        'name'         => 'My Plugin',
        'version'      => PLUGIN_MYPLUGIN_VERSION,
        'author'       => 'Author Name',
        'license'      => 'GPLv3',
        'homepage'     => 'https://example.com',
        'requirements' => [
            'glpi' => ['min' => '11.0', 'max' => '11.99'],
            'php'  => ['min' => '8.1'],
        ],
    ];
}

function plugin_myplugin_check_prerequisites(): bool {
    return true; // Add version checks if needed
}

function plugin_myplugin_check_config($verbose = false): bool {
    return true; // Validate config state
}
```

### plugin_init Function

```php
function plugin_init_myplugin(): void {
    global $PLUGIN_HOOKS;

    // MANDATORY — plugin won't load without this
    $PLUGIN_HOOKS['csrf_compliant']['myplugin'] = true;

    // Register classes
    Plugin::registerClass(MyItem::class);
    Plugin::registerClass(MyCategory::class);

    // Inject a tab on User detail pages
    Plugin::registerClass(MyItem_User::class, [
        'addtabon' => ['User'],
    ]);

    // Menu entry (appears under Assets, Management, Tools, Admin, or Config)
    if (MyItem::canView()) {
        $PLUGIN_HOOKS['menu_toadd']['myplugin'] = [
            'assets' => MyItem::class,    // or 'management', 'tools', 'admin'
        ];
    }

    // Register dropdowns in Setup > Dropdowns
    $PLUGIN_HOOKS['plugin_dropdowns']['myplugin'] = [
        MyCategory::class,
    ];

    // Config page (adds "Configure" link on Setup > Plugins)
    $PLUGIN_HOOKS['config_page']['myplugin'] = 'front/config.form.php';

    // CSS/JS — always append version to bust cache
    $v = PLUGIN_MYPLUGIN_VERSION;
    $PLUGIN_HOOKS['add_css']['myplugin']        = ["public/css/myplugin.css?v={$v}"];
    $PLUGIN_HOOKS['add_javascript']['myplugin'] = ["public/js/myplugin.js?v={$v}"];

    // Hook registrations (see Section 16 for full details)
    $PLUGIN_HOOKS['item_update']['myplugin'] = [
        'Ticket' => 'plugin_myplugin_ticket_update',
    ];

    // IMPORTANT: Wrap permission checks in try-catch.
    // Tables don't exist during install — any DB query will throw.
    if (Session::getLoginUserID()) {
        try {
            // plugin-specific permission check
        } catch (\Throwable $e) {
            // Silently fail during install/upgrade
        }
    }
}
```

### Key Rules

- `csrf_compliant` **must** be set to `true` or GLPI blocks all POST requests.
- Always append `?v=VERSION` to CSS/JS includes to prevent browser caching stale files.
- Wrap **all** DB-dependent code in `try-catch` — `plugin_init` runs during install when tables don't exist yet.
- Keep `plugin_init` fast — it runs on every page load.
- `Plugin::registerClass()` with `'addtabon'` is how you inject tabs into other item types. Tab-only classes (extending `CommonGLPI`, no DB table) typically only need registration if they inject tabs into **other** item types. For tabs on your own item type, `addStandardTab()` in `defineTabs()` is sufficient.
- Menu categories: `'assets'`, `'management'`, `'tools'`, `'admin'`, `'config'`.

---

## 3. hook.php — Database Schema & Migration

### Install

```php
<?php

use GlpiPlugin\Myplugin\MyItem;

function plugin_myplugin_install(): bool {
    global $DB;

    $charset   = DBConnection::getDefaultCharset();
    $collation = DBConnection::getDefaultCollation();
    $migration = new Migration(PLUGIN_MYPLUGIN_VERSION);

    // Create tables (use IF NOT EXISTS / tableExists for idempotency)
    if (!$DB->tableExists('glpi_plugin_myplugin_myitems')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_myplugin_myitems` (
            `id`            INT unsigned NOT NULL AUTO_INCREMENT,
            `name`          VARCHAR(255) NOT NULL DEFAULT '',
            `comment`       TEXT,
            `status`        VARCHAR(50)  NOT NULL DEFAULT 'active',
            `entities_id`   INT unsigned NOT NULL DEFAULT 0,
            `is_recursive`  TINYINT NOT NULL DEFAULT 0,
            `is_deleted`    TINYINT NOT NULL DEFAULT 0,
            `users_id`      INT unsigned NOT NULL DEFAULT 0,
            `date_mod`      TIMESTAMP NULL DEFAULT NULL,
            `date_creation` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `name` (`name`),
            KEY `entities_id` (`entities_id`),
            KEY `is_deleted` (`is_deleted`),
            KEY `users_id` (`users_id`),
            KEY `status` (`status`),
            KEY `date_mod` (`date_mod`),
            KEY `date_creation` (`date_creation`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset}
          COLLATE={$collation} ROW_FORMAT=DYNAMIC");
    }

    // Insert default config values (if using a config table)
    if ($DB->tableExists('glpi_plugin_myplugin_configs')) {
        $defaults = ['feature_enabled' => '1'];
        foreach ($defaults as $key => $value) {
            $existing = $DB->request([
                'FROM'  => 'glpi_plugin_myplugin_configs',
                'WHERE' => ['config_key' => $key],
                'LIMIT' => 1,
            ]);
            if (count($existing) === 0) {
                $DB->insert('glpi_plugin_myplugin_configs', [
                    'config_key' => $key,
                    'value'      => $value,
                ]);
            }
        }
    }

    // Provision rights via GLPI's native rights system
    ProfileRight::addProfileRights([MyItem::$rightname]);

    // Grant Super-Admin full rights
    $profile = new Profile();
    foreach ($profile->find(['interface' => 'central']) as $data) {
        if ($data['name'] === 'Super-Admin') {
            $profileRight = new ProfileRight();
            $profileRight->updateProfileRights($data['id'], [
                MyItem::$rightname => ALLSTANDARDRIGHT,
            ]);
        }
    }

    $migration->executeMigration();
    return true;
}
```

### Upgrade

```php
function plugin_myplugin_upgrade(string $fromVersion): bool {
    global $DB;

    $migration = new Migration(PLUGIN_MYPLUGIN_VERSION);

    // Guard each migration by checking actual DB state — never compare version strings
    if (!$DB->fieldExists('glpi_plugin_myplugin_myitems', 'new_column')) {
        $migration->addField(
            'glpi_plugin_myplugin_myitems',
            'new_column',
            'string',             // GLPI type: 'integer', 'string', 'text', 'bool', etc.
            ['value' => '']
        );
        $migration->addKey('glpi_plugin_myplugin_myitems', 'new_column');
    }

    // For new config keys
    if ($DB->tableExists('glpi_plugin_myplugin_configs')) {
        $newConfigs = ['new_setting' => 'default'];
        foreach ($newConfigs as $key => $value) {
            $exists = $DB->request([
                'FROM'  => 'glpi_plugin_myplugin_configs',
                'WHERE' => ['config_key' => $key],
                'LIMIT' => 1,
            ]);
            if (count($exists) === 0) {
                $DB->insert('glpi_plugin_myplugin_configs', [
                    'config_key' => $key,
                    'value'      => $value,
                ]);
            }
        }
    }

    $migration->executeMigration();
    return true;
}
```

### Uninstall

```php
function plugin_myplugin_uninstall(): bool {
    global $DB;

    // Drop in reverse dependency order (children first)
    $tables = [
        'glpi_plugin_myplugin_myitems_users',
        'glpi_plugin_myplugin_myitems',
        'glpi_plugin_myplugin_configs',
    ];

    foreach ($tables as $table) {
        if ($DB->tableExists($table)) {
            $DB->doQuery("DROP TABLE `{$table}`");
        }
    }

    // Clean up rights
    ProfileRight::deleteProfileRights([MyItem::$rightname]);

    return true;
}
```

### Migration Field Types

| GLPI Type | SQL Result |
|-----------|-----------|
| `'integer'` | `INT unsigned NOT NULL DEFAULT {value}` |
| `'string'` | `VARCHAR(255)` |
| `'text'` | `TEXT` |
| `'bool'` | `TINYINT NOT NULL DEFAULT {value}` |
| `'decimal(20,4)'` | `DECIMAL(20,4)` |

### Required Columns by Feature

| Feature | Required Columns |
|---------|-----------------|
| Entity support | `entities_id` INT unsigned, `is_recursive` TINYINT |
| Soft delete / Trash | `is_deleted` TINYINT |
| History / Log tab | `date_mod` TIMESTAMP |
| Timestamps | `date_mod` TIMESTAMP, `date_creation` TIMESTAMP |

### Key Rules

- Always use `IF NOT EXISTS` / `$DB->tableExists()` / `$DB->fieldExists()` — install and upgrade must be **idempotent** (safe to run multiple times).
- Use `DBConnection::getDefaultCharset()` / `getDefaultCollation()` instead of hardcoding charset.
- Never use version string comparisons for migrations — check the actual DB state.
- Add indexes on foreign key columns and frequently filtered columns.
- Call `$migration->executeMigration()` at the end of install and upgrade.
- Provision rights with `ProfileRight::addProfileRights()` during install.
- Clean up rights with `ProfileRight::deleteProfileRights()` during uninstall.
- Drop tables in reverse dependency order during uninstall (children first).
- Bump the version constant whenever the schema changes, or migration code won't run.

---

## 4. Namespaces & Autoloading

GLPI 11.x supports two autoloading mechanisms. **Both work simultaneously**, but PSR-4 is recommended for new plugins.

### PSR-4 (modern — recommended)

Classes live in `src/` under the `GlpiPlugin\{Pluginname}` namespace:

| Class | File |
|-------|------|
| `GlpiPlugin\Myplugin\MyItem` | `src/MyItem.php` |
| `GlpiPlugin\Myplugin\MyItem_User` | `src/MyItem_User.php` |
| `GlpiPlugin\Myplugin\MyCategory` | `src/MyCategory.php` |

```php
<?php
namespace GlpiPlugin\Myplugin;

use CommonDBTM;
use Session;
use Html;

class MyItem extends CommonDBTM {
    // ...
}
```

### CRITICAL: `use` statements in namespaced files

Every GLPI core class you reference **must** be imported with `use`. Without it, PHP looks for `GlpiPlugin\Myplugin\Dropdown` which doesn't exist — causing a **fatal error**.

```php
namespace GlpiPlugin\Myplugin;

use CommonDBTM;
use CommonDBRelation;
use CommonDropdown;
use CommonGLPI;
use CommonITILObject;
use DBConnection;
use Dropdown;          // MUST import — won't resolve without it!
use Entity;
use Group;
use Html;
use Manufacturer;
use Migration;
use NotificationTarget;
use Plugin;
use Profile;
use ProfileRight;
use Search;
use Session;
use User;
```

**PITFALL**: A missing `use` in a namespaced file causes a fatal error that is especially hard to debug when it happens inside AJAX-loaded tabs — you just see a blank tab or 500 error in the browser console.

### Global functions

GLPI global functions like `__()`, `_n()`, `_x()`, `sprintf()`, `countElementsInTable()`, `formatUserName()` are in the global namespace and work **without** `use` statements.

### Legacy autoloader (inc/)

Classes live in `inc/` with a strict filename convention — no namespace needed:

| Class Name | File |
|-----------|------|
| `PluginMypluginFeature` | `inc/feature.class.php` |
| `PluginMypluginProfile` | `inc/profile.class.php` |
| `PluginMypluginMenu` | `inc/menu.class.php` |

GLPI's autoloader matches these patterns exactly. **Any deviation breaks auto-discovery.**

```php
<?php
// inc/feature.class.php — no namespace, no use statements needed

class PluginMypluginFeature extends CommonDBTM {
    // Core classes resolve directly in global namespace
}
```

---

## 5. Core Base Classes

| Base Class | Use When | Has DB Table | Key Features |
|-----------|----------|-------------|-------------|
| `CommonDBTM` | Main item types | Yes | Forms, search, CRUD, entity support, history |
| `CommonDropdown` | Simple categorization lists | Yes | Dropdown lists, tree support via `CommonTreeDropdown` |
| `CommonDBRelation` | Many-to-many links | Yes | Manages relation table, dual-side logging |
| `CommonGLPI` | Tab-only UI logic | No | Tab rendering, no database persistence |

### CommonDBTM — Main Item Class

```php
namespace GlpiPlugin\Myplugin;

use CommonDBTM;

class MyItem extends CommonDBTM {
    // Enable features
    public $dohistory        = true;    // enables Log tab (requires date_mod column)
    public $usehaveright     = true;    // uses rights system
    public $can_be_recursive = true;    // entity recursion (requires entities_id, is_recursive columns)
    public $maybeDeleted     = true;    // soft delete / trash bin (requires is_deleted column)

    // Right name — must match what you register with ProfileRight::addProfileRights()
    static $rightname = 'plugin_myplugin_myitem';

    static function getTypeName($nb = 0) {
        return _n('My Item', 'My Items', $nb, 'myplugin');
    }
}
```

**Table name** auto-resolves from the class name:
- PSR-4: `GlpiPlugin\Myplugin\MyItem` → `glpi_plugin_myplugin_myitems`
- Legacy: `PluginMypluginMyitem` → `glpi_plugin_myplugin_myitems`

### CommonGLPI — Tab-Only Class (No DB Table)

Use for menu registration or adding UI tabs without a backing database table:

```php
namespace GlpiPlugin\Myplugin;

use CommonGLPI;
use Session;

class Menu extends CommonGLPI {
    static function getTypeName($nb = 0) {
        return __('My Plugin', 'myplugin');
    }

    static function getMenuName() {
        return __('My Plugin', 'myplugin');
    }

    static function getMenuContent() {
        $menu = [
            'title' => self::getMenuName(),
            'page'  => '/plugins/myplugin/front/myitem.php',
            'icon'  => 'fas fa-box',
        ];
        if (Session::haveRight('config', READ)) {
            $menu['options']['config'] = [
                'title' => __('Configuration', 'myplugin'),
                'page'  => '/plugins/myplugin/front/config.form.php',
                'icon'  => 'fas fa-cog',
            ];
        }
        return $menu;
    }
}
```

### Adding a Tab to an Existing GLPI Item

Any class can provide tabs on other GLPI items. Register it in `setup.php`:

```php
Plugin::registerClass(MyItem_User::class, ['addtabon' => ['User']]);
```

Then implement the tab interface:

```php
public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0) {
    if ($item instanceof \User) {
        $count = countElementsInTable(self::getTable(), [
            'users_id' => $item->getID(),
        ]);
        return self::createTabEntry(__('My Items', 'myplugin'), $count);
    }
    return '';
}

static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0) {
    if ($item instanceof \User) {
        self::showForUser($item);
        return true;
    }
    return false;
}
```

---

## 6. Dropdowns — CommonDropdown

For simple categorization / lookup lists:

```php
namespace GlpiPlugin\Myplugin;

use CommonDropdown;

class MyCategory extends CommonDropdown {
    public $can_be_recursive = true;

    static function getTypeName($nb = 0) {
        return _n('My Category', 'My Categories', $nb, 'myplugin');
    }
}
```

Register in `setup.php`:

```php
Plugin::registerClass(MyCategory::class);
$PLUGIN_HOOKS['plugin_dropdowns']['myplugin'] = [MyCategory::class];
```

Table name auto-resolves to `glpi_plugin_myplugin_mycategories`.

For **hierarchical / tree dropdowns**, extend `CommonTreeDropdown` instead — this adds parent-child relationships with `completename` (full path like `Root > Child > Grandchild`).

---

## 7. Forms — showForm() Patterns

GLPI supports two form rendering approaches. **Legacy table-based is recommended for plugins** — simpler, well-tested, and stable across GLPI versions.

### Basic showForm() Structure

```php
function showForm($ID, array $options = []) {
    $this->initForm($ID, $options);       // loads $this->fields from DB
    $this->showFormHeader($options);       // opens <form>, renders header

    // Each row = <tr> with 4 <td> cells (label, field, label, field)
    echo "<tr class='tab_bg_1'>";
    echo "<td><label for='name'>" . __('Name') . "</label></td>";
    echo "<td>";
    echo Html::input('name', ['id' => 'name', 'value' => $this->fields['name'] ?? '']);
    echo "</td>";
    echo "<td><label for='comment'>" . __('Comments') . "</label></td>";
    echo "<td>";
    echo "<textarea name='comment' id='comment' cols='45' rows='3'>"
        . htmlspecialchars($this->fields['comment'] ?? '') . "</textarea>";
    echo "</td>";
    echo "</tr>";

    $this->showFormButtons($options);     // renders Save/Add/Delete buttons + closes </form>
    return true;
}
```

**CRITICAL**: `initForm()` must be called before accessing `$this->fields`. `showFormButtons()` must close the form — without it, no Save/Add button is rendered.

### Form Field Types

```php
// Text input
echo Html::input('fieldname', ['value' => $this->fields['fieldname'] ?? '', 'size' => 40]);

// Textarea
echo "<textarea name='comment' cols='45' rows='3'>"
    . htmlspecialchars($this->fields['comment'] ?? '') . "</textarea>";

// GLPI dropdown (FK to standard GLPI type)
Dropdown::show(Manufacturer::class, [
    'name'   => 'manufacturers_id',
    'value'  => $this->fields['manufacturers_id'] ?? 0,
    'entity' => $this->getEntityID(),
]);

// Plugin dropdown (FK to plugin's own dropdown type)
Dropdown::show(MyCategory::class, [
    'name'   => 'plugin_myplugin_mycategories_id',
    'value'  => $this->fields['plugin_myplugin_mycategories_id'] ?? 0,
    'entity' => $this->getEntityID(),
]);

// User dropdown
User::dropdown([
    'name'   => 'users_id_owner',
    'value'  => $this->fields['users_id_owner'] ?? 0,
    'right'  => 'all',
    'entity' => $this->getEntityID(),
]);

// Group dropdown
Group::dropdown([
    'name'   => 'groups_id_department',
    'value'  => $this->fields['groups_id_department'] ?? 0,
    'entity' => $this->getEntityID(),
]);

// Yes/No dropdown
Dropdown::showYesNo('is_paid', $this->fields['is_paid'] ?? 0);

// Static array dropdown
Dropdown::showFromArray('is_external', [
    0 => __('Internal'),
    1 => __('External'),
], ['value' => $this->fields['is_external'] ?? 0]);

// Number input
echo Html::input('price', [
    'id'    => 'price',
    'value' => $this->fields['price'] ?? '',
    'type'  => 'number',
    'step'  => '0.01',
    'min'   => '0',
]);
```

### Inline JavaScript in Forms

```php
echo Html::scriptBlock("
    function myPluginToggle(val) {
        document.getElementById('my_element').style.display = val == 1 ? '' : 'none';
    }
");
```

**WARNING about `on_change` with Select2**: GLPI renders most dropdowns using Select2. The `on_change` parameter works with `Dropdown::showFromArray()` and `Dropdown::showYesNo()`, but the event fires with Select2's value. Use `'on_change' => 'myFunction(this.value)'`.

### Legend / Info Row on Forms

```php
echo "<tr class='tab_bg_2'>";
echo "<th colspan='4'>";
echo "<i class='fas fa-info-circle'></i>&nbsp;&nbsp;";
echo __('Your descriptive text here', 'myplugin');
echo "</th>";
echo "</tr>";
```

---

## 8. Search Options — rawSearchOptions()

Search options define columns visible in list views, searchable fields, and export data.

```php
function rawSearchOptions() {
    $tab = [];

    // Section header (required as first entry)
    $tab[] = [
        'id'   => 'common',
        'name' => self::getTypeName(2),
    ];

    // Name (clickable link to item)
    $tab[] = [
        'id'            => 1,
        'table'         => self::getTable(),
        'field'         => 'name',
        'name'          => __('Name'),
        'datatype'      => 'itemlink',       // makes it a clickable link
        'searchtype'    => ['contains'],
        'massiveaction' => false,
    ];

    // Simple text field
    $tab[] = [
        'id'       => 3,
        'table'    => self::getTable(),
        'field'    => 'comment',
        'name'     => __('Comments'),
        'datatype' => 'text',
    ];

    // Boolean field
    $tab[] = [
        'id'       => 5,
        'table'    => self::getTable(),
        'field'    => 'is_recursive',
        'name'     => __('Child entities'),
        'datatype' => 'bool',
    ];

    // Entity (standard pattern — GLPI auto-joins)
    $tab[] = [
        'id'       => 4,
        'table'    => 'glpi_entities',
        'field'    => 'completename',
        'name'     => Entity::getTypeName(1),
        'datatype' => 'dropdown',
    ];

    // FK to standard GLPI type (standard column name like manufacturers_id)
    $tab[] = [
        'id'       => 9,
        'table'    => 'glpi_manufacturers',
        'field'    => 'name',
        'name'     => Manufacturer::getTypeName(1),
        'datatype' => 'dropdown',
    ];

    // FK with NON-STANDARD column name (e.g., users_id_owner instead of users_id)
    $tab[] = [
        'id'        => 14,
        'table'     => 'glpi_users',
        'field'     => 'name',
        'name'      => __('Owner'),
        'datatype'  => 'dropdown',
        'linkfield' => 'users_id_owner',     // <-- REQUIRED for non-standard FK names
    ];

    // FK to Group with non-standard column
    $tab[] = [
        'id'        => 16,
        'table'     => 'glpi_groups',
        'field'     => 'completename',        // use 'completename' for tree dropdowns
        'name'      => __('Department'),
        'datatype'  => 'dropdown',
        'linkfield' => 'groups_id_department',
    ];

    // Join through a relation table (e.g., find items by assigned user)
    $tab[] = [
        'id'            => 8,
        'table'         => 'glpi_users',
        'field'         => 'name',
        'name'          => __('User'),
        'datatype'      => 'dropdown',
        'forcegroupby'  => true,             // groups results (one item can have many users)
        'massiveaction' => false,
        'joinparams'    => [
            'beforejoin' => [
                'table'      => 'glpi_plugin_myplugin_myitems_users',
                'joinparams' => [
                    'jointype' => 'child',   // relation table is a "child" of main table
                ],
            ],
        ],
    ];

    // URL / weblink
    $tab[] = [
        'id'       => 11,
        'table'    => self::getTable(),
        'field'    => 'portal_url',
        'name'     => __('Portal URL'),
        'datatype' => 'weblink',
    ];

    // Decimal
    $tab[] = [
        'id'       => 13,
        'table'    => self::getTable(),
        'field'    => 'price',
        'name'     => __('Price'),
        'datatype' => 'decimal',
    ];

    return $tab;
}
```

### CRITICAL: `linkfield` vs `joinparams`

- **Standard FK** (column name matches GLPI convention like `manufacturers_id`): No extra params needed — GLPI auto-detects the join.
- **Non-standard FK** (column like `users_id_owner`, `groups_id_department`): **Must use `'linkfield' => 'column_name'`** to tell GLPI which column to join on.
- **Through a relation table**: Use `joinparams` with `beforejoin`.

**DO NOT USE** `'field_fkey'` — this is NOT a valid GLPI search option parameter. It will be silently ignored and the search will break.

### Available Datatypes

`itemlink`, `string`, `text`, `number`, `integer`, `decimal`, `bool`, `datetime`, `date`, `dropdown`, `weblink`, `email`, `specific`

---

## 9. Tabs System

### Adding tabs to your own item type

In your `CommonDBTM` class:

```php
function defineTabs($options = []) {
    $tabs = [];
    $this->addDefaultFormTab($tabs);                            // main form
    $this->addStandardTab(MyItem_User::class, $tabs, $options); // custom tab
    $this->addStandardTab('Log', $tabs, $options);              // history
    $this->addStandardTab('Notepad', $tabs, $options);          // notes
    return $tabs;
}
```

### Adding tabs to OTHER item types (e.g., User pages)

In `setup.php`:

```php
Plugin::registerClass(MyItem_User::class, [
    'addtabon' => ['User'],
]);
```

### Tab provider class

```php
function getTabNameForItem(CommonGLPI $item, $withtemplate = 0) {
    if ($item instanceof MyItem) {
        $count = countElementsInTable(self::getTable(), [
            'plugin_myplugin_myitems_id' => $item->getID(),
        ]);
        return self::createTabEntry(__('Users', 'myplugin'), $count);
    }
    return '';
}

static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0) {
    if ($item instanceof MyItem) {
        self::showForMyItem($item);
        return true;
    }
    return false;
}
```

### CRITICAL: Tabs are loaded via AJAX

Tab content is loaded via AJAX through `/ajax/common.tabs.php`. If your tab class has a PHP error (e.g., missing `use` import), the AJAX request returns a 500 error and the tab shows a blank/error page in the browser.

**Debug tip**: Check the browser's Network tab for the failing AJAX request to `common.tabs.php` and look at the response body for the PHP error message.

---

## 10. Front Controllers

Files in `front/` are the entry points for user-facing pages.

### List Page (front/myitem.php)

```php
<?php
use GlpiPlugin\Myplugin\MyItem;

include(__DIR__ . '/../../../inc/includes.php');

Session::checkRight(MyItem::$rightname, READ);

Html::header(
    MyItem::getTypeName(Session::getPluralNumber()),
    $_SERVER['PHP_SELF'],
    'assets',           // menu section
    MyItem::class        // active menu item
);

Search::show(MyItem::class);  // auto-renders search + list + export buttons (CSV, PDF, SYLK)

Html::footer();
```

`Search::show()` automatically provides export buttons. No extra code needed — it uses your `rawSearchOptions()`.

### Form Page (front/myitem.form.php)

```php
<?php
use GlpiPlugin\Myplugin\MyItem;

include(__DIR__ . '/../../../inc/includes.php');
Session::checkLoginUser();

/**
 * CRITICAL: Do NOT call Session::checkCRSF() here!
 *
 * GLPI's inc/includes.php automatically validates and CONSUMES the CSRF
 * token for ALL POST requests to /front/ URLs. The token is stored in
 * $_SESSION['glpicsrftokens'] and removed after validation.
 * Calling checkCRSF() again will FAIL because the token pool is empty.
 */

// Handle custom POST actions from tabs BEFORE the standard CRUD chain
if (isset($_POST['_my_custom_action'])) {
    // do something
    Html::back();
}

$item = new MyItem();

if (isset($_POST['add'])) {
    $item->check(-1, CREATE, $_POST);
    $item->add($_POST);
    Html::back();
} else if (isset($_POST['update'])) {
    $item->check($_POST['id'], UPDATE);
    $item->update($_POST);
    Html::back();
} else if (isset($_POST['delete'])) {
    $item->check($_POST['id'], DELETE);
    $item->delete($_POST);
    $item->redirectToList();
} else if (isset($_POST['restore'])) {
    $item->check($_POST['id'], DELETE);
    $item->restore($_POST);
    Html::back();
} else if (isset($_POST['purge'])) {
    $item->check($_POST['id'], PURGE);
    $item->delete($_POST, 1);
    $item->redirectToList();
} else {
    $menus = ['assets', MyItem::class];
    MyItem::displayFullPageForItem($_GET['id'] ?? 0, $menus, [
        'formoptions' => "data-track-changes=true",
    ]);
}
```

### CRITICAL RULE: Never Call Session::checkCRSF() in /front/ Files

GLPI's bootstrap (`inc/includes.php`) automatically validates CSRF tokens for all POST requests routed through `/front/`. Calling `Session::checkCRSF()` a second time causes a failure because the token has already been consumed from the session token pool (`$_SESSION['glpicsrftokens']`).

---

## 11. AJAX Endpoints

```php
<?php
// ajax/getData.php
include_once(__DIR__ . '/../../../inc/includes.php');

header('Content-Type: application/json; charset=utf-8');

// Authentication check (no CSRF needed for AJAX)
if (!Session::getLoginUserID()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    return;
}

try {
    $param = $_GET['param'] ?? '';
    $result = PluginMypluginFeature::getData($param);
    echo json_encode(['success' => true, 'data' => $result]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
```

### Key Rules

- AJAX endpoints do **not** need CSRF validation — session authentication is sufficient.
- Always set `Content-Type: application/json; charset=utf-8`.
- Always check `Session::getLoginUserID()` and return 403 if not authenticated.
- Wrap in try-catch to prevent PHP errors from corrupting JSON output.

---

## 12. Database Access

GLPI provides a `$DB` global object. **Never use raw `mysqli_*` calls.**

### Read (SELECT)

```php
global $DB;

// Simple query
$rows = $DB->request([
    'FROM'  => 'glpi_plugin_myplugin_myitems',
    'WHERE' => ['status' => 'active', 'users_id' => $userId],
    'ORDER' => 'name ASC',
    'LIMIT' => 50,
]);
foreach ($rows as $row) {
    $id   = $row['id'];
    $name = $row['name'];
}

// With JOIN
$rows = $DB->request([
    'SELECT' => [
        'glpi_plugin_myplugin_myitems.*',
        'glpi_users.name AS username',
    ],
    'FROM'   => 'glpi_plugin_myplugin_myitems',
    'LEFT JOIN' => [
        'glpi_users' => [
            'FKEY' => [
                'glpi_plugin_myplugin_myitems' => 'users_id_owner',
                'glpi_users'                   => 'id',
            ],
        ],
    ],
    'WHERE' => [
        'glpi_plugin_myplugin_myitems.is_deleted' => 0,
    ],
    'ORDER' => ['glpi_plugin_myplugin_myitems.name ASC'],
]);

// LIKE search (escape user input)
$pattern = '%' . $DB->escape($searchTerm) . '%';
$rows = $DB->request([
    'FROM'  => 'glpi_plugin_myplugin_myitems',
    'WHERE' => [
        'OR' => [
            ['name'   => ['LIKE', $pattern]],
            ['serial' => ['LIKE', $pattern]],
        ],
    ],
]);

// IN clause
$rows = $DB->request([
    'FROM'  => 'glpi_plugin_myplugin_myitems',
    'WHERE' => ['id' => [1, 2, 3, 4]],  // Generates IN (1,2,3,4)
]);
```

### Count

```php
// Using GLPI helper
$count = countElementsInTable('glpi_plugin_myplugin_myitems', [
    'users_id' => $userId,
]);

// Using $DB->request
$count = count($DB->request([...]));

// Aggregate (MAX, COUNT, etc.)
$row = $DB->request([
    'SELECT' => ['MAX' => 'level AS max_level'],
    'FROM'   => 'glpi_groups',
])->current();
$maxLevel = (int)$row['max_level'];
```

### Insert

```php
$DB->insert('glpi_plugin_myplugin_myitems', [
    'name'          => $name,
    'status'        => 'active',
    'users_id'      => (int)$userId,
    'date_creation' => date('Y-m-d H:i:s'),
    'date_mod'      => date('Y-m-d H:i:s'),
]);
$newId = $DB->insertId();
```

### Update

```php
$DB->update(
    'glpi_plugin_myplugin_myitems',
    ['status' => 'completed', 'date_mod' => date('Y-m-d H:i:s')],  // SET
    ['id' => $itemId]  // WHERE
);
```

### Update or Insert (Upsert)

```php
$DB->updateOrInsert(
    'glpi_plugin_myplugin_configs',
    ['setting' => $value],    // data to set
    ['id' => 1]               // WHERE clause
);
```

### Delete

```php
$DB->delete('glpi_plugin_myplugin_myitems', ['id' => $itemId]);
```

### Schema Checks

```php
$DB->tableExists('glpi_plugin_myplugin_myitems');                   // true/false
$DB->fieldExists('glpi_plugin_myplugin_myitems', 'new_column');     // true/false
```

### Key Rules

- All values passed in arrays are auto-escaped — no manual escaping needed for insert/update/where.
- Use `$DB->escape()` only for LIKE patterns or raw `doQuery()` calls.
- Always cast integer IDs: `(int)$userId`.
- `$DB->request()` returns an iterator — use `foreach` or `count()`.
- For raw SQL (migrations only): `$DB->doQuery("ALTER TABLE ...")`.

---

## 13. Relations — CommonDBRelation

For many-to-many links between items (e.g., linking Users to your plugin items).

### Class Setup

```php
namespace GlpiPlugin\Myplugin;

use CommonDBRelation;

class MyItem_User extends CommonDBRelation {
    static public $itemtype_1 = MyItem::class;
    static public $items_id_1 = 'plugin_myplugin_myitems_id';  // FK column name

    static public $itemtype_2 = 'User';
    static public $items_id_2 = 'users_id';                     // FK column name

    // Rights: check rights on side 1 only
    static public $checkItem_1_Rights = self::HAVE_SAME_RIGHT_ON_ITEM;
    static public $checkItem_2_Rights = self::DONT_CHECK_ITEM_RIGHTS;

    // Log history on both sides
    static public $logs_for_item_1 = true;
    static public $logs_for_item_2 = true;
}
```

### Form URL Override

CommonDBRelation needs a custom `getFormURL()` pointing to the front-end handler:

```php
static function getFormURL($full = true) {
    return Plugin::getWebDir('myplugin', $full) . '/front/myitem_user.form.php';
}
```

### Front-End Handler File (REQUIRED)

You **must** create `front/myitem_user.form.php` — without it, add/delete buttons on the relation tab will 404:

```php
<?php
use GlpiPlugin\Myplugin\MyItem_User;

include(__DIR__ . '/../../../inc/includes.php');
Session::checkLoginUser();

$link = new MyItem_User();

if (isset($_POST['add'])) {
    $link->check(-1, CREATE, $_POST);
    $link->add($_POST);
    Html::back();
} else if (isset($_POST['purge'])) {
    $link->check($_POST['id'], PURGE);
    $link->delete($_POST, 1);  // 1 = force purge
    Html::back();
}
```

### Delete/Purge Buttons

```php
// CORRECT — plain text label
echo Html::submit(__('Delete'), [
    'name'    => 'purge',
    'class'   => 'btn btn-danger btn-sm',
    'confirm' => __('Confirm the final deletion?'),
]);

// WRONG — Html::submit() escapes HTML, so icons show as raw text
echo Html::submit("<i class='fas fa-trash-alt'></i>", [...]);
// Result: user sees literal "<i class='fas fa-trash-alt'></i>"
```

**RULE**: Never pass raw HTML to `Html::submit()` — it HTML-escapes the label. Use plain text like `__('Delete')` or `__('Remove')`.

---

## 14. Configuration Pages

### Registration in setup.php

```php
$PLUGIN_HOOKS['config_page']['myplugin'] = 'front/config.form.php';
```

This adds a **"Configure"** button on the Setup > Plugins page next to your plugin.

### Config Form Pattern (front/config.form.php)

```php
<?php
use GlpiPlugin\Myplugin\Config;

include(__DIR__ . '/../../../inc/includes.php');

Session::checkRight('config', UPDATE);  // only admins

$config = new Config();

if (isset($_POST['update'])) {
    $config->update($_POST);
    Html::back();
}

Html::header(__('My Plugin Configuration', 'myplugin'), $_SERVER['PHP_SELF'], 'config');

$config->showConfigForm();

Html::footer();
```

### Singleton Config Table Pattern

```sql
CREATE TABLE `glpi_plugin_myplugin_configs` (
    `id`           INT unsigned NOT NULL AUTO_INCREMENT,
    `setting_one`  INT NOT NULL DEFAULT 1,
    `setting_two`  TEXT,
    PRIMARY KEY (`id`)
);
INSERT INTO `glpi_plugin_myplugin_configs` (id, setting_one) VALUES (1, 1);
```

Read config:

```php
$row = $DB->request([
    'FROM'  => 'glpi_plugin_myplugin_configs',
    'WHERE' => ['id' => 1],
    'LIMIT' => 1,
])->current();
```

Write config:

```php
$DB->updateOrInsert(
    'glpi_plugin_myplugin_configs',
    ['setting_one' => $newValue],
    ['id' => 1]
);
```

---

## 15. GLPI Group Hierarchy

GLPI groups (`glpi_groups`) are a tree structure (CommonTreeDropdown):

| Column | Purpose |
|--------|---------|
| `id` | Primary key |
| `name` | Short name |
| `completename` | Full path (`Root > Child > Grandchild`) |
| `level` | Depth in tree (1 = root) |
| `groups_id` | Parent group ID (0 = root) |
| `entities_id` | Entity scope |
| `ancestors_cache` | JSON cache of ancestor IDs |
| `sons_cache` | JSON cache of descendant IDs |

User-group membership is in `glpi_groups_users`:

| Column | Purpose |
|--------|---------|
| `users_id` | FK to user |
| `groups_id` | FK to group |
| `is_dynamic` | Synced from LDAP/AD |
| `is_manager` | User is group manager |
| `is_userdelegate` | User is delegate |

### Walking the Tree

To find an ancestor at a specific level, walk up via `groups_id` (parent FK):

```php
$currentId = $groupId;
while ($cache[$currentId]['level'] > $targetLevel) {
    $currentId = $cache[$currentId]['groups_id'];  // go to parent
}
```

### Querying Max Depth

```php
$maxRow = $DB->request([
    'SELECT' => ['MAX' => 'level AS max_level'],
    'FROM'   => 'glpi_groups',
])->current();
$maxLevel = (int)$maxRow['max_level'];
```

---

## 16. GLPI Hook System

Hooks let your plugin react to events on GLPI items (tickets, users, computers, etc.).

### Registration (in setup.php)

```php
// Post-action hooks (fired AFTER the DB write)
$PLUGIN_HOOKS['item_update']['myplugin'] = ['Ticket' => 'PluginMypluginHook::afterTicketUpdate'];
$PLUGIN_HOOKS['item_add']['myplugin']    = ['Ticket' => 'PluginMypluginHook::afterTicketAdd'];

// Pre-action hooks (fired BEFORE the DB write — can block the operation)
$PLUGIN_HOOKS['pre_item_update']['myplugin'] = ['Ticket' => 'PluginMypluginHook::beforeTicketUpdate'];
$PLUGIN_HOOKS['pre_item_add']['myplugin']    = ['ITILSolution' => 'PluginMypluginHook::beforeSolutionAdd'];
```

### Hook Execution Order

```
1. pre_item_add / pre_item_update    ← Can block the operation
2. Database write happens
3. item_add / item_update            ← Post-action, informational only
```

### The fields[] vs input[] Rule (CRITICAL)

This is the single most important thing to understand about GLPI hooks:

```php
public static function afterTicketUpdate(Ticket $ticket): void {
    // $ticket->fields  = OLD values (current DB state BEFORE update)
    // $ticket->input   = NEW values (what is being written)

    $oldStatus = (int)($ticket->fields['status'] ?? 0);
    $newStatus = (int)($ticket->input['status'] ?? 0);

    if ($newStatus !== $oldStatus && $newStatus === CommonITILObject::CLOSED) {
        // Ticket was just closed — react to it
    }
}
```

| Context | `$item->fields` | `$item->input` |
|---------|-----------------|----------------|
| `pre_item_update` | Old DB values | New values being applied |
| `item_update` | Old DB values | New values just applied |
| `pre_item_add` | Empty/unset | Values being inserted |
| `item_add` | Values just inserted | Values just inserted |

### Blocking Operations in pre_ Hooks

```php
public static function beforeTicketUpdate(Ticket $ticket): void {
    if ($shouldBlockStatusChange) {
        // Remove the field from input to prevent it from being saved
        unset($ticket->input['status']);
        Session::addMessageAfterRedirect(__('Cannot close this ticket yet.', 'myplugin'), true, ERROR);
    }
}

public static function beforeSolutionAdd(ITILSolution $solution): void {
    if ($shouldBlockSolution) {
        // Set input to false to completely prevent the add
        $solution->input = false;
        Session::addMessageAfterRedirect(__('Add a follow-up first.', 'myplugin'), true, ERROR);
    }
}
```

### Ticket Status Detection — Cover All Paths

Ticket status changes can happen through multiple paths. Register hooks for all of them:

```php
// Direct status field update
$PLUGIN_HOOKS['pre_item_update']['myplugin'] = ['Ticket' => 'PluginMypluginHook::beforeTicketUpdate'];
$PLUGIN_HOOKS['item_update']['myplugin']     = ['Ticket' => 'PluginMypluginHook::afterTicketUpdate'];

// Adding a solution (which changes status to SOLVED)
$PLUGIN_HOOKS['pre_item_add']['myplugin']    = ['ITILSolution' => 'PluginMypluginHook::beforeSolutionAdd'];
$PLUGIN_HOOKS['item_add']['myplugin']        = ['ITILSolution' => 'PluginMypluginHook::afterSolutionAdd'];
```

A user can close a ticket via:
- Changing the status field directly → `pre_item_update` on Ticket
- Adding an ITILSolution → `pre_item_add` on ITILSolution
- Approving a pending solution → `pre_item_update` on Ticket

You need hooks on **all paths** to reliably block premature closure.

### Available Hook Names

| Hook | Fires | Can Block? |
|------|-------|-----------|
| `pre_item_add` | Before DB insert | Yes (`$item->input = false`) |
| `item_add` | After DB insert | No |
| `pre_item_update` | Before DB update | Yes (`unset($item->input['field'])`) |
| `item_update` | After DB update | No |
| `pre_item_purge` | Before permanent delete | Yes |
| `item_purge` | After permanent delete | No |
| `pre_item_delete` | Before soft delete (trash) | Yes |
| `item_delete` | After soft delete | No |

### Ticket Status Constants

```php
CommonITILObject::INCOMING    = 1
CommonITILObject::ASSIGNED    = 2
CommonITILObject::PLANNED     = 3
CommonITILObject::WAITING     = 4
CommonITILObject::SOLVED      = 5
CommonITILObject::CLOSED      = 6
```

**Always compare as integers**: `(int)$ticket->fields['status'] === CommonITILObject::CLOSED`. Never use string comparison like `=== 'closed'`.

---

## 17. Permissions & Rights

### Rights Constants

```php
READ               = 1
CREATE             = 2
UPDATE             = 4
DELETE             = 8
PURGE              = 16
ALLSTANDARDRIGHT   = 31   // READ + CREATE + UPDATE + DELETE + PURGE
```

### Native GLPI Rights System (recommended for GLPI 11.x)

Use `ProfileRight` to register and manage rights through GLPI's built-in system:

```php
// Install-time: register the right key and grant to Super-Admin
ProfileRight::addProfileRights(['plugin_myplugin_myitem']);

$profile = new Profile();
foreach ($profile->find(['interface' => 'central']) as $data) {
    if ($data['name'] === 'Super-Admin') {
        $profileRight = new ProfileRight();
        $profileRight->updateProfileRights($data['id'], [
            'plugin_myplugin_myitem' => ALLSTANDARDRIGHT,
        ]);
    }
}

// Uninstall-time: clean up
ProfileRight::deleteProfileRights(['plugin_myplugin_myitem']);
```

### Checking Rights in Code

```php
// Via item class (uses static $rightname)
MyItem::canView()       // has READ
MyItem::canUpdate()     // has UPDATE
MyItem::canCreate()     // has CREATE
MyItem::canDelete()     // has DELETE
MyItem::canPurge()      // has PURGE

// Via Session (for GLPI global rights or direct checks)
Session::haveRight('config', READ);           // Global config access
Session::haveRight('ticket', CREATE);         // Can create tickets
Session::checkRight(MyItem::$rightname, READ); // Throws exception if no right
```

### Custom Profile Rights Table (alternative approach)

For plugins that need more granular control beyond standard CRUD:

```sql
CREATE TABLE `glpi_plugin_myplugin_profiles` (
    `id`             INT unsigned NOT NULL AUTO_INCREMENT,
    `profiles_id`    INT unsigned NOT NULL DEFAULT 0,
    `right_feature`  INT unsigned NOT NULL DEFAULT 0,
    `right_config`   INT unsigned NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `profiles_id` (`profiles_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

```php
// Check custom rights using bitmask AND
if (($userRight & CREATE) === CREATE) {
    // User has CREATE permission
}
```

### Layered Permission Checking

Always check both plugin rights AND GLPI global rights as fallback:

```php
$canConfig = false;
try {
    $canConfig = PluginMypluginProfile::hasRight('right_config', UPDATE);
} catch (\Throwable $e) {}

// Fallback: GLPI super-admin can always access config
if (!$canConfig && !Session::haveRight('config', UPDATE)) {
    Html::displayRightError();
    exit;
}
```

---

## 18. Sessions & Authentication

### Session Data Access

```php
$userId    = Session::getLoginUserID();                    // 0 if not logged in
$profileId = $_SESSION['glpiactiveprofile']['id'] ?? 0;    // Active profile ID
$entityId  = $_SESSION['glpiactive_entity'] ?? 0;          // Active entity ID
$userName  = $_SESSION['glpiname'] ?? '';                   // Login username
$language  = $_SESSION['glpilanguage'] ?? 'en_GB';         // User language
```

### Getting User Details

```php
$user = new User();
if ($user->getFromDB($userId)) {
    $fullName = $user->getFriendlyName();           // "First Last"
    $email    = UserEmail::getDefaultForUser($userId);
}
```

### Important: Sessions in Cron Context

Cron tasks run without a user session. `Session::getLoginUserID()` returns `0`. Do not rely on `$_SESSION` in cron task code — pass entity/user IDs explicitly.

### Html::redirect() Does Not Exit

After `Html::redirect($url)`, your PHP code **continues executing**. Always call `exit;` or `return` after redirect if subsequent code should not run.

---

## 19. Translations (i18n)

### PHP Usage

```php
__('String to translate', 'myplugin');              // Simple translation
_n('One item', '%d items', $count, 'myplugin');     // Plural form
_x('String', 'context', 'myplugin');                // With disambiguation context
sprintf(__('Hello %s', 'myplugin'), $name);         // With parameters
```

### .po File Format (locales/en_GB.po, locales/pl_PL.po)

```po
msgid ""
msgstr ""
"Content-Type: text/plain; charset=UTF-8\n"
"Content-Transfer-Encoding: 8bit\n"

msgid "Equipment Transfer"
msgstr "Equipment Transfer"

msgid "Hello %s"
msgstr "Hello %s"
```

### Plural Forms

```po
msgid "Access"
msgid_plural "Accesses"
msgstr[0] "Access"
msgstr[1] "Accesses"
```

Polish requires 3 plural forms:

```po
"Plural-Forms: nplurals=3; plural=(n==1 ? 0 : n%10>=2 && n%10<=4 "
"&& (n%100<10 || n%100>=20) ? 1 : 2);\n"

msgstr[0] "Dostęp"
msgstr[1] "Dostępy"
msgstr[2] "Dostępów"
```

### Rules

- **Always** use the plugin domain (second parameter): `__('text', 'myplugin')`.
- **Never** hardcode user-visible strings without a translation wrapper.
- Add every new string to **both** language files.
- The domain must match the plugin directory name.

---

## 20. Frontend (JS/CSS)

### JavaScript

No build tools — vanilla JS only. Edit files directly.

```javascript
// AJAX call pattern
fetch(pluginBaseUrl + '/ajax/getData.php?param=' + encodeURIComponent(value), {
    method: 'GET',
})
.then(response => response.json())
.then(data => {
    if (data.success) {
        // Process data.data
    }
})
.catch(error => console.error('AJAX error:', error));
```

### Passing Config from PHP to JS

```php
// In your PHP form/page:
$jsConfig = [
    'ajaxUrl'   => Plugin::getWebDir('myplugin') . '/ajax/',
    'csrfToken' => Session::getNewCSRFToken(),  // Only if needed for non-/front/ POST
    'labels'    => [
        'confirm' => __('Confirm', 'myplugin'),
        'cancel'  => __('Cancel', 'myplugin'),
    ],
];
echo '<script>var MyPluginConfig = ' . json_encode($jsConfig) . ';</script>';
```

### CSS

```css
/* Prefix plugin classes to avoid collisions */
.plugin-myplugin-container { }
.plugin-myplugin-button { }

/* Use GLPI's Bootstrap variables where possible */
```

### CSS/JS Cache Busting

In `setup.php`, always append version to asset URLs:

```php
$v = PLUGIN_MYPLUGIN_VERSION;
$PLUGIN_HOOKS['add_css']['myplugin']        = ["public/css/myplugin.css?v={$v}"];
$PLUGIN_HOOKS['add_javascript']['myplugin'] = ["public/js/myplugin.js?v={$v}"];
```

Browsers and GLPI cache static assets aggressively. Without version query strings, users will see stale JS/CSS after updates. Always use `?v=VERSION` and increment on every release.

---

## 21. File & Document Handling

### Plugin Storage Directories

```php
$pluginDir = GLPI_DOC_DIR . '/_plugins/myplugin/';
@mkdir($pluginDir . 'uploads/', 0755, true);
```

### Creating a GLPI Document Record

```php
$doc = new Document();
$docId = $doc->add([
    'name'          => 'Protocol_' . $itemId . '.pdf',
    'filename'      => $filename,
    'filepath'      => $relativePath,    // Relative to GLPI_DOC_DIR
    'mime'          => 'application/pdf',
    'entities_id'   => $_SESSION['glpiactive_entity'],
    'is_recursive'  => 1,
]);
```

### Linking Documents to Items

```php
(new Document_Item())->add([
    'documents_id' => $docId,
    'itemtype'     => 'User',       // Or 'Ticket', 'Computer', etc.
    'items_id'     => $userId,
    'entities_id'  => $_SESSION['glpiactive_entity'],
]);
```

### File Upload Validation (Multi-Layer)

```php
// 1. Check upload success
if ($_FILES['upload']['error'] !== UPLOAD_ERR_OK) { return; }

// 2. Validate MIME type
$mime = mime_content_type($_FILES['upload']['tmp_name']);
if (!in_array($mime, ['image/png', 'image/jpeg'], true)) { reject(); }

// 3. Validate extension
$ext = strtolower(pathinfo($_FILES['upload']['name'], PATHINFO_EXTENSION));
if (!in_array($ext, ['png', 'jpg', 'jpeg'], true)) { reject(); }

// 4. Check file size
if ($_FILES['upload']['size'] > 2 * 1024 * 1024) { reject(); }

// 5. Validate image integrity (skip for SVG)
if ($mime !== 'image/svg+xml') {
    $info = @getimagesize($_FILES['upload']['tmp_name']);
    if ($info === false) { reject(); }
}

// 6. Generate safe filename (never use user-supplied filename directly)
$safeFilename = 'upload_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
```

---

## 22. PDF Generation

### Fallback Chain Pattern

```php
public static function generatePDF(string $html): ?string {
    // 1. Try wkhtmltopdf (best quality, 1:1 with browser Ctrl+P)
    $wkPath = self::findWkhtmltopdf();
    if ($wkPath) {
        return self::renderWithWkhtmltopdf($wkPath, $html);
    }

    // 2. Try Chromium headless
    $chromePath = self::findChromium();
    if ($chromePath) {
        return self::renderWithChromium($chromePath, $html);
    }

    // 3. Try mPDF (PHP library, limited CSS)
    if (class_exists('\\Mpdf\\Mpdf')) {
        return self::renderWithMpdf($html);
    }

    // 4. Fallback: save as HTML
    return self::saveAsHtml($html);
}
```

### Shell Command Safety

```php
$cmd = sprintf(
    '%s --quiet --page-size A4 --encoding utf-8 %s %s 2>&1',
    escapeshellarg($binaryPath),
    escapeshellarg($inputHtmlPath),
    escapeshellarg($outputPdfPath)
);
exec($cmd, $output, $exitCode);
```

### Temp File Handling

```php
$tmpDir = GLPI_TMP_DIR;
$htmlPath = $tmpDir . '/protocol_' . uniqid() . '.html';
file_put_contents($htmlPath, $html);
// ... generate PDF ...
@unlink($htmlPath);  // Always clean up
```

### mPDF Adaptation

mPDF has limited CSS support. Adapt HTML before passing:

```php
private static function adaptForMpdf(string $html): string {
    // mPDF doesn't support Segoe UI
    $html = str_replace("'Segoe UI'", "'DejaVu Sans'", $html);
    // mPDF handles max-width differently
    $html = str_replace('max-width: 800px;', '', $html);
    return $html;
}
```

### Design Rules for Printable HTML

- Use **table-based layout** only (no flexbox, no CSS grid) — mPDF can't render them.
- Use **inline styles** — external CSS classes don't carry into PDF rendering.
- Embed images as **base64 data URLs** — PDF engines can't fetch external URLs.
- Include `@page { size: A4; margin: 15mm 20mm; }` in `<style>` block.

---

## 23. Notifications

### Custom Notification Target

```php
class PluginMypluginNotificationTarget extends NotificationTarget
{
    // Define events this plugin can fire
    public function getEvents() {
        return [
            'transfer_completed' => __('Transfer completed', 'myplugin'),
        ];
    }

    // Define possible recipients
    public function addNotificationTargets($event = '') {
        $this->addTarget(Notification::USER, __('Employee', 'myplugin'));
        $this->addTarget(Notification::ASSIGN_TECH, __('Technician', 'myplugin'));
    }

    // Populate template tags
    public function addDataForTemplate($event, $options = []) {
        $this->data['##myplugin.employee##'] = $options['employee_name'] ?? '';
    }
}
```

### Firing a Notification

```php
NotificationEvent::raiseEvent('transfer_completed', $transferObject, [
    'employee_name' => $name,
    'employee_id'   => $userId,
]);
```

### Suppressing Standard GLPI Notifications

When creating tickets programmatically, prevent GLPI from sending its default notifications:

```php
$ticket = new Ticket();
$ticketId = $ticket->add([
    'name'             => 'My ticket',
    'content'          => 'Content',
    '_disablenotif'    => true,   // Suppress standard notification
    '_users_id_assign' => $techId,
]);
```

### Rules

- Method signatures in NotificationTarget subclasses **must match parent exactly** — no extra parameters, no return type hints.
- Register the notification target class in `setup.php`: `Plugin::registerClass('PluginMypluginNotificationTarget', ['notificationtargets_types' => true])`.
- Unregister in `plugin_myplugin_uninstall()`.

---

## 24. Logging

### Recommended Pattern

```php
class PluginMypluginLogger
{
    private static ?string $logFile = null;

    public static function info(string $message, array $context = []): void {
        self::log('INFO', $message, $context);
    }

    public static function error(string $message, array $context = []): void {
        self::log('ERROR', $message, $context);
        // Also log to GLPI's native logger for visibility
        try { Toolbox::logError("MyPlugin: {$message}"); } catch (\Throwable $e) {}
    }

    private static function log(string $level, string $message, array $context): void {
        $file = self::getLogFile();
        $ts = date('Y-m-d H:i:s');
        $userId = Session::getLoginUserID() ?: 0;
        $ctxStr = $context ? ' | ' . json_encode($context) : '';
        $line = "[{$ts}] [{$level}] [user:{$userId}] {$message}{$ctxStr}\n";
        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    }

    private static function getLogFile(): string {
        if (!self::$logFile) {
            self::$logFile = (defined('GLPI_LOG_DIR') ? GLPI_LOG_DIR : '/tmp') . '/myplugin.log';
        }
        return self::$logFile;
    }
}
```

### Key Rules

- Never log sensitive data (tokens, passwords, signatures) at any level.
- Log to the plugin's own file (`GLPI_LOG_DIR/myplugin.log`), not GLPI's main log.
- Also write errors to `Toolbox::logError()` for GLPI admin visibility.

---

## 25. Security Checklist

Every code change must satisfy:

| Check | Method |
|-------|--------|
| User input sanitized on output | `Html::cleanInputText()`, `htmlspecialchars()` |
| SQL queries parameterized | `$DB->request()` with array bindings, `$DB->escape()` for LIKE |
| Shell commands escaped | `escapeshellarg()` on ALL arguments |
| CSRF tokens on forms | GLPI auto-handles in `/front/`; use `Session::getNewCSRFToken()` elsewhere |
| Cryptographic tokens | `bin2hex(random_bytes(24))` — never `md5(time())` or `uniqid()` |
| File uploads validated | MIME + extension + size + integrity checks |
| Filenames sanitized | Generate safe names, never use user input directly |
| Authentication checked | `Session::getLoginUserID()` at every entry point |
| Permissions verified | Check plugin rights + GLPI global rights |

---

## 26. What Does NOT Work / Forbidden Patterns

### FORBIDDEN — Will break or cause subtle bugs

| Pattern | Why It Fails |
|---------|-------------|
| `Session::checkCRSF()` in `/front/*.php` | GLPI already consumed the token — second check always fails |
| Raw `mysqli_*` calls | Bypasses GLPI's connection management and escaping |
| `md5(time())` for tokens | Predictable, not cryptographically secure |
| `echo $userInput` without escaping | XSS vulnerability |
| Shell commands without `escapeshellarg()` | Command injection vulnerability |
| Accessing `$item->fields` in `pre_item_add` | Fields aren't populated yet — only `input` exists |
| Using `$item->fields['status']` to detect NEW status in `item_update` | `fields` contains the OLD value — use `$item->input['status']` |
| Hardcoding user-visible strings | Breaks translations, violates GLPI conventions |
| jQuery / npm imports | GLPI plugins use vanilla JS; GLPI's own jQuery is internal |
| `composer require` in plugin directory | GLPI has no Composer autoload for plugins |
| `$DB->query()` with string concatenation | SQL injection risk; use `$DB->request()` with arrays |
| Flexbox/CSS Grid in printable HTML | mPDF fallback can't render them — use tables |
| `Html::submit('<i class="..."></i>')` | Shows raw HTML text to user — use plain text labels |
| `'field_fkey' => 'col'` in search options | Silently ignored — search breaks. Use `'linkfield'` |
| Twig templates in plugins | Fragile, undocumented for plugins, breaks between GLPI versions |
| Direct SQL for SELECT queries | Bypasses entity filtering, hard to maintain |
| Hardcoding URLs | Breaks on non-root installations. Use `Plugin::getWebDir()`, `self::getFormURL()` |
| `echo` before `include('inc/includes.php')` | Headers already sent error |
| Storing config in PHP files | Lost on plugin update — use a config DB table |

### DOES NOT WORK — GLPI Limitations

| Expectation | Reality |
|-------------|---------|
| Plugin tables exist during `plugin_init` | They don't during install — always wrap in try-catch |
| Session data available in cron | `Session::getLoginUserID()` returns 0 in cron context |
| `NotificationEvent::raiseEvent()` works in uninstall | Plugin is already being deactivated — notifications may fail |
| Calling `Html::closeForm()` generates CSRF token | It does, but only for the NEXT form submission — not retroactively |
| Custom Asset queries on `glpi_computers` etc. | Custom Assets (GLPI 11) use `glpi_assets_assets` — completely different table |
| Missing `use Dropdown;` in namespaced file | Fatal error: class not found (especially in AJAX tabs — shows as blank tab) |
| Missing front-end form handler for relation | Add/delete buttons return 404 |
| Table without `entities_id` but `$can_be_recursive = true` | SQL errors on entity filtering |
| Search option with wrong `joinparams` | Column shows empty in search results |
| `Dropdown::show()` without `'entity'` param | Shows items from all entities |
| `showFormButtons()` missing at end of `showForm()` | No Save/Add button rendered |
| `initForm()` missing at start of `showForm()` | Form won't load existing data |
| Plugin version not bumped after schema change | Migration code won't run |

---

## 27. Common Gotchas & Known Pitfalls

### 1. Tables Don't Exist During Install

Every DB access in `setup.php`, menu classes, or any class method that runs at load time must be guarded:

```php
try {
    $value = $DB->request([...]);
} catch (\Throwable $e) {
    $value = $default;
}
// OR
if ($DB->tableExists('glpi_plugin_myplugin_configs')) {
    // Safe to query
}
```

### 2. Ticket Status Is an Integer, Not a String

```php
// Correct
$status = (int)$ticket->fields['status'];
if ($status === CommonITILObject::CLOSED) { ... }

// Wrong — string comparison may fail
if ($ticket->fields['status'] === 'closed') { ... }
```

### 3. Equipment State IDs May Vary Per Installation

State IDs like 2 (IN USE) and 29 (TO CHECK) are common defaults but not guaranteed. If your plugin depends on specific states, make them configurable or look them up by name from `glpi_states`.

### 4. Custom Assets (GLPI 11) Have a Different Schema

```php
// Native assets: glpi_computers, glpi_monitors, etc.
// Custom assets: glpi_assets_assets with assets_assetdefinitions_id filter

if ($isCustomAsset) {
    $rows = $DB->request([
        'FROM'  => 'glpi_assets_assets',
        'WHERE' => [
            'assets_assetdefinitions_id' => $definitionId,
            'id' => $itemId,
        ],
    ]);
} else {
    $rows = $DB->request([
        'FROM'  => $nativeTable,  // 'glpi_computers', etc.
        'WHERE' => ['id' => $itemId],
    ]);
}
```

### 5. Blocking Ticket Closure Requires Multiple Hooks

A user can close a ticket via:
- Changing the status field directly → `pre_item_update` on Ticket
- Adding an ITILSolution → `pre_item_add` on ITILSolution
- Approving a pending solution → `pre_item_update` on Ticket

You need hooks on **all paths** to reliably block premature closure.

### 6. Notification Method Signatures Must Match Parent

```php
// WRONG — extra return type breaks GLPI's reflection
public function getEvents(): array { }

// CORRECT — match parent signature exactly
public function getEvents() { }
```

### 7. Plugin CSS/JS Caching Is Aggressive

Without version query strings (`?v=VERSION`), users will see stale JS/CSS after updates. Always increment version on every release.

### 8. Base64 Images in Database Can Be Very Large

Signature pad captures or embedded images stored as base64 in `LONGTEXT` columns can be 50KB+ each. For high-DPI screens, they can be much larger. Plan your column types accordingly.

### 9. SuperAdmin Can Override Plugin Restrictions

Profile ID 4 (Super-Admin) can bypass many GLPI restrictions. Your plugin should handle this gracefully — either allow it with logging, or explicitly check and block with an explanation.

### 10. Missing `use` Statements in Namespaced Files

A missing `use` import in a namespaced file causes a fatal error that is especially hard to debug when it happens inside AJAX-loaded tabs — you just see a blank tab or 500 error in the browser console. **Always check ALL `use` statements.**

---

## 28. Useful Constants & Paths

```php
GLPI_ROOT                // /var/www/glpi (or wherever GLPI is installed)
GLPI_DOC_DIR             // /var/lib/glpi/files (document storage)
GLPI_LOG_DIR             // /var/log/glpi
GLPI_TMP_DIR             // /var/lib/glpi/_tmp
$CFG_GLPI['root_doc']   // URL base path, e.g., '/glpi' or '/'
$CFG_GLPI['url_base']   // Full URL base, e.g., 'https://glpi.example.com/glpi'

// Plugin paths
GLPI_ROOT . '/plugins/myplugin/'                           // Plugin files
GLPI_DOC_DIR . '/_plugins/myplugin/'                       // Plugin document storage
Plugin::getWebDir('myplugin')                              // Web-accessible URL path
Plugin::getPhpDir('myplugin')                              // Filesystem path

// Current entity
$_SESSION['glpiactive_entity']
$_SESSION['glpiactive_entity_name']

// Rights constants
READ              = 1
CREATE            = 2
UPDATE            = 4
DELETE            = 8
PURGE             = 16
ALLSTANDARDRIGHT  = 31
```

---

## 29. Checklists

### New Plugin Checklist

When starting a new GLPI plugin from scratch:

1. Create directory structure: `setup.php`, `hook.php`, `src/`, `front/`, `ajax/`, `public/`, `locales/`
2. Define plugin version constant and `plugin_version_*()` function
3. Implement `plugin_init_*()` with `csrf_compliant = true`
4. Implement install/uninstall/upgrade in `hook.php`
5. Create main class extending `CommonDBTM` with correct naming
6. Create menu class extending `CommonGLPI` (if needed)
7. Provision rights with `ProfileRight::addProfileRights()`
8. Grant Super-Admin full rights during install
9. Add translation files for all supported languages
10. Add CSS/JS with version cache-busting
11. Test: fresh install, uninstall, reinstall, upgrade path

### Before Shipping Checklist

- [ ] `setup.php` has `csrf_compliant` set to `true`
- [ ] All `use` statements present in every namespaced file
- [ ] `hook.php` has both install AND uninstall functions
- [ ] All front-end handler files exist (`*.form.php`) for every CommonDBRelation
- [ ] `rawSearchOptions()` uses `linkfield` (NOT `field_fkey`) for non-standard FKs
- [ ] `Html::submit()` never receives raw HTML as label
- [ ] `Migration::executeMigration()` called at end of install function
- [ ] Rights provisioned during install (`ProfileRight::addProfileRights`)
- [ ] Rights cleaned up during uninstall (`ProfileRight::deleteProfileRights`)
- [ ] Version constant bumped when schema changes
- [ ] Tables dropped in correct order during uninstall (children first)
- [ ] PHP syntax check passes on all files (`php -l src/*.php hook.php setup.php front/*.php`)
- [ ] Entity support columns present if `$can_be_recursive = true`
- [ ] `showFormHeader()` and `showFormButtons()` bracket form content
- [ ] `initForm()` called before accessing `$this->fields`
- [ ] `Dropdown::show()` calls include `'entity'` parameter

---

*This guide is maintained as a living document. Update it when you discover new GLPI patterns, quirks, or breaking changes across versions.*
