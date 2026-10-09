<!DOCTYPE html>
<html lang="en" data-mode="daylight">
<head>
    <meta charset="utf-8">
    <title>Land report {{ $campaign->code }}</title>
    @vite(['resources/css/app.css'])
    <style>
        /*
         * The area report: what a campaign has mapped of the land, for the
         * client to file. The same paged-HTML construction as the brief: the
         * running head and foot are a table's thead and tfoot, the one thing
         * in print CSS that repeats on every page and reserves its own space.
         */
        @page { size: A4 portrait; margin: 12mm 16mm; }

        table.page { width: 100%; border-collapse: collapse; }
        table.page > thead > tr > td,
        table.page > tfoot > tr > td { padding: 0; border: 0; }
        table.page > tbody > tr > td { padding: 5mm 0 0; border: 0; vertical-align: top; }

        html, body { background: #FBFAF7; color: #16202B; }

        body {
            font-family: 'Plus Jakarta Sans', system-ui, sans-serif;
            font-size: 9.5pt;
            line-height: 1.5;
            font-variant-numeric: tabular-nums;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        .running-head, .running-foot {
            font-family: 'JetBrains Mono', monospace;
            font-size: 7pt;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: #6B7A88;
        }
        .running-head {
            display: flex; justify-content: space-between;
            border-bottom: 0.5pt solid #D8D3C8; padding-bottom: 2mm;
        }
        .running-foot { border-top: 0.5pt solid #D8D3C8; padding-top: 2mm; color: #97A3AE; }

        h1 { font-family: 'Plus Jakarta Sans', sans-serif; font-size: 20pt; line-height: 1.15; margin: 0; font-weight: 400; }
        h2 {
            font-family: 'Plus Jakarta Sans', sans-serif; font-size: 7.5pt; font-weight: 600;
            letter-spacing: 0.12em; text-transform: uppercase; color: #6B7A88;
            margin: 6mm 0 2mm; border-bottom: 0.5pt solid #D8D3C8; padding-bottom: 1mm;
        }
        .lede { font-size: 10pt; color: #43535F; margin: 2mm 0 0; }
        .mono { font-family: 'JetBrains Mono', monospace; }

        .facts { display: flex; flex-wrap: wrap; gap: 6mm; margin-top: 3mm; }
        .fact-label { font-size: 6.5pt; letter-spacing: 0.1em; text-transform: uppercase; color: #97A3AE; }
        .fact-value { font-family: 'JetBrains Mono', monospace; font-size: 11pt; }

        table.datagrid { width: 100%; border-collapse: collapse; font-size: 8.5pt; }
        table.datagrid th {
            text-align: left; font-size: 6.5pt; letter-spacing: 0.1em; text-transform: uppercase;
            color: #97A3AE; border-bottom: 0.5pt solid #D8D3C8; padding: 1mm 2mm 1mm 0; font-weight: 600;
        }
        table.datagrid td { padding: 1.2mm 2mm 1.2mm 0; border-bottom: 0.25pt solid #E7E2D8; vertical-align: top; }

        .prose { max-width: 46em; white-space: pre-line; }
        .muted { color: #6B7A88; }
        .avoid-break { break-inside: avoid; }
    </style>
</head>
<body>
@php
    $org = $campaign->organisation?->name;
    $total = max(0.0001, (float) $summary['totals']['areaHa']);
    $v = $summary['verification'];
@endphp
<table class="page">
    <thead><tr><td>
        <div class="running-head">
            <span>{{ $org ?? 'Land report' }}</span>
            <span>{{ $campaign->code }}</span>
        </div>
    </td></tr></thead>
    <tfoot><tr><td>
        <div class="running-foot">
            Land and natural features &middot; generated {{ now()->format('j M Y, H:i') }} &middot; GeoVerify
            &middot; areas computed by PostGIS on the ellipsoid
        </div>
    </td></tr></tfoot>
    <tbody><tr><td>
        <h1>{{ $campaign->name }}</h1>
        <p class="lede">Land and natural features mapped &middot; {{ implode(', ', $areas) }}</p>

        <div class="facts">
            @foreach ([
                'Features' => number_format($summary['totals']['features']),
                'Area mapped' => number_format($summary['totals']['areaHa'], $summary['totals']['areaHa'] < 100 ? 2 : 0).' ha',
                'Lines' => number_format($summary['totals']['lengthKm'], 1).' km',
                'Points' => number_format($summary['totals']['points']),
                'Checked on the ground' => number_format($v['checksDone']).' of '.number_format($v['target']),
            ] as $label => $value)
                <div>
                    <div class="fact-label">{{ $label }}</div>
                    <div class="fact-value">{{ $value }}</div>
                </div>
            @endforeach
        </div>

        @if ($map !== null)
            <h2>The map</h2>
            <div class="avoid-break" style="display:flex; gap:5mm; align-items:flex-start">
                <svg viewBox="{{ $map['viewBox'] }}" style="width:105mm; height:95mm; background:#F2EFE8; border:0.5pt solid #D8D3C8" preserveAspectRatio="xMidYMid meet">
                    @foreach ($map['paths'] as $path)
                        @if ($path['line'])
                            <path d="{{ $path['d'] }}" fill="none" stroke="{{ $path['colour'] }}" stroke-width="{{ $map['stroke'] * 2 }}" />
                        @else
                            <path d="{{ $path['d'] }}" fill="{{ $path['colour'] }}" fill-opacity="0.85" stroke="none" fill-rule="evenodd" />
                        @endif
                    @endforeach
                    <path d="{{ $map['boundary'] }}" fill="none" stroke="#16202B" stroke-width="{{ $map['stroke'] * 2.5 }}" />
                </svg>
                <div style="flex:1">
                    @foreach ($summary['classes'] as $class)
                        @if ($class['features'] > 0)
                            <div style="display:flex; align-items:center; gap:2mm; margin-bottom:1.4mm">
                                <span style="display:inline-block; width:3.2mm; height:3.2mm; border-radius:0.6mm; background:{{ $class['colour'] }}"></span>
                                <span>{{ $class['label'] }}</span>
                            </div>
                        @endif
                    @endforeach
                    <p class="muted" style="margin-top:3mm; font-size:7.5pt">Outlined: the mandate boundary. Simplified to the page; the exports carry the full shapes.</p>
                </div>
            </div>
        @endif

        <h2>By class</h2>
        <table class="datagrid" style="width:100%">
            <thead><tr><th>Class</th><th>Kind</th><th>Features</th><th>Area</th><th>Share</th><th>Length</th><th>Checked</th><th>From the field</th></tr></thead>
            <tbody>
            @forelse (array_filter($summary['classes'], fn ($c) => $c['features'] > 0) as $class)
                <tr>
                    <td><span style="display:inline-block; width:2.4mm; height:2.4mm; border-radius:0.5mm; background:{{ $class['colour'] }}; margin-right:1.5mm"></span>{{ $class['label'] }}</td>
                    <td class="muted">{{ $class['geometryType'] }}</td>
                    <td class="mono">{{ number_format($class['features']) }}</td>
                    <td class="mono">{{ $class['geometryType'] === 'polygon' ? number_format($class['areaHa'], $class['areaHa'] < 100 ? 2 : 0).' ha' : '.' }}</td>
                    <td class="mono">{{ $class['geometryType'] === 'polygon' ? number_format(100 * $class['areaHa'] / $total, 1).'%' : '.' }}</td>
                    <td class="mono">{{ $class['geometryType'] === 'line' ? number_format($class['lengthKm'], 2).' km' : '.' }}</td>
                    <td class="mono">{{ number_format($class['verified']) }}</td>
                    <td class="mono">{{ number_format($class['fromField']) }}</td>
                </tr>
            @empty
                <tr><td colspan="8" class="muted">Nothing has been mapped yet.</td></tr>
            @endforelse
            </tbody>
        </table>

        <h2>Ground checks</h2>
        <table class="datagrid avoid-break" style="width:100%">
            <tbody>
                <tr><td>Drawn at the desk or imported</td><td class="mono">{{ number_format($v['fromDesk']) }}</td></tr>
                <tr><td>Sample to check on the ground ({{ $v['samplePct'] }}%)</td><td class="mono">{{ number_format($v['target']) }}</td></tr>
                <tr><td>Checks done</td><td class="mono">{{ number_format($v['checksDone']) }} ({{ $v['progressPct'] }}% of the sample)</td></tr>
                <tr><td>Checks waiting for an officer</td><td class="mono">{{ number_format($v['checksOpen']) }}</td></tr>
                <tr><td>Confirmed on the ground</td><td class="mono">{{ number_format($v['verified']) }}</td></tr>
                <tr><td>Not found on the ground</td><td class="mono">{{ number_format($v['rejected']) }}</td></tr>
                <tr><td>To be visited again</td><td class="mono">{{ number_format($v['revisit']) }}</td></tr>
            </tbody>
        </table>
        <p class="muted" style="font-size:8pt">A feature counts as checked only once an officer has stood on it. Features drawn at the desk stay unverified until then.</p>

        @if ($sources !== [])
            <h2>Sources</h2>
            <ul style="margin:0; padding-left:4mm">
                @foreach ($sources as $source)
                    <li>{{ $source }}</li>
                @endforeach
            </ul>
            @if (collect($sources)->contains(fn ($s) => str_contains($s, 'WorldCover')))
                <p class="muted" style="font-size:7.5pt">Land cover pre-drawn from ESA WorldCover (&copy; ESA WorldCover project), containing modified Copernicus Sentinel data, CC BY 4.0.</p>
            @endif
        @endif
    </td></tr></tbody>
</table>
</body>
</html>
