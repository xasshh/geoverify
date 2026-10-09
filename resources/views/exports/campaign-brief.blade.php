<!DOCTYPE html>
<html lang="en" data-mode="daylight">
<head>
    <meta charset="utf-8">
    <title>Campaign brief {{ $campaign['code'] }}</title>
    @vite(['resources/css/app.css'])
    <style>
        /*
         * One page, held in a meeting. The evidence pack is a document somebody
         * files; this is a document somebody reads across a table while being
         * asked what the exercise covers, so it is built to be scanned in a
         * minute rather than read in ten.
         *
         * Same paged-HTML construction as the pack: the running head and foot
         * are a table's thead and tfoot, which is the one thing in print CSS
         * that both repeats on every page and reserves its own space.
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
<table class="page">
    <thead><tr><td>
        <div class="running-head">
            <span>{{ $campaign['client']['name'] }}</span>
            <span>{{ $campaign['code'] }}</span>
        </div>
    </td></tr></thead>

    <tfoot><tr><td>
        <div class="running-foot">
            Campaign brief &middot; generated {{ now()->format('j M Y') }} &middot; GeoVerify
            &middot; boundaries OCHA and GRID3 &middot; streets &copy; OpenStreetMap contributors
        </div>
    </td></tr></tfoot>

    <tbody><tr><td>
        <h1>{{ $campaign['name'] }}</h1>
        <p class="lede">{{ $campaign['subjectType'] }} &middot; {{ $campaign['statusLabel'] }}</p>

        @if ($campaign['objective'])
            <p class="lede">{{ $campaign['objective'] }}</p>
        @endif

        <div class="facts">
            @php
                $facts = [
                    'Starts' => $campaign['timeline']['startsOn'] ?? 'not set',
                    'Ends' => $campaign['timeline']['endsOn'] ?? 'not set',
                    'Days left' => $campaign['timeline']['daysRemaining'] ?? 'n/a',
                    'Target' => number_format((int) ($campaign['collection']['target'] ?? 0)),
                    'Gathered' => number_format((int) $campaign['collection']['gathered']),
                    'Areas' => $campaign['coverage']['areaCount'],
                    'Agents' => $campaign['deployment']['activeCount'],
                ];
            @endphp
            @foreach ($facts as $label => $value)
                <div>
                    <div class="fact-label">{{ $label }}</div>
                    <div class="fact-value">{{ $value }}</div>
                </div>
            @endforeach
        </div>

        @if ($campaign['about'])
            <h2>About this exercise</h2>
            <div class="prose">{{ trim($campaign['about']) }}</div>
        @endif

        <h2>Coverage</h2>
        <table class="datagrid avoid-break">
            <thead><tr><th>Mandate</th><th>State</th><th>LGA</th><th>Area</th><th>Cells</th></tr></thead>
            <tbody>
            @forelse ($campaign['coverage']['areas'] as $area)
                <tr>
                    <td>{{ $area['name'] }}</td>
                    <td>{{ $area['state'] ?? '.' }}</td>
                    <td>{{ $area['lga'] ?? '.' }}</td>
                    <td class="mono">{{ $area['areaKm2'] }} km2</td>
                    <td class="mono">{{ number_format($area['cells']) }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="muted">No ground has been brought into scope yet.</td></tr>
            @endforelse
            </tbody>
        </table>

        <h2>What is being collected &middot; {{ $campaign['schema']['fieldCount'] }} fields</h2>
        <table class="datagrid avoid-break">
            <thead><tr><th>Field</th><th>Type</th><th>Required</th></tr></thead>
            <tbody>
            @forelse ($campaign['schema']['fields'] as $field)
                <tr>
                    <td>{{ $field['label'] }}</td>
                    <td class="muted">{{ $field['typeLabel'] }}</td>
                    <td class="mono">{{ $field['isRequired'] ? 'yes' : 'no' }}</td>
                </tr>
            @empty
                <tr><td colspan="3" class="muted">No schema has been declared yet.</td></tr>
            @endforelse
            </tbody>
        </table>

        <h2>Stakeholders</h2>
        @forelse ($campaign['stakeholders']['byCategory'] as $group)
            <table class="datagrid avoid-break" style="margin-bottom:3mm">
                <thead><tr><th colspan="3">{{ $group['label'] }}</th></tr></thead>
                <tbody>
                @foreach ($group['people'] as $person)
                    <tr>
                        <td>{{ $person['name'] }}</td>
                        <td class="muted">{{ $person['organisation'] ?? '.' }}</td>
                        <td class="mono">{{ $person['engagementLabel'] }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @empty
            <p class="muted">No stakeholders recorded.</p>
        @endforelse

        <h2>Agents deployed &middot; {{ $campaign['deployment']['activeCount'] }}</h2>
        <table class="datagrid avoid-break">
            <thead><tr><th>Officer</th><th>Reference</th><th>Area</th><th>Since</th></tr></thead>
            <tbody>
            @forelse ($campaign['deployment']['roster'] as $agent)
                <tr>
                    <td>{{ $agent['name'] }}</td>
                    <td class="mono">{{ $agent['staffRef'] ?? '.' }}</td>
                    <td class="muted">{{ $agent['area'] ?? 'not yet assigned' }}</td>
                    <td class="mono">{{ $agent['assignedAt'] }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="muted">Nobody is deployed yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </td></tr></tbody>
</table>
</body>
</html>
