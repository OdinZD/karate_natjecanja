# Prompt za Claude Code

**Prije prvog pokretanja:** raspakiraj `redizajn-arena.zip` u repozitorij kao `docs/redizajn-arena/`, tako da postoje `docs/redizajn-arena/DESIGN.md`, `PLAN.md`, `arena.css`, `izvjesce-za-redizajn.md` i mapa `reference/`. Zatim u Claude Codeu u korijenu projekta zalijepi tekst ispod crte.

---

Radimo potpuni redizajn ove Laravel aplikacije („Organizacija natjecanja”, lokalna karate natjecanja po HKS pravilima) u odabranom smjeru **Arena**. Sav materijal je u `docs/redizajn-arena/`.

## Pročitaj prvo, ovim redom

1. `docs/redizajn-arena/izvjesce-za-redizajn.md`: što aplikacija radi, uloge, ekrani, ograničenja.
2. `docs/redizajn-arena/DESIGN.md`: dizajn sustav, komponente, specifikacija svakog ekrana, prijedlozi (§6).
3. `docs/redizajn-arena/PLAN.md`: faze rada s kriterijima „gotovo kad”.
4. `docs/redizajn-arena/arena.css`: tokeni i oblici za Tailwind 4, kopira se u `resources/css/`.
5. `docs/redizajn-arena/reference/README.md`, a zatim referentni HTML ekrana kad radiš na tom ekranu. Iz njih prepisuj točne vrijednosti. `<x-dc>`, `<sc-for>`, `<sc-if>` i `{{…}}` su format alata za dizajn (petlja/uvjet), a podaci u `<script type="text/x-dc">` su izmišljeni primjeri.

## Tvrda ograničenja (ne smiju se prekršiti)

- Ostaje Blade + Livewire 4 + Flux UI (besplatna verzija) + Tailwind CSS 4 + Vite. Bez SPA frameworka. Vue 3 samo kao otok ako to izričito odobrim (vidi `DESIGN.md` §7).
- **Ne mijenjaj** rute ni njihova imena, Livewire svojstva (`wire:model`) i javne metode, ni `data-test="…"` atribute. Testovi ovise o njima. Ako misliš da nešto od toga treba promijeniti, pitaj me prije.
- Svi tekstovi sučelja idu kroz `__('English key')`, a hrvatski prijevod u `lang/hr.json`.
- Javne stranice zadržavaju `wire:poll.10s.visible`.
- Svijetla i tamna tema moraju raditi svugdje. Makni prisilni `class="dark"` iz layouta `sidebar` i `auth`.
- Pristupačnost: kontrast AA, ciljevi dodira 44 px, stanje nikad samo bojom, fokus vidljiv, `prefers-reduced-motion` gasi animacije.
- PDF ispisi (dompdf) nisu dio redizajna.
- Ne izmišljaj funkcije. Sve iz `DESIGN.md` §6 (PRIJEDLOZI) traži moje odobrenje prije backend rada. Do tada implementiraj samo izgled postojećih funkcija.
- Nigdje ne piši heksadecimalne boje u Bladeu. Koristi Arena klase iz `arena.css`. AKA je uvijek `arena-aka`, AO uvijek `arena-ao`.

## Način rada

- Radi **fazu po fazu** iz `PLAN.md`. Na početku svake faze kratko napiši plan (koje datoteke, što se mijenja), pa tek onda mijenjaj kod.
- Na kraju svake faze pokreni `php artisan test` i `npm run build` (i `vendor/bin/pint --dirty` ako postoji). Usporedi rute i `data-test` popise s Fazom 0. Zatim **stani** i napiši mi sažetak: što je gotovo, što je otvoreno, što trebam pogledati u pregledniku.
- Ako test padne zbog promjene markupa, popravi markup tako da test opet prolazi. Ne mijenjaj test bez mog odobrenja.
- Commitaj po fazama na grani `feature/arena-redizajn`. **U commit porukama nemoj dodavati `Co-Authored-By` ni druge trailere s potpisom Claudea.**
- Ako ti nešto u kodu proturječi izvješću ili dizajnu (npr. drugačije ime svojstva, drugačiji tijek), vjeruj kodu, zapiši razliku i pitaj me.

## Prvi zadatak

Napravi **Fazu 0 (inventura)** iz `PLAN.md`, bez promjena koda. Zatim mi predloži plan za **Fazu 1** s popisom datoteka koje ćeš mijenjati, i čekaj moje „može”.
