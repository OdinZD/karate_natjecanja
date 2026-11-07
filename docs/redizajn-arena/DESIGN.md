# Arena — dizajn specifikacija

Smjer: **A · Arena** („borba kao TV prijenos”). Ovaj dokument je izvor istine za redizajn aplikacije „Organizacija natjecanja”. Ide zajedno s `PLAN.md`, `arena.css` i mapom `reference/`.

- **Referentni ekrani:** `reference/*.dc.html`. To su HTML datoteke s inline stilovima. Iz njih se prepisuju točne vrijednosti (razmaci, veličine, boje, animacije). Oznake `<x-dc>`, `<sc-for>`, `<sc-if>` i `{{...}}` su format alata za dizajn: `sc-for` je `@foreach`, `sc-if` je `@if`, a blok `<script type="text/x-dc">` sadrži samo **izmišljene primjere podataka**. Popis datoteka i ekrana je u `reference/README.md`.
- **Platno s dizajnom** (privatno, samo vlasnik ga može otvoriti): https://claude.ai/code/artifact/8f02a859-ca7b-4937-adec-2c6d77449219
- Imena, klubovi, vremena i brojke na ekranima su primjeri.

---

## 1. Načela

1. **Kosi rez i velike brojke.** Naslovi su Big Shoulders velikim slovima. Rezultati i satovi su veliki. Čipovi i gumbi su nagnuti ili koso odrezani.
2. **Volt žuta (`#D9FF3D`) znači „uživo” ili „glavna radnja”.** Ništa drugo. U svijetloj temi volt je samo podloga s tamnim tekstom i tamnim obrubom, nikad boja teksta ili tanke linije.
3. **AKA crvena i AO plava se nikad ne mijenjaju**, ni u svijetloj ni u tamnoj temi. AKA je uvijek lijevo ili gore, AO desno ili dolje.
4. **Stanje se nikad ne pokazuje samo bojom.** Uvijek tekst plus oblik (krug, prsten, romb, kvadrat, trokut). Vidi §2.3.
5. **Kutovi su oštri.** Radijus je 0 svugdje osim na točkama statusa i medaljama, koje su krugovi.
6. **Pokret ima značenje:** ulazak sadržaja, promjena rezultata, uživo. Bez ukrasnih animacija koje se vrte bez razloga.

---

## 2. Tokeni

Implementacija je u `arena.css`: CSS varijable po temi, `@theme` za Tailwind klase, `@utility` za oblike.

### 2.1 Boje

| Token (Tailwind) | Tamna (zadano) | Svijetla | Upotreba |
|---|---|---|---|
| `arena-bg` | `#0A0C11` | `#F2F3F6` | pozadina stranice |
| `arena-bg-2` | `#0D1018` | `#E7E9EF` | trake tabova, pruge, zaglavlja grupa |
| `arena-surface` | `#121621` | `#FFFFFF` | kartice, tablice, modali |
| `arena-surface-2` | `#1A2030` | `#E8EBF1` | trake napretka, prazna polja, odabrani red |
| `arena-line` | `#262D3F` | `#D3D8E2` | linije i obrubi |
| `arena-line-strong` | `#3A4257` | `#9AA2B4` | obrub sekundarnog gumba, isprekidani obrubi |
| `arena-ink` | `#F3F5F9` | `#0A0C11` | glavni tekst |
| `arena-muted` | `#A0A8BA` | `#4B5468` | sporedni tekst, oznake |
| `arena-volt` | `#D9FF3D` | `#D9FF3D` (samo podloga) | uživo, primarni gumb, aktivni tab (tamna) |
| `arena-aka` | `#E8202E` | `#E8202E` | AKA ploča s bijelim tekstom |
| `arena-aka-text` | `#FF4D57` | `#B5121F` | AKA kao boja teksta ili crtice |
| `arena-ao` | `#2C62F0` | `#2C62F0` | AO ploča s bijelim tekstom |
| `arena-ao-text` | `#5B8CFF` | `#1F4FD1` | AO kao boja teksta ili crtice |
| `arena-warn` | `#FFB15C` | `#9A4D00` | kašnjenje, nedostaje operater, 1 prijava |
| `arena-ok` | `#3DDC84` | `#13753F` | povezano, spremljeno |
| `arena-gold / silver / bronze` | `#E9B949` / `#C9CFDB` / `#C98A55` | isto | medalje |

