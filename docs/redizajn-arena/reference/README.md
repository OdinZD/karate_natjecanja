# Referentni ekrani (Arena)

Svaka datoteka je jedan ekran iz platna s dizajnom. Otvori je u editoru i prepiši strukturu i inline stilove u Blade s Arena klasama (`arena.css`).

**Kako čitati format:**

- `<x-dc>` … `</x-dc>` je sam ekran. `<helmet>` sadrži fontove i `@keyframes`.
- `<sc-for list="{{items}}" as="item">` znači `@foreach ($items as $item)`.
- `<sc-if value="{{cond}}">` znači `@if ($cond)`.
- `{{item.name}}` je vrijednost, kao `{{ $item->name }}`.
- Blok `<script type="text/x-dc" data-dc-script>` na dnu daje **izmišljene primjere podataka** i stanje za klikove (u konzoli, rezultatima, prijavama, natjecanjima). Logiku ne prenositi, ona postoji u Livewire komponentama.
- Sve boje su ovdje napisane kao heksadecimalne radi pregleda. U aplikaciji ih zamijeni tokenima iz `arena.css` (vidi tablicu u `DESIGN.md` §2.1). Iznimka: AKA je u aplikaciji `#E11D2B` (u ovim prototipovima `#E8202E`), vidi napomenu u `DESIGN.md` §2.1.

| Datoteka | Ekran | Područje | Širina |
|---|---|---|---|
| `A-Pocetna.dc.html` | Početna, popis natjecanja | javno | mobitel 390 |
| `A-Detalji.dc.html` | Detalji natjecanja | javno | mobitel 390 |
| `A-Kategorije.dc.html` | Kategorije | javno | mobitel 390 |
| `A-Natjecatelji.dc.html` | Natjecatelji | javno | mobitel 390 |
| `A-ZdrijebPopis.dc.html` | Ždrijeb, popis kategorija | javno | mobitel 390 |
| `A-Zdrijeb.dc.html` | Stablo jedne kategorije | javno | mobitel 390 |
| `A-Javno-Satnica.dc.html` | Satnica uživo (tamna tema) | javno | mobitel 390 |
| `A-Svijetla.dc.html` | Satnica uživo (svijetla tema) | javno | mobitel 390 |
| `A-Rezultati.dc.html` | Rezultati s filtrom | javno | mobitel 390 |
| `A-Poredak.dc.html` | Poredak klubova | javno | mobitel 390 |
| `A-Dvorana.dc.html` | Veliki ekran u dvorani (PRIJEDLOG) | javno | 1920×1080 |
| `A-Tatamiji.dc.html` | Moji tatamiji | konzola | mobitel 390 |
| `A-Konzola.dc.html` | Konzola tatamija | konzola | mobitel 390 |
| `A-KonzolaStanja.dc.html` | Konzola: nema ždrijeba, čeka, završeno | konzola | 3 × mobitel |
| `A-Admin.dc.html` | Dashboard | admin | 1440 (fluidno) |
| `A-Natjecanja.dc.html` | Natjecanja + modal „Novo natjecanje” | admin | 1440 |
| `A-Natjecanje.dc.html` | Natjecanje: detalji, tatamiji, operateri | admin | 1440 |
| `A-KategorijeAdmin.dc.html` | Kategorije + službeni skupovi | admin | 1440 |
| `A-Prijave.dc.html` | Prijave + modal uvoza kluba | admin | 1440 |
| `A-ZdrijebAdmin.dc.html` | Ždrijeb + nositelji | admin | 1440 |
| `A-SatnicaAdmin.dc.html` | Satnica po tatamijima | admin | 1440 |
| `A-Klubovi.dc.html` | Klubovi (obrazac i za Natjecatelje, Korisnike) | admin | 1440 |
| `A-Prijava.dc.html` | Prijava | auth | fluidno |
| `A-Postavke.dc.html` | Postavke: izgled i sigurnost | postavke | 1440 |
| `A-Sustav.dc.html` | Dizajn sustav (boje, tipografija, komponente, animacije) | sustav | 1600 |
