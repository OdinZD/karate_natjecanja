# Izvješće o aplikaciji „Organizacija natjecanja“ (za potpuni redizajn)

## 1. Što je aplikacija
Web aplikacija za organizaciju **lokalnih karate natjecanja** (Hrvatska, HKS pravila). Jedna osoba (administrator) unosi sve klubove, natjecatelje i prijave u natjecanje. Klubovi se ne prijavljuju u aplikaciju, nego administratoru šalju Excel. Gledatelji i roditelji prate ždrijeb, raspored i rezultate na javnim stranicama bez prijave. Operateri na tatamijima unose rezultate borbi.

Podržane discipline: **Kata pojedinačno** i **Kumite pojedinačno**. Ekipne discipline (kata ekipno, kumite ekipno), para karate i pojasevi nisu podržani.

## 2. Tehnički okvir (ograničenja za redizajn)
- Laravel 13, PHP 8.3, **Livewire 4** (single-file komponente, stranice su u `resources/views/pages/**/⚡*.blade.php`), **Flux UI 2.13** (besplatna verzija), **Tailwind CSS 4**, Vite.
- Fontovi: **Instrument Sans** (cijela aplikacija) i **Barlow Condensed** (naslovi na javnim stranicama).
- Autentikacija: Laravel Fortify (prijava, zaboravljena lozinka, verifikacija e-pošte, 2FA, passkeys).
- PDF ispisi (dompdf), Excel (OpenSpout). Jezik sučelja je hrvatski (prijevodi u `lang/hr.json`, ključevi su engleski tekstovi u `__()`).
- Javne stranice se osvježavaju s `wire:poll.10s.visible`, bez websocketa.
- Redizajn treba ostati unutar Blade/Livewire/Flux/Tailwind pristupa. Nema SPA frameworka.

## 3. Uloge i područja
| Područje | Tko | Ulaz |
|---|---|---|
| **Javno** (bez prijave) | gledatelji, roditelji, treneri | `/` i `/competitions/{slug}/…` |
| **Administracija** | administrator | `/admin/…` |
| **Konzola tatamija** | operater tatamija (ili admin) | `/tatami`, `/tatami/{id}` |
| **Postavke računa** | svaki prijavljeni korisnik | `/settings/…` |

Natjecanje u statusu *Draft* nije vidljivo javnosti. Ostali statusi su: *Registration → Drawn → In progress → Finished*.

## 4. Cijeli tijek rada administratora
1. Kreira natjecanje (naziv, datum, vrijeme početka, mjesto, organizator, rok prijave, status).
2. Dodaje **tatamije** (broj, naziv) i dodjeljuje im operatere.
3. **Kategorije:** ručno, kopiranjem s drugog natjecanja ili jednim klikom **„Uvezi službene kategorije“** (skup Turnir 125, Prvenstvo Hrvatske 157, Kup Hrvatske 115 kategorija, HKS 2026, godišta se računaju prema godini natjecanja).
4. **Klubovi** i **natjecatelji** (ručno ili kroz uvoz).
5. **Prijave:** ručno, općim Excel uvozom (stupci Klub, Kategorija…) ili **uvozom kluba**. Admin preuzme „Predložak za klubove“ (XLSX, list „Prijave“ + list „Upute“), pošalje ga klubu, klub upiše djecu (Ime, Prezime, Spol, Datum rođenja, Težina, Kata DA, Kumite DA), a admin datoteku učita uz odabir kluba. Aplikacija sama bira kategoriju prema spolu, godištu i težini (najuža dobna skupina, najlakša težinska klasa koja paše, nikad Kata B). Ako je bilo koji redak neispravan, ništa se ne uvozi i ispiše se popis grešaka.
6. **Ždrijeb:** nositelji (opcionalno), generiranje po kategoriji ili „Generate all“, pregled ždrijeba, regeneriranje, brisanje (dok nema odigranih borbi).
7. **Time table:** dodjela kategorija tatamijima, procjena trajanja i vremena završetka (broj prijava × trajanje borbe).
8. **PDF:** ždrijeb jedne kategorije, svi ždrijebovi, time table (cijeli ili po tatamiju).
9. **Dan natjecanja:** operateri unose rezultate. Javne stranice se same osvježavaju.

## 5. Popis ekrana i što prikazuju