Kontrasti su provjereni za AA: bijeli tekst na `arena-aka` i `arena-ao`, `arena-muted` na `arena-bg`, i tekstualne inačice AKA/AO na obje pozadine. **Fokus:** 3 px obrub, volt u tamnoj temi, `arena-ink` u svijetloj.

### 2.2 Tipografija

| Uloga | Font | Veličina | Napomena |
|---|---|---|---|
| Display (naslov natjecanja, hero) | Big Shoulders 900 | 46–72 px (mobitel 50–54, veliki ekran 64–96) | VELIKA SLOVA, `line-height: .88` |
| Naslov odjeljka | Big Shoulders 800 | 22–28 px | VELIKA SLOVA, `letter-spacing: .04em` |
| Brojke u pločama i KPI | Big Shoulders 900 | 40–150 px | rezultat AKA/AO, broj tatamija |
| Tekst | Inter 400–700 | 15 / 14 / 13 px | tablice 14 px |
| Oznake | Inter 700–800 | 11–12 px | VELIKA SLOVA, `letter-spacing: .1–.14em` |
| Vrijeme, težina, brojevi u tablicama | JetBrains Mono 500–700 | 11–22 px | tabularne brojke |

Fontovi se mogu učitati preko Google Fonts (`Big Shoulders`, `Inter`, `JetBrains Mono`) ili Bunny Fonts, ako tamo postoje isti nazivi. Treba podržati hrvatska slova (č ć đ š ž).

### 2.3 Stanja (oblik + tekst)

| Stanje | Oblik | Tekst | Boja |
|---|---|---|---|
| Uživo / u tijeku | puni krug koji pulsira | UŽIVO, U TIJEKU, SADA | volt |
| Prijave otvorene | prsten | PRIJAVE OTVORENE | volt obrub |
| Ždrijeb gotov / čeka | romb | ŽDRIJEB GOTOV, ČEKA, SLJEDEĆE | `arena-ao-text` |
| Završeno | kvadrat | ZAVRŠENO | `arena-muted` / srebrna |
| Nema ždrijeba / nacrt | isprekidani prsten | NEMA ŽDRIJEBA, NACRT | `arena-muted` |
| Upozorenje | trokut | npr. „Nema operatera”, „+12 min” | `arena-warn` |
| Greška | romb ili trokut, crveno | npr. „greške” | `arena-aka-text` |

Medalje: **1** puni zlatni krug s tamnim brojem, **2** puni srebrni prsten (3 px), **3** točkasti brončani prsten (3 px, `dotted`). Uz medalju uvijek ide `aria-label="1. mjesto"`.

### 2.4 Oblici i razmaci

