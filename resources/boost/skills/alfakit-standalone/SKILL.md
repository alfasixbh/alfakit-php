---
name: alfakit-standalone
description: Use AlfaKit forms, tables, infolists, actions and widgets outside the admin panel, in the application's own Inertia pages, routes and layout — Standalone\Form, Table, Infolist, Widgets and Component classes rendered with <AlfakitComponent>. Use when a feature needs a validated form, a data table or actions on a non-admin page.
---

# AlfaKit outside panels (standalone components)

## When to use this skill

Use it when a page of the application itself (not the admin panel) needs an AlfaKit form, table, infolist, action buttons or widgets: a contact form, a customer's orders table, an account detail view, a stats row. The component is defined in PHP, passed to `Inertia::render()` as a prop and rendered by `<AlfakitComponent>` with the app's own layout and shadcn tokens. No `createAlfakit()`, no panel layout.

## Setup (once per app)

- Styles: in `resources/css/app.css` add `@import '@alfakit/react/standalone.css';` (or `vue` / `svelte`) and `@source '../../node_modules/@alfakit/react/dist';` after `@import 'tailwindcss';`. The `standalone.css` stylesheet defines no colour palette: components inherit the app's shadcn tokens. It only adds `--success`, `--warning`, `--info` and a white danger-button label inside AlfaKit views (override with `--alfakit-danger-foreground`), since the starter kits set `--destructive-foreground` equal to `--destructive`. `php artisan alfakit:install --standalone` does this and creates an example form and page.
- Toaster: place `<AlfakitToaster />` once in the app layout (React: also fine inside `createInertiaApp({ withApp })`). If the layout already mounts a sonner `<Toaster>` from the same package instance, use `<AlfakitToaster toaster={false} />` (`:toaster="false"` in Vue) to avoid duplicate toasts. Without any toaster the first `<AlfakitComponent>` renders one.
- Config: `config/alfakit.php` → `standalone` (`enabled`, `path`, `middleware`, `auth_middleware`, `guard`, `guest_uploads`). The hidden `standalone` panel registers only `/_alfakit/*` internal endpoints.

## Generators

`php artisan make:alfakit-form ContactForm`, `make:alfakit-table OrdersTable --model=Order`, `make:alfakit-infolist OrderDetails --model=Order`, `make:alfakit-component AccountOverview`. Classes land in `app/Alfakit/Standalone`.

`php artisan make:alfakit-page Orders --standalone [--model=Order] [--adapter=react|vue|svelte] [--no-frontend]` writes a `Standalone\Component` plus the Inertia page that renders it (`resources/js/pages/orders.tsx`, `Orders.vue`, `Orders.svelte`) and prints the route.

## One line in the controller

`AlfaSix\Alfakit\Facades\Alfakit::render('orders', ['stats' => OrderStats::class, 'orders' => [OrdersTable::class, ['customer' => $customer]], 'title' => 'Orders'])` is `Inertia::render()` that builds standalone component classes with `make()` (a `[Class, parameters]` pair passes parameters; other values pass through). Built components (`OrdersTable::make([...])`) work too.

## A form

```php
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Forms\Components\Textarea;
use AlfaSix\Alfakit\Notifications\Notification;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Standalone\Form;

class ContactForm extends Form
{
    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('email')->email()->required(),
            Textarea::make('message')->required()->minLength(10),
        ]);
    }

    public function submit(array $data): mixed
    {
        ContactRequest::create($data); // validated, uploads stored

        Notification::make()->success()->title(__('Thanks!'))->send();

        return back();
    }
}
```

Controller: `Inertia::render('contact', ['form' => ContactForm::make()->fill(['email' => $user?->email])])`. Page (React): `<AlfakitComponent component={form} className="max-w-xl" />`; Vue: `<AlfakitComponent :component="form" />`; Svelte: `<AlfakitComponent component={form} />`. Import `AlfakitComponent` and the `StandaloneComponentSchema` type from `@alfakit/react|vue|svelte`.

