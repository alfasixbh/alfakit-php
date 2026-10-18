# AlfaKit

An admin panel builder for Laravel by [Alfa Six](https://alfasixbh.com). Build resources with forms and tables, actions with modals, dashboards with widgets, notifications, multi-tenancy and authentication entirely in PHP, and render them with React, Vue or Svelte through Inertia.js.

> **Status: beta.** AlfaKit 0.1 is a public beta. Public APIs may still change before 1.0, so pin your versions.

This is a read-only split of the PHP package. Development, issues and pull requests happen in the monorepo: [alfasixbh/alfakit](https://github.com/alfasixbh/alfakit).

## Installation

```bash
composer require alfasix/alfakit
php artisan alfakit:install
```

The installer detects your frontend from `package.json`, publishes `config/alfakit.php`, creates `app/Providers/Alfakit/AdminPanelProvider.php` and installs `@alfakit/react`, `@alfakit/vue` or `@alfakit/svelte`.

Requires Laravel 13, Inertia.js 3, Node 22+ and Tailwind CSS 4.

## Documentation

[alfakit.dev/docs](https://alfakit.dev/docs)

## License

AlfaKit is open-source software licensed under the [MIT license](LICENSE.md). The AlfaKit name, fox mascot and logos are brand assets of Alfa Six and are not covered by the MIT license.