- **Gumb (primarni):** volt podloga, tamni tekst, Big Shoulders 900 18–22 px, `arena-cut`, min. visina 44 px (48–56 za glavne radnje).
- **Gumb (sekundarni):** proziran, obrub `arena-line-strong`, Inter 600 14 px. **Tekstualni:** volt tekst (samo tamna tema).
- **Čip / filter:** `arena-skew`, obrub 1 px. Odabrani je pun (`arena-ink` podloga, `arena-bg` tekst).
- **Oznaka tatamija:** „T1” Big Shoulders 900 na `arena-ink` podlozi, `arena-skew`.
- **AKA | AO ploča:** dvije polovice u gridu. `arena-plate-aka` i `arena-plate-ao` daju dijagonalu od 16 px (34 px na velikom ekranu). Natpis AKA/AO je 11 px, 800, razmak .14em; ime je 15 px 700; klub 12 px; rezultat je Big Shoulders 900.
- **Kartica:** `arena-surface`, obrub 1 px `arena-line`, bez radijusa. Kartica borbe koja je u tijeku dobiva `arena-sweep` ili volt obrub.
- **Koraci natjecanja:** 7 strelica (`arena-chevron`). Završeni su `arena-surface-2` s ✓, sadašnji je volt, budući su `arena-bg-2` s prigušenim tekstom.
- **Razmaci:** mobitel 16 px sa strane, 12 px između kartica, 24 px između odjeljaka. Admin 24–40 px sa strane (`clamp(16px, 3vw, 40px)`), 16–20 px između blokova.
- **Ciljevi dodira:** najmanje 44 px. U konzoli 48 px (stepper) i 84–156 px (odabir pobjednika).

---

## 3. Animacije

| Klasa | Trajanje i krivulja | Gdje | Kad |
|---|---|---|---|
| `animate-arena-in` | 700 ms, `cubic-bezier(.2,.8,.2,1)`, razmak 60–120 ms po stavci | kartice, redovi, naslov | samo pri prvom prikazu |
| `animate-arena-up` | 500 ms ease-out | admin blokovi | prvi prikaz |
| `arena-sweep` | 3,6 s, ponavlja se | samo kartica borbe u tijeku | dok je borba u tijeku |
| `animate-arena-pop` | 600 ms, krivulja s odskokom | rezultat AKA/AO, brojke na Detaljima | kad se vrijednost promijeni |
| `animate-arena-pulse` | 1,6 s, ponavlja se | točka UŽIVO | dok je uživo |
| `animate-arena-ticker` | 26 s linear (40 s na velikom ekranu) | traka rezultata | stalno |
| `animate-arena-bar` | 1,2 s | trake napretka, poredak | prvi prikaz |
| `animate-arena-medal` | 500 ms | medalje u rezultatima | prvi prikaz |
| `animate-arena-modal` | 400 ms | modali i bočni paneli | otvaranje |

**Pravila uz `wire:poll`:**

- Livewire pri osvježavanju zadržava postojeće DOM elemente, pa se animacije ulaska ne ponavljaju svakih 10 s. Zato svaki red u petlji mora imati stabilan `wire:key`.
- „Pop” rezultata treba zaigrati **samo kad se broj promijeni**. Najjednostavnije: ključ elementa uključuje vrijednost, npr. `wire:key="aka-{{ $bout->id }}-{{ $bout->aka_score }}"`, pa se element zamijeni i animacija krene ispočetka.
- Zakašnjenje u petlji: `style="animation-delay: {{ $loop->index * 80 }}ms"`.
- `prefers-reduced-motion` gasi sve (u `arena.css`). Ništa ne treperi brže od 3 puta u sekundi.

---

## 4. Komponente (Blade)

Prijedlog: anonimne komponente u `resources/views/components/arena/`. Flux komponente koje već koristite (modal, input, select, dropdown…) **zadržite** zbog logike i pristupačnosti, a izgled im uskladite s tokenima. Gdje Flux ne može postići izgled, napravite vlastitu komponentu.