### Javni dio (layout „public“, svijetli/tamni prema postavci, vlastiti dizajn)
- **Početna `/`:** naslov „Natjecanja“, kratki opis, popis „Current and upcoming“ i „Finished“ (paginirano). Svaki redak (*fixture*) ima veliki datum (dan + mjesec), naziv, mjesto, organizatora, broj prijava i status (točka + tekst: Registration open / Draw done / Live / Finished). Live natjecanje ima bedž koji vodi na time table.
- **Zaglavlje natjecanja** (na svim podstranicama): link „All competitions“, veliki naslov, datum/mjesto/organizator/status te **horizontalni tabovi:** Details, Categories, Competitors, Draw, Time table, Results, Standings.
- **Details:** baner „The competition is under way“ (kad je u tijeku), tablica „When and where“ i „In numbers“ (kategorije, prijave, natjecatelji, klubovi, tatamiji), linkovi na Draw/Time table/Results.
- **Categories:** popis kategorija (naziv, disciplina, spol, godišta, težina, broj prijava, tatami, status ždrijeba).
- **Competitors:** pretraga po imenu, filter kluba, popis (ime, klub, kategorije), paginacija.
- **Draw:** blok „Being played now“ (označeni redovi) i popis svih kategorija sa statusom, a klik vodi na bracket.
- **Bracket (ždrijeb kategorije):** vodoravno pomično stablo kola (kartice borbi: AKA crvena točka, AO plava točka, rezultat, odluka, pobjednik označen), „Now“ oznaka na borbi koja se igra, repasaž za kumite (dvije polovice) i poredak (1., 2., 3. mjesto).
- **Time table:** po tatamiju: naslov „Tatami N“, procjena kraja, blok „Now playing“ (ploča AKA/AO s imenima i klubovima) i redoslijed kategorija s procjenom vremena.
- **Results:** filter discipline, kartice kategorija s postoljem (medalje 1/2/3, ime, klub) i oznakom „Finished/In progress“.
- **Standings:** medalje po klubovima (rang, klub, zlato/srebro/bronca, ukupno).

### Administracija (layout „sidebar“)
Lijevi sidebar: Dashboard, Tatami (ako korisnik ima tatamije), grupa Administracija (Natjecanja, Klubovi, Natjecatelji, Korisnici), korisnički izbornik s postavkama. Na mobitelu je gornja traka s hamburgerom i profilom.
- **Dashboard:** trenutno **prazan placeholder** iz starter kita (4 prazna okvira s dijagonalnim uzorkom).
- **Natjecanja:** tablica (naziv, datum, mjesto, status) + modal „New competition“.
- **Natjecanje (detalji):** red gumba (Categories, Entries, Draw, Time table, Delete competition), forma s detaljima i statusom, tablica tatamija s operaterima i modal za dodjelu operatera.
- **Categories:** tablica (naziv, disciplina, spol, godišta, težina, prijave), gumbi „Import official categories“, „Copy from competition“, „New category“, modal za unos/uređivanje.
- **Entries:** tablica prijava (natjecatelj, klub, kategorija, težina), filter po kategoriji i gumbi: „Template for clubs“, „Import club entries“, „Import from Excel“, „New entry“. Svaki uvoz ima modal s prikazom grešaka po retku.
- **Draw:** tablica kategorija (broj prijava, status ždrijeba, akcije: nositelji, generiraj, pogledaj, PDF, regeneriraj, obriši), „Generate all“, „All draws (PDF)“.
- **Bracket (admin):** pregled stabla jedne kategorije.
- **Timetable:** kategorije bez tatamija (gumbi po tatamiju), blokovi po tatamiju (broj borbi, trajanje, procjena kraja, PDF).
- **Clubs, Competitors, Users:** tablice s pretragom/filterom, modali za unos i uređivanje (korisnici imaju ulogu Administrator ili Tatami operator).

### Konzola tatamija (isti sidebar layout, za operatere)
- **Tatami lista:** kartice tatamija operatera (natjecanje, datum, broj, naziv).
- **Konzola tatamija:** odabir kategorije, zadnji rezultat s „Undo“, velika kartica **„Now“** s dvije velike tipke AKA (crveno) / AO (plavo) za odabir pobjednika, odluka (points, hantei, flags, kiken, shikkaku), bodovi AKA/AO i „Record result“. Ispod su popisi „Next“ i „Played“ te stanja: „No draw yet“, „Waiting for entrants“, „Category finished“. Namijenjena je radu na telefonu/tabletu uz tatami.

### Autentikacija i postavke
Prijava (e-pošta + lozinka + passkey), zaboravljena/reset lozinke, potvrda e-pošte, 2FA izazov. Layout je centrirana kartica s logom. Postavke: profil, sigurnost (lozinka, 2FA, passkeys, kodovi za oporavak), izgled (svijetlo/tamno/sustav), brisanje računa.

### PDF ispisi (zaseban, minimalan stil)
Ždrijeb kategorije (A4 landscape), svi ždrijebovi (stranica po kategoriji), time table (cijeli ili po tatamiju).

## 6. Domenski model i pravila (korisno za sadržaj sučelja)
- **Competition** (slug, status), **Club** (naziv, grad, država), **Competitor** (ime, prezime, spol, datum rođenja, klub), **Category** (naziv, disciplina, spol ili mješovita, godišta od–do, težina min/max, trajanje borbe, tatami, sort_order), **Entry** (natjecatelj u kategoriji, težina, nositelj), **Tatami**, **Bout**, **Placement**.
- Nazivi kategorija su po HKS-u, npr. „Kadeti - Kumite -52 kg“, „U10 - Mlađe učenice - Kata“, „Veterani 35+ - Kumite +85 kg“. U praksi je **100–160 kategorija po natjecanju**.
- **Ždrijeb:** jednostruka eliminacija, s nositeljima i s obzirom na klub. Kumite ima **repasaž** i dvije bronce, a kata jednu broncu. Kata koristi isti stablo-ždrijeb s odlukom „flags“.
- Odluke: Points, Hantei, Flags, Kiken, Shikkaku, Bye. Strane su **AKA (crveno)** i **AO (plavo)**, kao u karateu.
- Rang klubova: zlato, pa srebro, pa bronca. Klubovi s istim medaljama dijele rang (1, 2, 2, 4).
- Vrijeme borbe prema dobi: 60 s (mlađi), 90 s, 120 s (kadeti, juniori, veterani), 180 s (U21, seniori).