- `->submitLabel('Send')`, `->submitKeyBindings(['mod+s'])`; more buttons: override `getFormActions()` and include `$this->getSubmitAction()`.
- Edit a record: `ProfileForm::make(['record' => $user])` or `->record($user)`; `submit()` then calls `$this->getRecord()->update($data)`.
- Relationship fields are saved after `submit()`: to the bound record, or on create to the model `submit()` returns (`return Post::create($data);`). On create the schema needs `$schema->model(Post::class)`. `$data` excludes relationship fields.
- Empty the form after submit: `$this->reset()` (or `$this->reset(['topic' => 'sales'])`) inside `submit()`, or `ContactForm::make()->resetAfterSubmit()` for every submit; the next render uses defaults instead of `fill()` data or the record.
- Generators that build fields or columns from a model's table use `AlfaSix\Alfakit\Support\SchemaGenerator::formComponents(Post::class)` / `::tableColumns(Post::class)` (`['code' => string, 'imports' => list<string>]`).

## A table

```php
use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Actions\BulkAction;
use AlfaSix\Alfakit\Standalone\Table;
use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tables\Table as TableBuilder;

class OrdersTable extends Table
{
    public Customer $customer; // filled from make(['customer' => $customer]), resolved by route key on every request

    public function table(TableBuilder $table): TableBuilder
    {
        return $table
            ->query(Order::query()->where('customer_id', $this->customer->getKey()))
            ->columns([TextColumn::make('number')->searchable()->sortable(), TextColumn::make('status')->badge()])
            ->recordActions([Action::make('cancel')->requiresConfirmation()->action(fn (Order $record) => $record->cancel())])
            ->bulkActions([BulkAction::make('export')->action(fn (Collection $records) => Export::dispatch($records))]);
    }
}
```

Sort, search, filters and pagination live in the host page's query string; the controller simply calls `OrdersTable::make([...])` again. Record and bulk actions, inline edits, reordering, `deferLoading()` and `poll()` work as in panels.

## Infolists, widgets, headless components