| Komponenta | Props | Koristi se na |
|---|---|---|
| `x-arena.logo` | `size` | svi layouti |
| `x-arena.status` | `state` (live, registration, drawn, waiting, done, none, warn, error), `label` | posvuda |
| `x-arena.tatami` | `number`, `size` (sm, md, lg, hall) | satnica, ždrijeb, konzola, admin |
| `x-arena.plate` | `aka`, `ao` (ime + klub), `akaScore`, `aoScore`, `showScore`, `size` | javno uživo, veliki ekran, mini traka „Sada na T1” |
| `x-arena.medal` | `place` (1, 2, 3) | rezultati, ždrijeb, konzola (završeno) |
| `x-arena.section-title` | slot, `meta` | sve stranice |
| `x-arena.card` | `variant` (default, live, dashed, alert) | sve stranice |
| `x-arena.button` | `variant` (primary, secondary, ghost, danger), `href`, `icon` | sve stranice |
| `x-arena.tabs` | `competition`, `active` | javno zaglavlje natjecanja, **zadržati `data-test="tab-…"`** |
| `x-arena.steps` | `competition`, `current` | sve admin stranice natjecanja (zamjenjuje red gumba) |
| `x-arena.stat` | `label`, `value`, `of`, `progress`, `hint` | dashboard, detalji |
| `x-arena.ticker` | `items` | javno uživo, veliki ekran |
| `x-arena.bout` | `bout`, `live`, `winner` | stablo ždrijeba |
| `x-arena.empty` | `title`, `text`, `action` | prazna stanja (konzola, liste) |
| `x-bracket` (postojeća) | bez promjene API-ja | prepraviti iznutra na tokene `bg-arena-aka`/`bg-arena-ao`; javne stranice više ne smiju prepisivati boje CSS-om |

---

## 5. Ekrani

Za svaki ekran: referentna datoteka, sadržaj, stanja i napomene. Rute, `wire:model` svojstva, metode i `data-test` atributi ostaju **isti** kao sada.

### 5.1 Javni dio (layout `public`, mobitel je prioritet)

**Početna `/`** · `reference/A-Pocetna.dc.html`
- Hero s naslovom „NATJECANJA” (72 px) i rečenicom opisa. Kose crvena i plava ploča uđu s desne strane.
- „UŽIVO SADA”: istaknuta kartica natjecanja u statusu *In progress*. Sadrži volt blok s datumom, napredak borbi (odigrane/ukupno), broj aktivnih tatamija i gumb „PRATI UŽIVO” koji vodi na satnicu.
- „NADOLAZEĆA” i „ZAVRŠENA”: retci s datumom (dan + kratica mjeseca SIJ, VELJ…), nazivom, mjestom, organizatorom, statusom (oblik + tekst) i brojem prijava. Paginacija s 44 px kosim gumbima.

**Zaglavlje natjecanja + tabovi** (sve podstranice): link „Sva natjecanja”, naslov, meta podaci i tabovi Detalji, Kategorije, Natjecatelji, Ždrijeb, Satnica, Rezultati, Poredak. Tabovi se pomiču vodoravno, a aktivni ima volt podvlaku od 4 px. Na podstranicama osim Detalja zaglavlje je sažeto (natrag + naziv u jednom retku).

**Detalji** · `A-Detalji.dc.html`
- Baner „NATJECANJE JE U TIJEKU” (volt, kose pruge koje se pomiču) samo kad je status *In progress*.
- „KADA I GDJE”: lista pojmova (`<dl>`) s datumom, početkom, mjestom, organizatorom, rokom prijave i statusom.
- „U BROJKAMA”: kategorije, prijave, natjecatelji, klubovi, tatamiji. Brojke iskoče s „pop” animacijom.
- Veliki linkovi Ždrijeb, Satnica, Rezultati s podnaslovom.

**Kategorije** · `A-Kategorije.dc.html`
- Pretraga, čipovi filtra (Sve, Kata, Kumite, Ž, M, Uživo).
- Grupe po dobnoj skupini sa sklapanjem: naziv, godišta, broj kategorija. Redak: naziv, spol, broj prijava, tatami, stanje ždrijeba. Redak vodi na stablo.

**Natjecatelji** · `A-Natjecatelji.dc.html`
- Pretraga po imenu, odabir kluba, ukupan broj.
- Popis po početnom slovu prezimena (Ć i Š kao posebna slova, hrvatsko sortiranje). Redak: inicijali, ime, klub, čipovi kategorija s tatamijem, koji vode na stablo. Paginacija ili „Učitaj još”.