## 7. Trenutni vizualni dizajn
**Opći:** minimalistički, gotovo monokromatski (Flux zinc/neutral skala), akcent = crna u svijetlom, bijela u tamnom načinu. Logo je Heroicon „trophy“ ikona, naziv „Organizacija natjecanja“, favicon je zlatni pehar (SVG).

**Admin i konzola tatamija:** zadani izgled Flux komponenti (tablice, modali, gumbi, badge, callout). Ništa posebno nije stilizirano. Layouti `sidebar` i `auth` imaju `class="dark"` zapisan izravno na `<html>` (admin i prijava su stalno tamni, a postavka „Izgled“ to ne mijenja). Konzola tatamija koristi Tailwind crvenu/plavu za AKA/AO.

**Javne stranice** imaju vlastiti sustav (`.public-site`, klase `ps-*`, tokeni u `resources/css/app.css`):
- Svijetla paleta: pozadina `#f4f6f8`, površina `#fff`, tekst `#16202b`, linije `#d9dfe6`; **zelena „tatami“ akcentna `#2b6b55`**, AKA `#c8202b`, AO `#1c5bc8`, zlato/srebro/bronca, narančasta „live“ `#a84a00`. Tamna paleta je definirana za sve tokene.
- Tipografija: veliki **Barlow Condensed** naslovi (do 72 px), ostatak Instrument Sans, tabularni brojevi.
- Komponente: ploče sa zaobljenim rubom (14 px), redovi s min. visinom 44 px, tabovi s podvlakom, status točka s pulsiranjem za „live“, medalje kao krugovi (zlato puni, srebro puna obruba, bronca točkasta obruba), ploča AKA|AO.
- Pristupačnost: ciljevi dodira najmanje 44 px, status nikad samo bojom (uvijek tekst/oblik), fokus s 3 px obrubom, poštuje `prefers-reduced-motion`.

## 8. Poznate slabosti dizajna (prilike za redizajn)
1. **Dashboard je prazan** (starter-kit placeholder). Nema pregleda aktivnog natjecanja, brojki ni prečaca.
2. Admin izgleda generički i ne dijeli identitet s javnim dijelom (drugačije boje, fontovi, komponente).
3. Stranica natjecanja i stranica Prijave imaju **zakrčen red gumba** (5 akcija u zaglavlju), a tijek rada (kategorije → prijave → ždrijeb → time table) nije vođen koracima.
4. Bracket komponenta (`x-bracket`) koristi fiksne Tailwind boje, a javne stranice ih preslikavaju CSS-om. Dva izvora istine za boje.
5. Konzola tatamija radi, ali nije prilagođena brzom unosu uz tatami (male kontrole, nema velikog prikaza stanja).
6. Tablice s 100+ kategorija nemaju grupiranje (po disciplini/dobi/spolu), ni filter, ni pretragu.
7. Postoji i alternativni layout `layouts/app/header.blade.php` koji se **ne koristi** (uključuje mrtvi gumb „Search“).
8. Javna početna i detalji su informativni, ali nema istaknutog „sada na tatamijima“ prikaza za dvoranu (veliki ekran).

## 9. Što redizajn mora očuvati
- Sve **rute i njihova imena**, Livewire svojstva (`wire:model`) i metode, jer ih testovi i logika koriste.
- **`data-test="…"` atribute** na elementima (testovi ih traže, npr. `import-club-entries-button`, `current-bout`, `tab-draw`).
- Hrvatski prijevodi preko `__('English key')`, nove tekstove treba dodati u `lang/hr.json`.
- Pristupačnost (kontrast AA, 44 px, status ne samo bojom), rad na mobitelu (gledatelji i operateri), `wire:poll` na javnim stranicama.
- Izgled PDF-a je zaseban i nije dio ovog redizajna, osim ako se izričito traži.
- Svjetlu i tamnu temu (javni dio ih podržava).

## 10. Smjernice i pitanja za novi dizajn
- Prioriteti: (1) javne stranice na mobitelu (gledatelji), (2) konzola tatamija, (3) admin tijek rada, (4) dashboard.
- Jedan jedinstven dizajn sustav (tokeni, tipografija, komponente) za javni dio, admin i konzolu, uz zadržavanje AKA crvena / AO plava.
- Predložiti vođeni tijek („koraci natjecanja“) umjesto reda gumba, pregledni dashboard aktivnog natjecanja, filtriranje i grupiranje kategorija te prikaz „uživo“ za veliki ekran u dvorani.
- Implementacija mora biti izvediva u Blade + Livewire + Flux + Tailwind 4 (bez novog frontend frameworka).
