# Faza 0: inventura (stanje prije redizajna)

Snimke: `routes-before.txt` (72 rute) i `data-test-before.txt` (91 atribut, bez brojeva redaka).

Polazno stanje: `php artisan test` 501 test (499 prolazi, 2 preskočena, 0 pada), `pint --test` prolazi, `npm run build` prolazi.

## Naredbe za usporedbu

```bash
php artisan route:list --json | jq -r '.[] | select(.uri|test("^livewire-")|not) | "\(.method)\t\(.uri)\t\(.name // "-")\t\(.action)"' | sort -k2 | diff docs/redizajn-arena/routes-before.txt -
grep -roE 'data-test="[^"]*"' resources/views | sed 's/^resources\/views\///' | sort | diff docs/redizajn-arena/data-test-before.txt -
```

`route:list --except-vendor` iz PLAN.md ne vrijedi: `Route::livewire` rute su vendorske pa prikaže samo 7 ruta. Rute `livewire-<hash>` se izbacuju jer se hash mijenja.

## Markup o kojem ovise testovi (osim data-test)

- `data-stat="…">N<` (HomeAndDetailsTest) i `data-rank` (standings).
- `<span class="ps-num">—</span>` (CategoriesAndCompetitorsTest:213).
- Na Detaljima točno jedan `aria-current="page"`, na `tab-show`.
- Točno jedan `wire:poll` po javnoj stranici, na `data-test="live-region"` (LivePollingTest).
- `wire:click="undo(ID)"` u konzoli tatamija (ConsoleTest).
- Redoslijed tekstova na početnoj: „Current and upcoming” prije „Finished” (assertSeeInOrder).

## Stranice, layouti, komponente

- Javno (`layouts::public`): `home`, `public.competitions.{show,categories,competitors,draw,bracket,timetable,results,standings}`.
- Admin (`layouts/app/sidebar`): `admin.competitions.{index,show,categories,entries,draw,bracket,timetable}`, `admin.clubs.index`, `admin.competitors.index`, `admin.users.index`, uz PDF/XLSX rute.
- Konzola (isti sidebar): `tatami.index`, `tatami.console`.
- Postavke: `profile.edit`, `appearance.edit`, `security.edit`. Auth: Fortify prikazi u `pages/auth/*` preko `layouts/auth/simple`.
- `dashboard` je `Route::view`, ne Livewire.
- Nekorišteni layouti: `app/header`, `auth/card`, `auth/split` (svi s `class="dark"`).
- Komponente: `bracket`, `bracket-bout` (12 fiksnih zinc/red/blue/emerald klasa), `public/{competition-header,status,progress,updates}`, `app-logo*`, `desktop-user-menu`, `placeholder-pattern` (samo dashboard).

## CSS i fontovi

- `resources/css/app.css`: Flux import, `@custom-variant dark`, `@theme` (Instrument Sans, zinc paleta, accent), `.public-site` s `--ps-*` tokenima i `ps-*` klasama, na kraju CSS koji prepisuje boje stabla.
- Fontovi dolaze iz `laravel-vite-plugin/fonts` (`bunny()` u `vite.config.js`) i `@fonts` u `partials/head`, ne iz `<link>` taga. Zadani subset je samo `latin`, pa č ć đ š ž nemaju glifove.

## Domena i konzola

- `Category.sort_order` se koristi po tatamiju: `AssignCategoryToTatami` (max+1), `MoveCategory`, admin satnica `move(id, 'up'|'down')`.
- `Bout.finished_at` postoji (zapis, poništenje, bye).
- Konzola (`pages/tatami/⚡console`): svojstva `tatami`, `categoryId` (`wire:model.live`), `winner` (`$set`), `decision` (zadano `points`), `akaScore`, `aoScore`. Metode `record(int)`, `undo(int)`, `updatedCategoryId`, `progressColor`.

## Razlike kod ↔ dizajn (vjerovati kodu)

1. DESIGN §6 „promjena redoslijeda” i „ide na kraj reda” već postoje u backendu; treba samo izgled.
2. Za „kašnjenje” vremenska oznaka već postoji; ostaje PRIJEDLOG.
3. Dashboard je `Route::view`; za podatke u Fazi 6 treba odluka (Livewire stranica ili view composer).
4. Dizajn konzole ima „stanje veze”, a podataka za to nema.
5. `register` postoji, ali je registracija isključena (RegistrationDisabledTest); ne diramo.
