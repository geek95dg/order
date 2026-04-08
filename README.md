# Order GLPI plugin

[![License](https://img.shields.io/github/license/pluginsGLPI/order.svg?&label=License)](https://github.com/pluginsGLPI/order/blob/develop/LICENSE)
[![Follow twitter](https://img.shields.io/twitter/follow/Teclib.svg?style=social&label=Twitter&style=flat-square)](https://twitter.com/teclib)
[![Telegram Group](https://img.shields.io/badge/Telegram-Group-blue.svg)](https://t.me/glpien)
[![Project Status: Active](http://www.repostatus.org/badges/latest/active.svg)](http://www.repostatus.org/#active)
[![GitHub release](https://img.shields.io/github/release/pluginsGLPI/order.svg)](https://github.com/pluginsGLPI/order/releases)
[![GitHub build](https://travis-ci.org/pluginsGLPI/order.svg?)](https://travis-ci.org/pluginsGLPI/order/)

![Screenshot1](./screenshots/screenshot1.png)
![Screenshot2](./screenshots/screenshot2.png)
![Screenshot3](./screenshots/screenshot3.png)

This plugin allows you to manage order management within GLPIi:

- Products references management
- Order management (with approval workflow)
- Budgets management
- GLPI 11+ user-defined custom assets support as product references
- OT (fixed assets protocol) PDF generation from orders

## Documentation

We maintain a detailed documentation here -> [Documentation](https://glpi-plugins.readthedocs.io/en/latest/order/index.html)

## Contact

For notices about major changes and general discussion of order, subscribe to the [/r/glpi](https://www.reddit.com/r/glpi/) subreddit.
You can also chat with us via IRC in [#glpi on freenode](http://webchat.freenode.net/?channels=glpi) or [@glpi on Telegram](https://t.me/glpien).

## Professional Services

![GLPI Network](./glpi_network.png "GLPI network")

The GLPI Network services are available through our [Partner's Network](http://www.teclib-edition.com/en/partners/). We provide special training, bug fixes with editor subscription, contributions for new features, and more.

Obtain a personalized service experience, associated with benefits and opportunities.

## Changelog

### 2.13.1

**Improvements**

- **Bill-to-Item Linking on OT Generation**: When generating an OT with an invoice number, the auto-created bill is now linked to all order items. Each item's bill reference and payment status are set to Paid, Infocom records on delivered assets are updated with the bill number and warranty date, and the order's aggregate bill state is updated accordingly. This ensures the "Faktura" (Invoice) and "Status faktury" (Invoice status) columns are populated in the delivered items view.

### 2.13.0

**New Features**

- **GLPI 11+ Custom Assets Support**: User-defined custom assets (stored in `glpi_assets_assets`) are now dynamically discovered and available as product references. Type, Model, and Template dropdowns work correctly for custom asset classes, including proper scoping via `assets_assetdefinitions_id`.

- **OT Protocol PDF Generation**: Generate OT (fixed assets protocol) documents directly from orders. Accessible via the "Generate OT" action in the Actions dropdown. Prompts for a Cost Center (MPK) and Invoice Number, then generates a PDF containing all delivered items with their serial numbers, values, and delivery dates. Uses a system binary fallback chain (wkhtmltopdf → Chromium → mPDF → HTML). Generated documents are saved to the GLPI document system and linked to the order. When an invoice number is provided, a Bill record is automatically created with supplier, amount, paid status, and linked to all order items.

## Contributing

* Open a ticket for each bug/feature so it can be discussed
* Follow [development guidelines](http://glpi-developer-documentation.readthedocs.io/en/latest/plugins/index.html)
* Refer to [GitFlow](http://git-flow.readthedocs.io/) process for branching
* Work on a new branch on your own fork
* Open a PR that will be reviewed by a developer

## Copying

* **Code**: you can redistribute it and/or modify
    it under the terms of the GNU General Public License ([GPL-2.0](https://www.gnu.org/licenses/gpl-2.0.en.html)).