**Ždrijeb (popis)** · `A-ZdrijebPopis.dc.html`
- „SE IGRA SADA”: kategorije koje su trenutno na tatamijima (volt crta lijevo, sweep).
- „SVE KATEGORIJE”: filtar disciplina i retci sa stanjem (ZAVRŠENO, ŽDRIJEB GOTOV, NEMA ŽDRIJEBA).

**Stablo kategorije** · `A-Zdrijeb.dc.html`
- Odabir kategorije (veliki gumb) i meta čipovi.
- Stablo se pomiče vodoravno: kartice borbi (crtica AKA/AO, ime, rezultat), pobjednik je podebljan, a poraženi prigušen. Borba u tijeku ima volt obrub, oznaku „SADA” i sweep. Spojne linije se iscrtavaju (clip-path).
- Repasaž (samo kumite) po polovicama, dok ne počne prikazuje „Nakon polufinala”.
- Poredak 1, 2, 3, 3 za kumite, a za katu 1, 2, 3.
- Na dnu je fiksna traka „SADA NA T1” s mini pločom, koja vodi na satnicu.
- Kandidat za Vue otok samo ako zatreba zumiranje i pomicanje prstima (vidi §7).

**Satnica (uživo)** · `A-Javno-Satnica.dc.html` (tamno) i `A-Svijetla.dc.html` (svijetlo)
- Hero s pločama, oznaka UŽIVO.
- „SADA NA TATAMIJIMA”: po tatamiju kartica s oznakom, kategorijom, kolom/borbom, procjenom kraja kategorije („do ~10:35”) i AKA | AO pločom s rezultatom. Kata nema rezultat (zastavice). Tatami u pauzi je u isprekidanom okviru.
- „SLIJEDI NA T1”, „UPRAVO ZAVRŠENO” (postolje) i ticker rezultata na dnu.
- **Nema štoperice borbe:** aplikacija ne mjeri vrijeme borbe, pa se prikazuje samo procjena kraja kategorije.

**Rezultati** · `A-Rezultati.dc.html`: filtar Sve/Kata/Kumite (kosi segmenti), kartice kategorija s medaljama (animacija medalja). Kategorija u tijeku ima prazne isprekidane medalje i link „Polufinale sada, finale oko 11:05”.

**Poredak klubova** · `A-Poredak.dc.html`: tablica s mjestom (prvo je volt), klubom, Z/S/B i ukupno, plus traka po klubu (zlato | srebro | bronca) koja raste. Klubovi s istim medaljama dijele mjesto (1, 2, 2, 4), a uz naslov je kratko objašnjenje pravila.

**Veliki ekran u dvorani** · `A-Dvorana.dc.html` · **PRIJEDLOG nove rute** (vidi §6)
- 1920×1080. Zaglavlje s nazivom, UŽIVO i satom; 2×2 pločice tatamija (oznaka, kategorija, kolo, velika ploča s rezultatom 150 px, „SLIJEDI”). Pločica pauze prikazuje „PRIPREMA SE” s kategorijama koje slijede. Na dnu je ticker od 72 px.

### 5.2 Konzola tatamija (isti sidebar layout, telefon ili tablet)

**Moji tatamiji `/tatami`** · `A-Tatamiji.dc.html`: kartica tatamija koji je danas uživo (velik broj, sweep, gumb „OTVORI KONZOLU”), zatim sljedeći i završeni tatamiji. Napomena na dnu: „Ne vidiš svoj tatami? Administrator te mora dodati kao operatera.”

