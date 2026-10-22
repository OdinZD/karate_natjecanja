<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        @page { margin: 12mm 12mm 16mm 12mm; }

        body { font-family: 'DejaVu Sans', sans-serif; font-size: 10pt; color: #000; }
        h1 { font-size: 17pt; margin: 0 0 1mm 0; }
        h2 { font-size: 13pt; margin: 3mm 0 1mm 0; }
        h3 { font-size: 10pt; margin: 4mm 0 1mm 0; }
        .muted { color: #555; }
        .small { font-size: 8pt; }

        table { border-collapse: collapse; }
        table.plain { width: 100%; }
        table.plain th { text-align: left; font-size: 8pt; color: #555; border-bottom: 0.75pt solid #000; padding: 1.2mm 1.5mm; }
        table.plain td { padding: 1.6mm 1.5mm; border-bottom: 0.5pt solid #bbb; vertical-align: top; }

        table.bracket { width: 100%; table-layout: fixed; }
        table.bracket th { text-align: left; font-size: 8pt; color: #555; padding: 0 1mm 1mm 1mm; border-bottom: 0.75pt solid #000; }
        table.bracket td.slot { vertical-align: middle; padding: 1mm; }

        table.bout { width: 100%; border: 0.5pt solid #777; }
        table.bout td { padding: 0.8mm 1.2mm; vertical-align: middle; }
        table.bout tr.aka td.tag { border-left: 3pt solid #c8202b; }
        table.bout tr.ao td.tag { border-left: 3pt dashed #1c5bc8; }
        table.bout tr.ao td { border-top: 0.5pt solid #bbb; }
        table.bout td.tag { width: 9mm; font-size: 0.7em; color: #555; }
        table.bout td.score { width: 6mm; text-align: right; }
        table.bout .club { font-size: 0.8em; color: #555; }
        table.bout tr.winner td { font-weight: bold; }
        table.bout tr.winner td.tag { font-weight: normal; }

        table.size-16 table.bout td, table.size-32 table.bout td, table.size-64 table.bout td { padding: 0.3mm 0.6mm; }
        table.size-16 table.bout td.tag, table.size-32 table.bout td.tag, table.size-64 table.bout td.tag { width: 7mm; }
        table.size-16 table.bout td.score, table.size-32 table.bout td.score, table.size-64 table.bout td.score { width: 5mm; }
        table.size-16 td.slot, table.size-32 td.slot, table.size-64 td.slot { padding: 0.4mm; }
        table.size-16 table.bout td, table.size-32 table.bout td, table.size-64 table.bout td { white-space: nowrap; }

        table.size-small { font-size: 11pt; }
        table.size-16 { font-size: 9pt; }
        table.size-32 { font-size: 7pt; }
        table.size-64 { font-size: 6pt; }

        .footer { position: fixed; bottom: -11mm; left: 0; right: 0; font-size: 8pt; color: #555; border-top: 0.5pt solid #bbb; padding-top: 1.5mm; }
        .footer .right { float: right; }
        .pagenum:before { content: counter(page); }
        .page-break { page-break-after: always; }
    </style>
</head>
<body>
    <div class="footer">
        <span class="right">{{ __('Page') }} <span class="pagenum"></span></span>
        {{ $competition->name }}
    </div>

    @yield('content')
</body>
</html>