- `Standalone\Infolist`: `infolist(Schema $schema)` plus `actions()`; bind the record with `make(['record' => $order])`.
- `Standalone\Widgets`: `widgets()` returns widget classes or instances; `widgetColumns()` sets the grid. Lazy widgets and polling are fetched from `/_alfakit/standalone/components` by token. Plugin widgets work too (`KanbanWidget`, `CalendarWidget`): list the plugin in `config('alfakit.standalone.plugins')` (`[CalendarPlugin::class]`) or call `Alfakit::getStandalonePanel()->plugin(CalendarPlugin::make())` in a provider so its options and frontend translations apply.
- `Standalone\Component`: any mix of `form()`, `table()`, `infolist()`, `content()`, `actions()`, `widgets()`, `footerWidgets()`; `heading()` / `subheading()` on the payload.
- Parameters: `make()` takes named parameters; each is assigned to a public property of the same name and passed to `mount(mixed ...$parameters)`. `record` and `tenant` are reserved (`->tenant($team)` restores the tenant on internal requests; membership is re-checked each time through the guard user's `HasTenants::canAccessTenant()`, 404 otherwise and guests fail closed; override `canAccessTenant(Model $tenant): bool` on the component to change that). Guest uploads: `alfakit.standalone.guest_uploads` (default true, throttled, per guest session); set it to false to require the standalone guard.
- Access: `requiresAuthentication()` (static, default `false`), `canAccess()` (static), `authorize()` (instance, after mount). All are re-checked on every internal request; tokens are bound to the user.

## Frontend customisation

- Global renderer overrides: `configureAlfakit({ components: { TextInput: MyInput, 'map-picker': MapPicker } })` once in the app entry. Per instance: `<AlfakitComponent components={{ TextInput: MyInput }} />`.
- Layout: `className` / `class` on the wrapper; `header` and `footer` (React props, Vue slots, Svelte snippets); render the blocks yourself with the children render function (React), default slot (Vue) or `children` snippet (Svelte) and `ContentBlockRenderer`.
- Hooks inside custom components work as in panels (`useT()`, `useFormatter()`, `useField()`, `usePanel()`, `usePageSchema()`).

## Inside a `createAlfakit()` app and SSR

- A page registered with `createAlfakit({ pages })` that carries no `alfakit` prop (the rest of the app, rendered by the same entry) can embed `<AlfakitComponent>`: the component then applies its own translations and locale, and toasts show through the app's toaster.
- `createAlfakitServer({ pages })` server-renders those registered pages too (not only panel pages), standalone components included; pages that are neither go to `fallback` or render in the browser.

## Testing

Read the payload from the page (`get('/contact', \AlfaSix\Alfakit\Testing\PageTester::inertiaHeaders())->json('props.form')`), then post to `/_alfakit/actions` with `token`, `action` (`submit` for forms; table actions add `'component' => 'table'` and `record` or `bulk`/`records`) and `data`. `OrdersTable::make([...])->toPayload()` works in unit tests.

## App auth kit (sign-in and account pages for the app's users)

`php artisan alfakit:auth [--adapter=] [--mfa] [--fortify|--no-fortify] [--prefix=account] [--home=/dashboard]` writes `app/Providers/AuthKitServiceProvider.php`, adds `\AlfaSix\Alfakit\AuthKit\AuthKit::routes();` to `routes/web.php` and two Inertia pages (`auth/alfakit.tsx` gets `component` + `authKit`; `settings/alfakit.tsx` gets `sections` + `authKit`; Vue/Svelte `auth/Alfakit`, `settings/Alfakit`). With Fortify installed it always sets `'views' => false` in `config/fortify.php` (the auth kit owns `login`, `verification.notice`, `two-factor.login`…; duplicate names break Wayfinder), and the kit's `verification.verify` takes over Fortify's `/email/verify/{id}/{hash}` URI. It needs the app's Inertia frontend (`@inertiajs/<adapter>` in package.json) and stops without one, as `alfakit:install --standalone` does.

```php
use AlfaSix\Alfakit\Auth\MultiFactor\App\AppAuthentication;
use AlfaSix\Alfakit\AuthKit\AuthKit;
use AlfaSix\Alfakit\AuthKit\AuthKitPanel;

AuthKit::configure(fn (AuthKitPanel $auth) => $auth           // in a provider's boot()
    ->inertiaPages(guest: 'auth/alfakit', account: 'settings/alfakit')
    ->homeUrl(fn (): string => route('dashboard'))
    ->multiFactorAuthentication([AppAuthentication::make()->recoverable()])
    ->invitationOnlyRegistration()                               // optional
    ->sessions()->accountDeletion()                              // on by default
    ->fortify());                                                // Fortify's actions, authenticateUsing() and 2FA
AuthKit::routes();                                               // routes/web.php; routes(prefix: 'kit', as: 'kit.')
```

- Routes use Laravel's names: `login`, `logout`, `register`, `password.request`, `password.reset`, `verification.notice`, `verification.verify`, `two-factor.login`, `account`; internal endpoints `/_alfakit/auth/*` (`alfakit.auth-kit.internal.*`). With `route:cache` set `->routeOptions(prefix:, as:)` in `configure()`. To keep a Fortify starter kit's own sign-in and add only the account page: `->routeOptions(prefix: 'account', as: 'account.')->login(false)->registration(false)->passwordReset(false)->emailVerification(false)` and `'views' => true` in `config/fortify.php` (the command sets it to false).
- `AuthKitPanel` (id `auth-kit`) is a hidden panel: `login(false)`, `registration(false)`, `passwordReset(false)`, `emailVerification(false)`, `profile(false)` (account page), `emailChangeVerification()`, `multiFactorAuthenticationRememberDevice()`, `authGuard()`, `authPasswordBroker()`, `locales([...])` (then it resolves the user's language itself), `components(['login' => MyLogin::class, 'profile' => MyProfile::class])` (keys `login`, `register`, `forgot-password`, `reset-password`, `verify-email`, `two-factor-challenge`, `profile`, `password`, `two-factor`, `sessions`, `delete-account`; classes in `AlfaSix\Alfakit\AuthKit\Pages` and `...\AuthKit\Account`). It never calls `CanAccessPanel`.
- Tests: `get('/login', PageTester::inertiaHeaders())->json('props.component.token')` (`AlfaSix\Alfakit\Testing\PageTester`; a bare `X-Inertia` header answers 409 once the app has a Vite build), then post to `/_alfakit/auth/actions` with `action` `authenticate` / `register` / `request` / `reset` / `verify`, or the account sections' `submit`, `logoutOtherSessions`, `deleteAccount`.
- No passkeys (WebAuthn) in AlfaKit yet.
