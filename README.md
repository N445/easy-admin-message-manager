# EasyAdmin Messenger Manager (EAMM)

Manage Symfony Messenger from EasyAdmin: queue overview, every pending or failed message with its cause, single and batch retry or removal, optional processing history.

- **Overview**: message count per transport, failures grouped by class, exception and original transport, last 24 h statistics; refreshes itself
- **Messages**: every listable transport (Doctrine, Redis ≥ 8.1), pending and in-progress messages, filters, pagination, detail page
- **Failed messages**: filters (class, original transport, exception, date range), stack trace, single retry (immediate result), batch requeue or removal of the selection or of **everything matching the filter**
- **History** (opt-in): handled / retrying / failed attempts with duration, memory, error, trace and message content
- Message and stamps shown in a JSON viewer: fold/unfold, search, copy
- Doctrine transports are queried in SQL: it stays fast with hundreds of thousands of messages
- Permissions per action (`EAMM_VIEW`, `EAMM_RETRY`, `EAMM_REMOVE`), payload redaction (configured keys and `#[\SensitiveParameter]`)
- Built with Symfony UX Live Components, light and dark themes follow EasyAdmin

## Requirements

- PHP 8.4
- Symfony 7.4 or 8.x
- EasyAdmin 5 with pretty admin URLs (the default since EasyAdmin 5)
- Symfony UX Live Components, with the Stimulus `live` controller loaded on admin pages
- A Doctrine failure transport is recommended (`doctrine://default?queue_name=failed`): it is the only one that can be listed, filtered and paginated at any volume

## Installation

### 1. Install the package

```bash
composer require robin/easy-admin-messenger-manager
```

Until the package is published on Packagist, declare the GitHub repository first in your `composer.json`:

```json
"repositories": [
    { "type": "vcs", "url": "https://github.com/robin/easy-admin-messenger-manager" }
]
```

To work on the bundle next to your application, use a path repository instead (with Docker, the folder must also be mounted in the PHP container at the same relative location):

```json
"repositories": [
    { "type": "path", "url": "../easy-admin-messenger-manager", "options": { "symlink": true } }
]
```

### 2. Register the bundle

Symfony Flex does it automatically. Otherwise add it to `config/bundles.php`:

```php
return [
    // ...
    EasyAdminMessengerManager\EasyAdminMessengerManagerBundle::class => ['all' => true],
];
```

### 3. Enable Live Components on admin pages

Skip this step if your admin already uses Live Components.

```bash
composer require symfony/ux-live-component
```

Check that `config/routes/ux_live_component.yaml` exists (the Flex recipe creates it):

```yaml
live_component:
    resource: '@LiveComponentBundle/config/routes.php'
    prefix: '/_components'
```

Load the Stimulus application on admin pages, for instance with Webpack Encore in your dashboard:

```php
public function configureAssets(): Assets
{
    return Assets::new()->addWebpackEncoreEntry('admin');
}
```

where the `admin` entry imports your Stimulus bootstrap and `assets/controllers.json` enables `@symfony/ux-live-component`. With AssetMapper, use `Assets::new()->addAssetMapperEntry('app')` instead.

Live requests are sent to `/_components/...`, outside of `/admin`: the bundle checks the permissions itself on every request, so no access control rule is needed, but that path must be covered by the firewall your admins log into.

### 4. Publish the assets

```bash
php bin/console assets:install public
```

This copies the JSON viewer and the selection script to `public/bundles/easyadminmessengermanager/`. Flex runs it after each `composer install`.

### 5. Generate the dashboard controller

```bash
php bin/console make:messenger:dashboard
```

It creates `src/Controller/Admin/MessengerDashboardController.php`:

```php
#[AdminRoute('/messenger', name: 'messenger')]
final class MessengerDashboardController extends AbstractMessengerDashboardController
{
}
```

Options: `make:messenger:dashboard Queues --dir=src/Admin --route=queues`. Pick another `--route` if `/admin/messenger` is already taken (by zenstruck/messenger-monitor-bundle for example).

### 6. Add it to the menu

In your `DashboardController::configureMenuItems()`:

```php
yield MenuItem::linkTo(MessengerDashboardController::class, 'Messenger', 'fa fa-envelope')->setAction('overview');
```

The pages are now available under `/admin/messenger`.

### 7. Optional: enable the history

```yaml
# config/packages/easy_admin_messenger_manager.yaml
easy_admin_messenger_manager:
    history:
        enabled: true
        exclude:
            - App\Message\HighVolumeMessage
```

The `eamm_message_history` table is mapped as a Doctrine entity: create it with a migration.

```bash
php bin/console doctrine:cache:clear-metadata
php bin/console doctrine:migrations:diff
php bin/console doctrine:migrations:migrate
```

Restart the workers (`messenger:stop-workers`) so they start recording, and schedule the purge (step 8).

### 8. Schedule the purges

Run them daily with cron, supervisor or the Scheduler component:

```bash
php bin/console eamm:history:purge --older-than=30
php bin/console eamm:failed:purge --older-than=90 --force
```

## Configuration

All keys are optional; defaults shown.

```yaml
easy_admin_messenger_manager:
    max_scan: 10000              # non-Doctrine transports: messages read to filter and paginate
    breakdown_sample: 1000       # most recent failures used for the class/exception/origin breakdown
    batch:
        transport: null          # transport receiving batch jobs; null = handled during the request
        chunk_size: 200
    permissions:
        view: ROLE_ADMIN
        retry: ROLE_ADMIN
        remove: ROLE_ADMIN
    redaction:
        keys: [password, token, secret, apiKey, authorization]
    history:
        enabled: false
        connection: default
        retention_days: 30       # default of eamm:history:purge --older-than
        capture_payload: true
        exclude: []              # classes or interfaces never recorded
```

Set `batch.transport` to an async transport (`async`) when you plan to requeue or remove thousands of messages at once.

## Commands

| Command | Options |
|---|---|
| `make:messenger:dashboard [class]` | `--dir`, `--route` |
| `eamm:failed:purge` | `--older-than=<days>`, `--class`, `--exception`, `--origin`, `--transport`, `--dry-run`, `--force` |
| `eamm:history:purge` | `--older-than=<days>`, `--status=handled\|retrying\|failed`, `--class`, `--transport`, `--dry-run` |

## Customization

Override the protected methods of the generated controller:

```php
#[AdminRoute('/messenger', name: 'messenger')]
final class MessengerDashboardController extends AbstractMessengerDashboardController
{
    protected function hiddenTransports(): array { return ['sync']; }
    protected function messagesPerPage(): int { return 100; }
    protected function refreshSeconds(): int { return 30; }
    protected function describeMessage(MessageView $view): string { return $view->shortClass(); }
}
```

- Templates: override any file of `@EasyAdminMessengerManager/` in `templates/bundles/EasyAdminMessengerManagerBundle/`
- Translations: domain `EasyAdminMessengerManager` (English and French provided)
- Permissions: change the roles in the configuration, or add your own voters on `EAMM_VIEW`, `EAMM_RETRY`, `EAMM_REMOVE`

## Troubleshooting

- **Buttons and filters do nothing**: the Stimulus `live` controller is not loaded on admin pages (step 3).
- **The JSON viewer stays empty**: run `assets:install` (step 4).
- **`doctrine:migrations:diff` sees no history table**: clear the metadata cache (`doctrine:cache:clear-metadata`, and OPcache first if PHP-FPM keeps the old mapping).
- **"route already exists"**: another bundle uses the same admin path, regenerate the controller with `--route`.

## Good to know

- Only listable transports can be browsed: Doctrine, and Redis since Symfony 8.1. AMQP only shows a count. Non-Doctrine transports are read up to `max_scan` messages, a notice says so.
- Text filters on a Doctrine transport search the serialized message, so they scan the whole table: count ~2 s for 200 000 messages.
- A message whose class no longer exists is shown as undecodable: it can be removed, not retried.
- Retrying runs the handler again, side effects included.

## Development

```bash
composer install
vendor/bin/phpunit
```

## License

MIT. Ships [@alenaksu/json-viewer](https://github.com/alenaksu/json-viewer) 2.1.2 (MIT) in `public/vendor/json-viewer/`.
