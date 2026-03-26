# LiteSpeed Cache for PrestaShop

Full Page Cache module for PrestaShop running on LiteSpeed Web Server. Saves and serves static copies of dynamic pages, greatly reducing page-load time. The only PrestaShop cache module to support `esi:include` and `esi:inline`.

## Requirements

| Component | Version |
|---|---|
| PHP | >= 7.4 |
| PrestaShop | 1.7.8 / 8.x / 9.x |
| Web Server | LiteSpeed Enterprise or OpenLiteSpeed |

## Installation

1. Download `litespeedcache.zip` from the [latest release](https://github.com/ecomlabs-es/lscache_prestashop/releases/latest).
2. In PrestaShop Admin, go to **Modules > Module Manager** and click **Upload a Module**.
3. Select the zip file.
4. Click **Configure** to launch the Setup Wizard.

> Make sure your LiteSpeed license includes the LSCache module. A free 2-CPU trial license is available for 15 days.

## Setup Wizard

The module includes a guided wizard that configures the cache based on your store:

1. **Your Store** — Auto-detects languages, currencies, multistore. Asks about hosting type, mobile theme, customer group pricing, catalog update frequency.
2. **Purge** — Configures automatic cache purge on orders and content changes.
3. **Object Cache** — Detects Redis and enables object caching if available.
4. **CDN** — Cloudflare integration with email, API key and auto-detected domain.
5. **Summary** — Review and apply configuration.

## Features

- Full page cache with automatic purge on content changes
- Setup Wizard for guided configuration
- Edge Side Includes (ESI) for per-user dynamic blocks (cart, account)
- Automatic product page purge on cart changes (stock/availability sync)
- Multi-store, multi-language, multi-currency and geolocation support
- Separate mobile view caching
- Tag-based purge (products, categories, CMS, prices, manufacturers, suppliers)
- Redis object cache backend with auto-detection
- Cloudflare CDN integration
- Configurable TTL per content type
- Cache exclusions by URL, query string, cookie, user agent or customer group
- Cache warmup with concurrent crawling and performance profiles (Low/Medium/High)
- Server load throttling for crawl operations
- Mobile cache warmup
- Import/export full configuration
- Debug headers and logging
- Compatible with PrestaShop 1.7.8, 8.x and 9.x


## Screenshots

### **Wizard**
<table>
  <tr>
    <td><img src="https://github.com/user-attachments/assets/8ee9572b-cbdf-445a-a3b4-225e7aa68f72" width="100%" /></td>
    <td><img src="https://github.com/user-attachments/assets/202bb1a8-839f-4269-b237-1a7548ce7971" width="100%" /></td>
    <td><img src="https://github.com/user-attachments/assets/36b48b70-d9ea-4050-b456-c16b1f898459" width="100%" /></td>
  </tr>
  <tr>
    <td><img src="https://github.com/user-attachments/assets/ca6e3e15-0208-405e-a727-3e8d47a85846" width="100%" /></td>
    <td><img src="https://github.com/user-attachments/assets/523b6c44-97e7-4a2d-b7e9-97550cf53970" width="100%" /></td>
    <td></td>
  </tr>
</table>

### **Cache Litespeed / Redis / CDN**

<table>
  <tr>
    <td><img src="https://github.com/user-attachments/assets/8dd6e977-a9ac-426d-9cb3-2b3dfcdbee7d" width="100%" /></td>
    <td><img src="https://github.com/user-attachments/assets/680180c6-d185-4fad-83f2-b76ef79b15da" width="100%" /></td>
    <td><img src="https://github.com/user-attachments/assets/af0dd748-05b1-4798-b90f-806e66d129bb" width="100%" /></td>
  </tr>
  <tr>
    <td><img src="https://github.com/user-attachments/assets/030dd96a-4adc-4148-bcf8-b06d12701a4b" width="100%" /></td>
    <td><img src="https://github.com/user-attachments/assets/841031ec-42d2-45c7-8e5a-ee8708659de6" width="100%" /></td>
    <td><img src="https://github.com/user-attachments/assets/4babe7b7-202d-4c57-bec0-4d8db7af6e8d" width="100%" /></td>
  </tr>
</table>

### **Warm up / Stats / Database optimizations**

<table>
  <tr>
    <td><img src="https://github.com/user-attachments/assets/d8e83aec-5d41-4fd0-9d3c-b1c992ed6d77" width="100%" /></td>
    <td><img src="https://github.com/user-attachments/assets/8dd7f44d-7a4b-4fd3-b9bc-b1610cb19a84" width="100%" /></td>
    <td><img src="https://github.com/user-attachments/assets/2ac0fcd6-f473-4321-aa40-371f90deeba0" width="100%" /></td>
  </tr>
</table>

### **Tools**

<table>
  <tr>
    <td><img src="https://github.com/user-attachments/assets/90bd837f-4622-4b5c-8c12-463573f5fbbb" width="100%" /></td>
    <td><img src="https://github.com/user-attachments/assets/5d23279c-4d09-41c9-819a-e6cfd10ebb8d" width="100%" /></td>
    <td><img src="https://github.com/user-attachments/assets/dd4e8886-9eed-4230-9b78-873ae8846676" width="100%" /></td>
  </tr>
  <tr>
    <td><img src="https://github.com/user-attachments/assets/7bda2756-a61e-40c4-a160-011b3c81960c" width="100%" /></td>
    <td><img src="https://github.com/user-attachments/assets/41e9ca5c-587d-4acb-8d93-3548cb6a3aad" width="100%" /></td>
    <td><img src="https://github.com/user-attachments/assets/7ee5821b-0dc3-4495-b814-1dcd1b29be65" width="100%" /></td>
  </tr>
</table>

## Architecture

```
litespeedcache.php          Main module class (hooks, install/uninstall)
src/
├── Admin/                  ConfigValidator
├── Cache/                  CacheRedis (object cache driver)
├── Command/                WarmupLscacheCommand (CLI: litespeedcache:warmup)
├── Config/                 CacheConfig, CdnConfig, ObjConfig, ExclusionsConfig, WarmupConfig
├── Controller/Admin/       15 Symfony admin controllers + WizardController
├── Core/                   CacheManager, CacheState
├── Esi/                    EsiItem, EsiModuleConfig
├── Form/                   CachingTypeExtension, ImportSettingsType
├── Helper/                 CacheHelper, ObjectCacheActivator
├── Hook/
│   ├── Action/             Product, Category, CMS, Pricing, Auth, Order...
│   ├── Display/            BackOffice, Front display hooks
│   └── Filter/             Content filter hooks
├── Integration/            Cloudflare, ObjectCache
├── Logger/                 CacheLogger
├── Module/                 TabManager
├── Resolver/               HookParamsResolver
├── Service/Esi/            EsiMarkerManager, EsiOutputProcessor, EsiRenderer
├── Update/                 ModuleUpdater
└── Vary/                   VaryCookie
integrations/
├── LscIntegration.php      Base class for ESI integrations
├── core/                   Internal ESI blocks (Token, Env)
├── prestashop/             Native PS modules (CustomerSignIn, Shoppingcart, EmailAlerts)
├── modules/                Third-party modules (GdprPro, Pscartdropdown)
└── themes/                 Theme integrations (Warehouse, Panda, Alysum)
config/
├── routes.yml              30 admin routes
└── services.yml            Symfony DI services
views/templates/admin/      Twig templates for admin UI
```

## CLI Commands

Cache warmup from the PrestaShop root directory:

```bash
# Warm up all pages from sitemap (uses saved config: concurrency, delay, timeout, load limit)
php bin/console litespeedcache:warmup https://example.com/1_index_sitemap.xml

# Override settings for a single run
php bin/console litespeedcache:warmup https://example.com/1_index_sitemap.xml --concurrency=8 --delay=0

# Include mobile cache warmup
php bin/console litespeedcache:warmup https://example.com/1_index_sitemap.xml --mobile
```

### Cron setup

```bash
# Run daily at 3 AM
0 3 * * * cd /var/www/html && php bin/console litespeedcache:warmup https://example.com/1_index_sitemap.xml
```

The command reads the crawler configuration (concurrency, delay, timeout, load limit, mobile) from the module settings automatically.

## Testing Cache Headers

Use your browser's developer tools (Network tab) to check response headers:

| Header | Meaning |
|---|---|
| `X-LiteSpeed-Cache: hit` | Page served from cache |
| `X-LiteSpeed-Cache: miss` | Page generated and cached |
| `X-LiteSpeed-Cache-Control: no-cache` | Page not cacheable |

## Development

```bash
# Install dev dependencies
composer install

# Run tests (61 tests, 103 assertions)
vendor/bin/phpunit --testdox

# Check coding standards
composer cs-check

# Fix coding standards
composer cs-fix
```

## CI/CD

GitHub Actions workflows:

- **compatibility.yml** — PHP Lint (8.1/8.2/8.3), PHP CS Fixer, Composer Validate, Unit Tests (PHPUnit), Twig Lint
- **release.yml** — Builds `litespeedcache.zip` and creates a GitHub release on tag push (`v*`)

## License

GPL-3.0+

## Links

- [LiteSpeed Cache for PrestaShop documentation](https://docs.litespeedtech.com/lscache/lscps/)
- [LiteSpeed Web Server](https://www.litespeedtech.com)
