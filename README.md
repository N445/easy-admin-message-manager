# EasyAdmin Messenger Manager (EAMM)

Manage Symfony Messenger from EasyAdmin: queue overview, every pending or failed message with its cause, single and batch retry or removal, optional processing history.

- **Overview**: message count per transport with its trend and the age of its oldest message (a queue waiting too long is flagged), last 24 h statistics linking to the history, failures grouped by class, exception and original transport; refreshes itself while the tab is visible (pause button, paused while you select text or type in a filter)
- **Messages**: every listable transport (Doctrine, Redis ≥ 8.1), pending and in-progress messages with their queued date and age, filters, pagination, detail page, removal of one message or of a selection
- **Failed messages**: filters (class, original transport, exception, date range), stack trace, single "Run now" (handler runs in the request, immediate result), batch requeue or removal of the selection or of **everything matching the filter**
- **History** (opt-in): handled / retrying / failed attempts with duration, memory, error, trace and message content, filtered by status, class, transport, error, period, minimum duration or tracking id
- **Timeline** (with the history): every dispatched message gets a tracking id, kept across retries and requeues; the message page lists all its attempts
- Message and stamps shown in a JSON viewer: fold/unfold, search, copy
- Doctrine transports are queried in SQL: it stays fast with hundreds of thousands of messages
- Permissions per action (`EAMM_VIEW`, `EAMM_RETRY`, `EAMM_REMOVE`), payload redaction (configured keys and `#[\SensitiveParameter]`)
- Built with Symfony UX Live Components, light and dark themes follow EasyAdmin

## Requirements

- PHP 8.4
- Symfony 7.4 or 8.x
- EasyAdmin 5 with pretty admin URLs (the default since EasyAdmin 5)
- Symfony UX Live Components, with the Stimulus `live` controller loaded on admin pages
- Doctrine DBAL 4.5+ (the history table joins the ORM schema when doctrine/orm 3.6.8+ is installed)
- A Doctrine failure transport is recommended (`doctrine://default?queue_name=failed`): it is the only one that can be listed, filtered and paginated at any volume

## Installation

### 1. Install the package

```bash
composer require robin/easy-admin-messenger-manager
```

With Symfony Flex, the recipe registers the bundle, creates `config/packages/easy_admin_messenger_manager.yaml` and `composer install` publishes the assets. Without Flex, add the bundle to `config/bundles.php` and run `php bin/console assets:install public`:

```php
return [
    // ...
    EasyAdminMessengerManager\EasyAdminMessengerManagerBundle::class => ['all' => true],
];
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

### 2. Generate the dashboard controller

```bash
php bin/console eamm:make:dashboard
```

It creates `src/Controller/Admin/MessengerDashboardController.php`:

```php
#[AdminRoute('/messenger', name: 'messenger')]
final class MessengerDashboardController extends AbstractMessengerDashboardController
{
}
```

Options: `eamm:make:dashboard Queues --dir=src/Admin --route=queues`. Pick another `--route` if `/admin/messenger` is already taken (by zenstruck/messenger-monitor-bundle for example).

Then add it to the menu in your `DashboardController::configureMenuItems()`:

```php
yield MenuItem::linkTo(MessengerDashboardController::class, 'Messenger', 'fa fa-envelope')->setAction('overview');
```

The pages are now available under `/admin/messenger`.

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

### 4. Optional: enable the history

```yaml
# config/packages/easy_admin_messenger_manager.yaml
easy_admin_messenger_manager:
    history:
        enabled: true
        exclude:
            - App\Message\HighVolumeMessage
```

The bundle ships its own migration (like other bundles providing tables): with DoctrineMigrationsBundle installed it is registered automatically under the `EasyAdminMessengerManager\Migrations` namespace, nothing is generated in your project.

```bash
php bin/console doctrine:migrations:migrate
```

The table is also declared in the Doctrine schema of the history connection, so `doctrine:migrations:diff` neither recreates nor drops it.

Without migrations, create it directly (`--dump-sql` prints the SQL instead):

```bash
php bin/console eamm:history:setup
```

Restart the workers (`messenger:stop-workers`) so they start recording, and schedule the purge (step 5).

With the history enabled, the bundle adds an `EammMessageIdStamp` (a UUID v7) to every message when it is first sent to a transport. The stamp survives retries, the failure transport and requeues, and the history stores it in `message_id`: the message page shows its timeline. Messages dispatched before the history was enabled have no tracking id, their page says so.

### 5. Schedule the purges

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
        transport: null          # transport receiving batch jobs (must exist); null = handled during the request
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
        exclude: []              # existing classes or interfaces never recorded
```

Set `batch.transport` to an async transport (`async`) when you plan to requeue or remove thousands of messages at once. An unknown transport or excluded class fails at container compilation with the list of valid values.

Transports using the `sync://` DSN are never shown. Hide others with `hiddenTransports()` (see Customization): they disappear from every tab, the origin filter, and their message pages return 404.

## Commands

| Command | Options |
|---|---|
| `eamm:make:dashboard [class]` | `--dir`, `--route` |
| `eamm:history:setup` | `--dump-sql` |
| `eamm:failed:purge` | `--older-than=<days>`, `--class`, `--exception`, `--origin`, `--transport`, `--dry-run`, `--force` |
| `eamm:history:purge` | `--older-than=<days>`, `--status=handled\|retrying\|failed`, `--class`, `--transport`, `--dry-run` |

## Customization

Override the protected methods of the generated controller:

