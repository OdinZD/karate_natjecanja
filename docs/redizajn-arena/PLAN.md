# Arena — plan implementacije

Redoslijed prati prioritete iz izvješća: (1) javne stranice na mobitelu, (2) konzola tatamija, (3) admin tijek rada, (4) dashboard. Svaka faza završava zelenim testovima, uspješnim buildom i pregledom prije sljedeće.

**Grana:** `feature/arena-redizajn` (ili prema konvenciji repozitorija).
**Commitovi:** jedan ili više po fazi, poruke na hrvatskom ili engleskom prema postojećoj konvenciji, **bez `Co-Authored-By` i drugih trailera s potpisom Claudea**.

Naredbe za provjeru na kraju svake faze (prilagoditi onome što projekt ima):

```bash
php artisan test            # cijeli test suite mora proći
npm run build               # Vite build bez grešaka
vendor/bin/pint --dirty     # samo ako je Pint instaliran
php artisan route:list --except-vendor > /tmp/routes-now.txt && diff docs/redizajn-arena/routes-before.txt /tmp/routes-now.txt
```

---

## Faza 0 — Inventura (bez promjena izgleda)

1. Snimi rute: `php artisan route:list --except-vendor > docs/redizajn-arena/routes-before.txt`.
2. Popiši sve `data-test` atribute i gdje su: `grep -rn 'data-test=' resources/views > docs/redizajn-arena/data-test-before.txt`.
3. Popiši Livewire stranice (`resources/views/pages/**/⚡*.blade.php`), layoute (`layouts/*`) i komponente (`x-bracket` …), te koja ruta koristi koju stranicu.
4. Provjeri trenutno stanje `resources/css/app.css` (postoji li već `@custom-variant dark`, Flux import, `ps-*` tokeni).
5. Provjeri kako se učitavaju fontovi (Bunny ili Google, gdje u layoutu).
6. Provjeri koristi li Category `sort_order` po tatamiju, imaju li Bout zapisi vremensku oznaku rezultata i koja su točna imena svojstava i metoda u konzoli tatamija.

**Gotovo kad:** postoje tri popisa u `docs/redizajn-arena/` i kratki sažetak nalaza u odgovoru. Bez promjena koda.

---

## Faza 1 — Temelj: tokeni, fontovi, teme

1. Dodaj `resources/css/arena.css` (iz paketa) i uvezi ga u `app.css`.
2. Učitaj fontove Big Shoulders (700–900), Inter (400–700) i JetBrains Mono (500–700) u layoutima `public`, `sidebar` i `auth`.
3. **Makni prisilni `class="dark"`** iz `layouts/sidebar` i `layouts/auth`, tako da admin i prijava slijede postavku Izgled (svijetla, tamna, sustav). Provjeri da Flux appearance i dalje radi.
4. Javni `ps-*` tokeni: preusmjeri ih na Arena varijable ili ih zamijeni klasama `arena-*` (bez promjene HTML strukture u ovoj fazi).
5. Ukloni neiskorišteni `layouts/app/header.blade.php` (s mrtvim gumbom „Search”) ako ga ništa ne koristi (provjeri `grep`).

**Gotovo kad:** stranice se otvaraju s novim fontovima i bojama u obje teme, nijedna ruta nije promijenjena, testovi prolaze.

---

## Faza 2 — Komponente

Izradi anonimne Blade komponente iz `DESIGN.md` §4 u `resources/views/components/arena/`: `logo`, `status`, `tatami`, `plate`, `medal`, `section-title`, `card`, `button`, `tabs`, `steps`, `stat`, `ticker`, `bout`, `empty`.

- Svaka komponenta: samo Arena klase, bez heksadecimalnih boja, s propsima iz specifikacije i ispravnim ARIA atributima.
- `x-bracket`: prepiši boje na `bg-arena-aka` / `bg-arena-ao` / tokene i zadrži isti API (props, slotovi). Izbriši CSS koji na javnim stranicama prepisuje boje stabla (jedan izvor istine).
- Po potrebi napravi probnu stranicu samo za lokalni razvoj (npr. ruta dostupna samo u `local` okruženju) koja prikazuje sve komponente u obje teme. Ne smije biti dostupna u produkciji.

**Gotovo kad:** komponente se prikazuju ispravno u obje teme, `x-bracket` testovi (ako postoje) prolaze, nema vizualnih regresija u logici.

---

## Faza 3 — Javne stranice (mobitel prvi)

Redoslijed: zaglavlje natjecanja + tabovi → Satnica (uživo) → Stablo → Ždrijeb popis → Rezultati → Poredak → Detalji → Kategorije → Natjecatelji → Početna.

Za svaku stranicu:
1. Otvori referentni HTML iz `reference/` i prepiši strukturu u Blade s Arena komponentama.
2. Zadrži `wire:poll.10s.visible`, sva Livewire svojstva i metode te sve `data-test` atribute (usporedi s `data-test-before.txt`).
3. Stabilni `wire:key` na svim petljama. Animacije samo pri prvom prikazu; „pop” rezultata s ključem koji uključuje vrijednost (`DESIGN.md` §3).
4. Svi novi tekstovi kroz `__('English key')` + prijevod u `lang/hr.json`.
5. Provjeri na širini 390 px i 1280 px, u svijetloj i tamnoj temi, i s uključenim `prefers-reduced-motion`.