**Konzola `/tatami/{id}`** · `A-Konzola.dc.html` (interaktivno u Play načinu)
- Zaglavlje: natrag, „TATAMI 1”, stanje veze.
- Odabir kategorije, kolo i „BORBA 6 / 9”.
- **AKA ploča (gore) i AO ploča (dolje)**, svaka 156 px. Dodir na ime znači pobjednika: odabrana dobije volt „POBJEDNIK” i sweep, druga se priguši na 45 %. Uz svaku su + / − za bodove (48×44) i rezultat od 44 px.
- Odluka: 5 tipki (Bodovi, Hantei, Zastavice, Kiken, Shikkaku) kao radio grupa.
- „ZAPIŠI REZULTAT” je volt, a dok pobjednik nije odabran siv s tekstom „ODABERI POBJEDNIKA”. Nakon zapisa: traka „Zapisano · Marić 3:1” s gumbom „Poništi”.
- Dno: sljedeća borba i zadnja odigrana.
- **Zadržati `data-test="current-bout"`** i postojeća imena svojstava (pobjednik, odluka, bodovi) i metoda (`record…`, `undo…`). U dizajnu nema novih podataka.

**Stanja konzole** · `A-KonzolaStanja.dc.html`: „JOŠ NEMA ŽDRIJEBA”, „ČEKA POBJEDNIKE” (mini prikaz borbi o kojima ovisi sljedeća) i „KATEGORIJA ZAVRŠENA” (medalje, gumb na sljedeću kategoriju tog tatamija, „Poništi zadnji rezultat”).

### 5.3 Administracija (layout `sidebar`)

**Sidebar:** logo, Dashboard, Tatami konzola (ako korisnik ima tatamije), grupa ADMINISTRACIJA (Natjecanja, Klubovi, Natjecatelji, Korisnici) i korisnički izbornik. Aktivna stavka ima `arena-surface-2` podlogu i volt crtu lijevo. Na mobitelu sidebar ide iznad sadržaja ili u postojeći hamburger.

**Dashboard** · `A-Admin.dc.html`
- Aktivno natjecanje (naslov, UŽIVO, „Javna stranica”, „PDF ispisi”).
- Koraci natjecanja (`x-arena.steps`).
- KPI: borbe odigrane/ukupno, kategorije završene/ukupno, procjena kraja (s kašnjenjem), prijave, natjecatelji i klubovi.
- „TATAMIJI UŽIVO”: tatami, kategorija, napredak, u roku ili kasni, link na konzolu.
- „TREBA PAŽNJU” (PRIJEDLOG, §6) i tablica kategorija s pretragom, čipovima i grupiranjem.
- Kad nema aktivnog natjecanja: prazno stanje s gumbom „NOVO NATJECANJE” i popisom sljedećih natjecanja.

**Natjecanja** · `A-Natjecanja.dc.html`: naslov, „NOVO NATJECANJE”, tabovi statusa s brojem, tablica (naziv, datum, mjesto, prijave, status, Otvori). Modal „NOVO NATJECANJE”: naziv, datum, vrijeme početka, mjesto, organizator, rok prijave i status kao radio gumbi (Nacrt…), uz napomenu da Nacrt nije javan. Polja odgovaraju postojećoj formi.

**Natjecanje (detalji)** · `A-Natjecanje.dc.html`
- Zaglavlje s „Javna stranica” i izbornikom „⋯” (PDF ispisi, **Obriši natjecanje**, s potvrdom). Red od 5 gumbi nestaje.
- Koraci natjecanja: oznaka sadašnjeg koraka plus mogućnost klika na bilo koji korak, jer se koraci mogu raditi bilo kojim redom.
- Blok „01 · DETALJI” s formom i blok „02 · TATAMIJI I OPERATERI” s popisom tatamija, operaterima kao čipovima, upozorenjem „Nema operatera” i gumbom „Operateri” koji otvara panel s izborom korisnika s ulogom Tatami operater.
- Na dnu: „SLJEDEĆI KORAK: KATEGORIJE →”.