```php
#[AdminRoute('/messenger', name: 'messenger')]
final class MessengerDashboardController extends AbstractMessengerDashboardController
{
    protected function hiddenTransports(): array { return ['internal']; }
    protected function messagesPerPage(): int { return 100; }
    protected function refreshSeconds(): int { return 30; }
    protected function stuckAfterSeconds(): int { return 3600; }
}
```

`refreshSeconds()` returning 0 disables the auto-refresh. `stuckAfterSeconds()` (default 900) flags a queue whose oldest message waits longer than that; 0 disables it.

- Templates: override any file of `@EasyAdminMessengerManager/` in `templates/bundles/EasyAdminMessengerManagerBundle/`
- Translations: domain `EasyAdminMessengerManager` (English and French provided)

### Columns, row actions and labels

Implement `MessageViewCustomizer`: with autoconfiguration (the default), the service is picked up without any configuration. Several customizers can coexist; the first label that is not null wins.

```php
namespace App\Admin;

use App\Message\RefreshAlbumStats;
use EasyAdminMessengerManager\Customization\MessageAction;
use EasyAdminMessengerManager\Customization\MessageColumn;
use EasyAdminMessengerManager\Customization\MessageViewCustomizer;
use EasyAdminMessengerManager\Message\MessageView;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final readonly class AlbumMessagesCustomizer implements MessageViewCustomizer
{
    public function __construct(private UrlGeneratorInterface $urls)
    {
    }

    public function columns(): iterable
    {
        yield new MessageColumn('Album', static fn (MessageView $view): string => (string) self::albumId($view));
    }

    public function actions(MessageView $view): iterable
    {
        $albumId = self::albumId($view);
        if (null === $albumId) {
            return [];
        }

        yield new MessageAction('Open the album', $this->urls->generate('album_show', ['id' => $albumId]), 'fa fa-images');
        yield new MessageAction('Edit', $this->urls->generate('admin_album_edit', ['entityId' => $albumId]), permission: 'ROLE_EDITOR');
    }

    public function label(MessageView $view): ?string
    {
        $albumId = self::albumId($view);

        return null === $albumId ? null : \sprintf('Album stats #%d', $albumId);
    }

    private static function albumId(MessageView $view): ?int
    {
        $message = $view->envelope->getMessage();

        return $message instanceof RefreshAlbumStats ? $message->albumId : null;
    }
}
```

- `columns()`: extra columns of the failed and pending message tables. Values are escaped; labels are translated with the `messages` domain.
- `actions()`: links shown on each row and on the message page. `permission` is checked with `is_granted($permission, $view)`.
- `label()`: replaces the short class name in the tables and on the message page; `null` keeps it.

### Permissions

Change the roles in the configuration, or add your own voters on `EAMM_VIEW`, `EAMM_RETRY` and `EAMM_REMOVE`. Row buttons and single actions pass the `MessageView` as subject, so a voter can decide per message class:

```php
final class SupportVoter extends Voter
{
    protected function supports(string $attribute, mixed $subject): bool
    {
        return 'EAMM_RETRY' === $attribute && $subject instanceof MessageView;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        return $subject->envelope->getMessage() instanceof RefreshAlbumStats
            && \in_array('ROLE_SUPPORT', $token->getRoleNames(), true);
    }
}
```

Batch actions (selection, every matching message) and `EAMM_VIEW` are checked without subject. With the default `affirmative` strategy a voter can only grant more; to forbid an action for some classes to users who hold the configured role, set `security.access_decision_manager.strategy` to `unanimous`.

## Troubleshooting

- **Buttons and filters do nothing**: the Stimulus `live` controller is not loaded on admin pages (step 3); the dashboard shows a warning banner in that case.
- **The JSON viewer stays empty, or a banner asks for `assets:install`**: run `php bin/console assets:install public` (again after each update of the bundle).
- **The EAMM migration is not listed by `doctrine:migrations:status`**: `history.enabled` must be true and DoctrineMigrationsBundle installed; the migration runs on the connection used by your migrations, which must be `history.connection`.
- **`doctrine:migrations:diff` wants to drop `eamm_message_history`**: `history.connection` is not the connection being diffed, or a `schema_filter` excludes the table.
- **"route already exists"**: another bundle uses the same admin path, regenerate the controller with `--route`.

## Good to know

- Only listable transports can be browsed: Doctrine, and Redis since Symfony 8.1. AMQP only shows a count. Non-Doctrine transports are read up to `max_scan` messages, a notice says so.
- The queued date comes from `created_at` on Doctrine and from the entry id on Redis; other transports do not expose it. Removing a pending message rejects it from its transport: a worker may be processing it at that moment.
- Text filters on a Doctrine transport search the serialized message, so they scan the whole table: count ~2 s for 200 000 messages.
- A message whose class no longer exists is shown as undecodable: it can be removed, not retried.
- "Run now" runs the handler again in the web request, side effects included; "Requeue" sends the message back to its original transport for the workers.

## Flex recipe

The `recipe/` folder holds the Symfony Flex recipe (bundle registration, commented configuration, post-install instructions). Once the package is on Packagist, it is submitted to [symfony/recipes-contrib](https://github.com/symfony/recipes-contrib) as `robin/easy-admin-messenger-manager/1.0/`; until then, register the bundle and create the configuration by hand (step 1).

## Development

```bash
composer install
vendor/bin/phpunit
```

## License

MIT. Ships [@alenaksu/json-viewer](https://github.com/alenaksu/json-viewer) 2.1.2 (MIT) in `public/vendor/json-viewer/`.