Napomene:
- Satnica prikazuje **procjenu kraja kategorije**, ne štopericu borbe (aplikacija nema mjerenje vremena borbe).
- Mini traka „SADA NA T1” na stablu je fiksirana na dnu i ne smije prekrivati sadržaj (dodaj `padding-bottom`).

**Gotovo kad:** sve javne stranice su u Arena stilu, testovi prolaze, nijedan `data-test` nije izgubljen.

---

## Faza 4 — Konzola tatamija

1. `/tatami`: kartice tatamija operatera (danas uživo / sljedeće / završeno) i napomena za operatere bez tatamija.
2. `/tatami/{id}`: AKA ploča gore i AO dolje kao velike tipke za pobjednika, stepperi bodova, radio grupa odluke, „ZAPIŠI REZULTAT” onemogućen dok nema pobjednika, traka „Zapisano · … / Poništi”.
3. Stanja: „Još nema ždrijeba”, „Čeka pobjednike”, „Kategorija završena” (`A-KonzolaStanja`).
4. Optimistični prikaz odabira (Alpine) bez čekanja na server; zapis ostaje ista Livewire metoda.
5. Zadrži `data-test="current-bout"` i postojeće nazive svojstava i metoda.

**Gotovo kad:** operater može na telefonu odabrati pobjednika, unijeti bodove i odluku te zapisati i poništiti rezultat; testovi konzole prolaze.

---

## Faza 5 — Admin tijek rada

1. `x-arena.steps` na svim stranicama natjecanja (Detalji/Tatamiji, Kategorije, Prijave, Ždrijeb, Satnica, Dan natjecanja). Stanje koraka se računa iz postojećih podataka.
2. **Natjecanje:** makni red od 5 gumbi; „Javna stranica” + izbornik „⋯” (PDF, Obriši natjecanje s potvrdom). Forma detalja i tatamiji s operaterima na istoj stranici.
3. **Kategorije:** grupiranje po dobnoj skupini, pretraga, čipovi filtra, izbornik „Službene kategorije” (postojeća akcija uvoza), „Kopiraj s natjecanja”, „Nova kategorija”.
4. **Prijave:** jedan gumb „UVOZ ▾” za uvoz kluba i opći uvoz (postojeće modale zadržati, samo restilizirati), modal s greškama po retku kao na referenci. `data-test="import-club-entries-button"` mora ostati na elementu koji otvara uvoz kluba.
5. **Ždrijeb:** jedna glavna radnja po retku + „⋯” izbornik s onemogućenim stavkama i razlogom; panel nositelja; „Generiraj preostale”.
6. **Satnica:** blok „Bez tatamija” s T1–T4, stupci po tatamiju s trajanjem, krajem i PDF-om.
7. **Klubovi, Natjecatelji, Korisnici:** zajednički obrazac tablica + panel ili modal.
8. **Natjecanja:** tabovi statusa i modal „Novo natjecanje”.

**Gotovo kad:** cijeli tijek od novog natjecanja do satnice radi kroz korake bez reda gumbi, testovi prolaze.

---

## Faza 6 — Dashboard

Zamijeni prazni placeholder: aktivno natjecanje, koraci, KPI (borbe, kategorije, procjena kraja, prijave), tatamiji uživo, tablica kategorija. „Treba pažnju” i kašnjenje su PRIJEDLOZI, pa ih radi tek nakon odobrenja. Prazno stanje kad nema aktivnog natjecanja.

---

## Faza 7 — Autentikacija i postavke

Prijava, zaboravljena lozinka, reset, potvrda e-pošte i 2FA u istoj kartici (`A-Prijava`). Postavke s podizbornikom i karticama teme (`A-Postavke`). Provjeri passkeys i 2FA tok s postojećim testovima Fortifyja.

---

## Faza 8 — Prijedlozi (samo uz odobrenje)

Prema `DESIGN.md` §6: veliki ekran za dvoranu (nova ruta, eventualno Vue otok), „Treba pažnju”, kašnjenje, „Uvoz po klubovima”, „Proglasi”, redoslijed kategorija na tatamiju. Za svaki prijedlog prvo kratki plan (migracije, upiti, testovi), pa tek onda kod.

---

## Faza 9 — Završna provjera

- Usporedi rute i `data-test` popise s Fazom 0 (moraju biti isti ili samo prošireni).
- Pristupačnost prema `DESIGN.md` §8 (kontrast, fokus, tipkovnica, čitač zaslona na barem jednoj stranici svakog područja).
- Mobitel 390 px: javne stranice i konzola bez vodoravnog pomicanja stranice (osim stabla i tabova, koji se pomiču namjerno).
- `prefers-reduced-motion`: nema animacija.
- Svi novi tekstovi u `lang/hr.json`.