**Kategorije (admin)** · `A-KategorijeAdmin.dc.html`
- Pretraga, čipovi (Sve, Kata, Kumite, Bez prijava, Bez tatamija).
- „SLUŽBENE KATEGORIJE ▾” je izbornik s tri skupa (Turnir 125, Prvenstvo Hrvatske 157, Kup Hrvatske 115) i napomenom da se godišta računaju prema godini natjecanja. Uz to „Kopiraj s natjecanja” i „Nova kategorija”.
- Tablica grupirana po dobnoj skupini (zaglavlje grupe: naziv, godišta, broj, trajanje borbe). Stupci: naziv, disciplina, spol, godišta, težina, trajanje, prijave (0 je `arena-warn`) i uređivanje.

**Prijave** · `A-Prijave.dc.html` (interaktivno)
- Koraci (04 aktivan), status „PRIJAVE OTVORENE · ROK …”.
- Alatna traka: pretraga, filtar kategorije, „Predložak za klubove”, **jedan gumb „UVOZ ▾”** (izbornik: Uvoz prijava kluba, Opći Excel uvoz) i „Nova prijava”. **Zadržati `data-test="import-club-entries-button"`** na stavci ili gumbu koji otvara uvoz kluba.
- Tablica: natjecatelj, klub, kategorija, težina, nositelj.
- Panel „UVOZ PO KLUBOVIMA” (PRIJEDLOG, §6).
- Modal uvoza kluba: 1 Klub, 2 Datoteka, pa crveni okvir „Ništa nije uvezeno: N redaka ima grešku” (kratka trešnja), popis grešaka po retku (RED 7 · ime · poruka) i pregled ispravnih redaka s automatski dodijeljenom kategorijom. Gumb „UVEZI” je onemogućen dok postoje greške.

**Ždrijeb (admin)** · `A-ZdrijebAdmin.dc.html`
- Zaglavlje s napretkom (113/125), „Svi ždrijebovi (PDF)” i „GENERIRAJ PREOSTALE · 12” (postojeći „Generate all”).
- Čipovi: Sve, Bez ždrijeba, S ždrijebom, Ima odigranih.
- Redak: kategorija, prijave, nositelji (gumb), stanje, a zatim **jedna glavna radnja** prema stanju (Generiraj ili Pogledaj), PDF i „⋯” (Regeneriraj, Obriši). Kad postoje odigrane borbe, stavke su onemogućene s razlogom, npr. „Nije moguće: 5 borbi je odigrano”.
- Bočni panel „NOSITELJI”: redoslijed nositelja, „SPREMI I GENERIRAJ”.

**Satnica (admin)** · `A-SatnicaAdmin.dc.html`
- Procjena kraja i „Cijela satnica (PDF)”.
- „BEZ TATAMIJA · N” (narančasti obrub): retci s gumbima T1–T4.
- Stupac po tatamiju: oznaka, operater, broj borbi i trajanje, kraj (narančasto ako kasni), traka opterećenja, PDF tog tatamija. Redoslijed kategorija s procijenjenim početkom; ono što je u tijeku je volt, a završeno prigušeno.
- Ručka za promjenu redoslijeda: vidi §6.

**Klubovi / Natjecatelji / Korisnici** · `A-Klubovi.dc.html`: zajednički obrazac. Naslov s brojem, „NOVI …”, pretraga i filtar, tablica, paginacija i **bočni panel za uređivanje** umjesto modala (ili postojeći Flux modal u istom stilu). Brisanje je crveni sekundarni gumb lijevo. Korisnici imaju i odabir uloge (Administrator, Tatami operater).

### 5.4 Autentikacija i postavke

**Prijava** · `A-Prijava.dc.html`: kose ploče i pruge u pozadini, kartica 440 px s logom, „PRIJAVA”, e-poštom, lozinkom (prikaži/sakrij), „Zapamti me”, „PRIJAVI SE” i „Prijava passkeyem”. Na dnu je link na javne rezultate. Isti izgled kartice koriste zaboravljena lozinka, reset, potvrda e-pošte i 2FA izazov.

