# Storewide Sale for WooCommerce

Apply one percentage discount to many products, exclude recent products or categories, and restore previous sale prices when the campaign ends. An independent plugin by incoog. Version 0.1.0 is an initial version for staging evaluation.

## Installation

[Download the installable ZIP from Releases](https://github.com/incoog/storewide-sale/releases). The repository source archive also contains development tools; use the release asset for WordPress installation.

Requires WordPress 6.5+, WooCommerce 8.0+ and PHP 7.4+. Upload `storewide-sale-0.1.0.zip` in **Plugins → Add New → Upload Plugin**, activate it, and open **WooCommerce → Storewide Sale**. The administration interface is currently in Swedish.

There is no build step or runtime dependency on another discount plugin. The ZIP contains the plugin, this README and its licence. Test tooling is not included.

## Campaign settings

| Setting | Behavior |
| --- | --- |
| Percentage | A discount greater than 0 and up to 100%, calculated from the current regular price, rounded to the store's price decimals |
| Created after | Exclude products created after the selected date, in the site's timezone; the selected day is included |
| Categories | Exclude selected categories and their descendants |
| Variations | Use their own prices and their parent's creation date and categories |
| Existing lower sale | Leave an already active sale unchanged if it is as low as, or lower than, the proposed campaign price |
| Catalog scope | Published/private simple products, external products and variations existing when activation begins; parent/grouped/custom product types are not repriced |

The campaign writes WooCommerce sale prices through product CRUD. It stores the original sale price, sale timestamps and original regular price before the first write. Original regular prices are recorded for comparisons, never written back. Existing sale schedules are suspended for managed products and restored with their original timestamps. A schedule that has expired while the campaign ran is not extended.

Percentage, date and category settings stay fixed during a campaign. Products created after activation are not automatically enrolled, and later category changes do not re-evaluate membership. Finish the campaign and start another to change the selection.

## Price changes during a campaign

| Change | Result |
| --- | --- |
| Change regular price | Recalculate the campaign sale against the new regular price |
| Change regular price on a product that had an original sale | Add a required restoration decision: restore the original sale amount, preserve the original percentage discount, or remove the sale |
| Change regular price again | Invalidate that product's earlier restoration decision |
| Change sale price or sale dates | Remove that product from automatic repricing; show it in the manual overrides list |
| Manual override at campaign end | Keep its latest sale and schedule by default, or explicitly remove the sale and schedule |
| Override an unresolved regular-price conflict manually | Replace the conflict with a manual override, with keep as the default |

Example: a product starts at 1,000 with a sale price of 900. A 20% campaign sets its sale to 800. Changing regular price to 1,200 sets the campaign sale to 960 and requires a restoration decision. Choose 900, choose 1,080 (the original 10% discount), or remove the sale. Regular price remains 1,200.

All required conflicts must be decided before the stop request is accepted. A further conflict discovered during restoration pauses completion until it is decided. Original sale amounts that are no longer below regular price cannot be restored as a valid WooCommerce sale; select the percentage option or remove the sale instead. Manual overrides do not require a decision to stop.

Changes are observed through WooCommerce product-save hooks, including WooCommerce's editor, bulk editor, REST API and CSV importer when they use CRUD. Direct database writes and tools bypassing those hooks do not trigger immediate recalculation. A changed sale encountered during restoration is conservatively retained as a manual override.

## Background processing and recovery

Activation and restoration run in small batches. WordPress scheduled events continue the work; keeping the settings page open also advances batches through authenticated requests. Sites with disabled WP-Cron need their normal external cron setup or an open settings page. A failed batch displays an error and can be retried. Starting another campaign is blocked until the current one is finished.

A database journal records each product's original state and pending write before changing its sale price. Completed steps can be resumed after interruption without replacing the original snapshot. Campaign commands and observed price saves are serialized with a short database lease. If a price save collides with a batch, it reports that the campaign is busy rather than silently discarding the edit.

**Finish the campaign from its settings page before deactivating or deleting the plugin.** Deactivation is not the campaign switch and does not restore prices automatically. Restoration records are deliberately retained, including after deactivation. Reactivate the plugin to continue an unfinished campaign.

## Optional SOFT79 integration

Storewide Sale works without SOFT79. It does not modify SOFT79 files or settings.

| Detected installation | Settings page |
| --- | --- |
| No SOFT79 | No integration controls |
| Original SOFT79 | Information linking to the independent [maintained fork](https://github.com/incoog/soft79-wc-pricing-rules); no integration switch |
| Maintained fork installed but not running | Information asking you to activate the fork; no switch |
| Maintained fork running with exclusion support | Optional switch to exclude campaign-managed products from SOFT79 rules |

The switch defaults to off on a fresh installation. The plugin identifies the active fork through `soft79_wcpr_get_integration_info()` and uses its `soft79_wcpr_product_pricing_enabled` filter hook. A product is excluded only once its campaign price has been written. Manual overrides stay excluded until campaign completion, and other products remain governed by SOFT79 normally. The integration preference can be changed during the campaign.

## Validation

The initial implementation passed 71 integration assertions in WordPress 7.1.3, WooCommerce 11.2.1 and PHP 8.3.33 using WordPress Playground with SQLite. Checks included the 900/1,200/1,080 restoration example, both restoration choices, repeat price changes, manual overrides, variation eligibility, SOFT79 detection/exclusion, interrupted saves, batches, cancellation and deletion. PHP syntax was checked on 7.4, 8.3 and 8.5. This is not a live-site/MySQL compatibility certification.

Test representative products, tax/currency rounding, existing scheduled sales, category exclusions, variation prices, other price-changing extensions and your actual cart/checkout on staging before live use. This plugin sets ordinary product sale prices; ordinary coupon rules still apply. It does not claim tested compatibility with every product type, cache, currency extension or checkout implementation.

## Development and automated checks

GitHub Actions runs on pushes to `main`, pull requests and version tags. It checks PHP syntax on 7.4, 8.3 and 8.5; tests the admin polling script with Node's built-in test runner; verifies the ZIP builder; and runs the full product-price/restoration fixtures against a real WordPress/WooCommerce installation with MySQL 8. Two integration combinations are exercised: WordPress 6.5 / WooCommerce 8.0.0 / PHP 7.4, and current WordPress / WooCommerce / PHP 8.3. The SOFT79 fixture is a separate checkout pinned to commit `7923cba7f27c44e081c442e1e7b09d246da01b92`; SOFT79 code is not bundled in this plugin.

Fast development commands are `node --test tests/admin.test.cjs`, `python3 -m unittest discover -s tests -p 'test_*.py' -v` and `python3 scripts/build_release.py`. No npm dependencies are needed. The package builder includes only the main PHP file, `includes`, `assets`, README and LICENSE. Its ZIP has one `storewide-sale` root directory. Every successful workflow uploads this installable ZIP as an Actions artifact. A version-tag workflow validates the tag against the PHP header and prepares a draft release with the ZIP; publishing remains an explicit step.

The integration script creates product fixtures and changes prices. Run it only on a disposable, empty test site. It requires CLI, `SWS_TEST_RUN=1`, a site option `sws_test_environment=disposable`, no existing product posts/campaign and `SWS_SOFT79_FILE` pointing to the separately checked-out fork entry point. Use `SWS_TEST_RUN=1 SWS_SOFT79_FILE=/absolute/path/to/soft79-wc-pricing-rules.php wp eval-file /absolute/path/to/storewide-sale/tests/integration.php --path=/absolute/path/to/disposable-wordpress` after installing WooCommerce and activating this plugin. `scripts/ci-integration.sh` provisions that environment in Actions using its dedicated MySQL service.

## Changelog

### Unreleased

| Type | Change |
| --- | --- |
| Added | Repeatable WordPress/WooCommerce integration tests against MySQL, covering price restoration, conflicts, manual overrides, recovery, deletion, access guards and SOFT79 detection |
| Added | PHP compatibility checks, admin polling tests and package tests in GitHub Actions |
| Added | Reproducible installation ZIP builder, workflow artifacts and draft release preparation for version tags |
| Changed | Keep development/test files in the repository and exclude them from the installable release ZIP |

### 0.1.0

| Type | Change |
| --- | --- |
| Added | Storewide percentage campaigns with creation-date and category exclusions |
| Added | Persistent sale prices, original-price snapshots and resumable batch activation/restoration |
| Added | Recalculate campaign prices after regular-price edits without overwriting the new regular price on restoration |
| Added | Required per-product decisions when an original sale and a changed regular price conflict |
| Added | Manual sale/schedule overrides with keep/remove choices and keep as the default |
| Added | Optional product exclusions for the independently maintained SOFT79 fork |
| Added | Permission/nonce checks, serialized campaign writes and a paginated administration interface |

## Licence

GPL-2.0-or-later. See LICENSE.