**Postavke** · `A-Postavke.dc.html`: podizbornik (Profil, Sigurnost, Izgled, Brisanje računa). Izgled je izbor od tri kartice s malim pregledom (Svijetla, Tamna, Prema sustavu). Sigurnost je sažetak (lozinka, 2FA, passkeyevi, kodovi za oporavak).
- **Važno:** maknuti `class="dark"` zapisan izravno u layoutima `sidebar` i `auth`, da postavka Izgled stvarno vrijedi i za admin.

---

## 6. Prijedlozi (nema ih u postojećoj aplikaciji)

Prije ikakvog backend rada ove stavke treba odobriti. Dok se ne odobre, implementira se samo izgled postojećih funkcija.

| Prijedlog | Gdje | Što treba u backendu |
|---|---|---|
| Veliki ekran za dvoranu | nova ruta npr. `/competitions/{slug}/hall` | nova Livewire stranica, isti podaci kao satnica, `wire:poll` |
| „Treba pažnju” | dashboard | upiti: kategorije bez prijava, s 1 prijavom, bez tatamija, tatamiji bez operatera, kašnjenje |
| Kašnjenje tatamija („+12 min”) | dashboard, satnica | usporedba plana s vremenom završetka borbi (treba vremenska oznaka rezultata) |
| „Uvoz po klubovima” | prijave | zapis zadnjeg uvoza po klubu i natjecanju (datum, broj redaka, greške) |
| „Proglasi” za kategoriju s 1 prijavom | ždrijeb admin | plasman bez borbe |
| Promjena redoslijeda kategorija na tatamiju | satnica admin | `sort_order` već postoji na Category, provjeriti koristi li se po tatamiju |
| „Kategorija ide na kraj reda tatamija” | satnica admin | pravilo pri dodjeli |
| Filtri „Bez prijava”, „Bez tatamija”, „Ima odigranih” | admin liste | dodatni uvjeti u upitima |
| Brojevi uz tabove statusa | natjecanja | `count` po statusu |
| „Smanji animacije” na uređaju | postavke | spremanje postavke, klasa na `<html>` |
| Koraci natjecanja sa stanjem | admin | izračun stanja svakog koraka iz postojećih podataka (bez nove tablice) |

---

## 7. Vue ili Alpine

- Livewire već dolazi s Alpineom. **Sve animacije i interakcije u ovom dizajnu mogu se napraviti CSS-om, Alpineom i Livewireom.** Vue nije potreban za prvu verziju.
- Vue 3 ima smisla kao „otok” samo za:
  1. veliki ekran (puno stanja, neprekidno osvježavanje, tranzicije pločica),
  2. stablo ždrijeba ako treba zumiranje i pomicanje prstima,
  3. konzolu ako se uvede rad bez mreže (red čekanja rezultata).
- Pravila za otok: element s `wire:ignore`, podaci kroz `data-*` ili JSON u `<script type="application/json">`, a osvježavanje preko Livewire događaja ili vlastitog dohvaćanja. Bez `wire:ignore` bi `wire:poll` prebrisao Vue DOM.

---

## 8. Pristupačnost (provjera prije svakog spajanja)

- Kontrast AA za sav tekst; za veliki tekst (24 px+) dovoljno je 3:1.
- Status uvijek tekst + oblik, nikad samo boja. AKA/AO su uvijek i napisani („AKA”, „AO”).
- Ciljevi dodira najmanje 44 px, a u konzoli više.
- Pravi elementi: `<button>`, `<a href>`, `<label>` + `<input>`. Gumbi samo s ikonom imaju `aria-label`.
- Tabovi su `<nav>` s `aria-current="page"`. Radio grupe (odluka, filtri) imaju `role="radiogroup"` i `aria-checked`, a koraci `aria-current="step"`.
- Modali: `role="dialog"`, `aria-modal`, `aria-labelledby`, fokus ostaje u modalu, a Esc ga zatvara (Flux modal to već radi).
- Ticker ima `aria-label`. Sadržaj koji se mijenja uz `wire:poll` ne smije preuzimati fokus.
- `prefers-reduced-motion` gasi sve animacije.
