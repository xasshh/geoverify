<!DOCTYPE html>
<html lang="en" data-mode="daylight">
<head>
    <meta charset="utf-8">
    <title>Evidence pack {{ $pack['cell']['h3'] }}</title>
    @vite(['resources/css/app.css'])
    <style>
        /*
         * Print, not screen. A4 with generous outer margins because this is a
         * document that gets held, annotated and filed rather than scrolled,
         * and a survey report with web margins reads as a web page printed out.
         */
        /*
         * The running head and foot are a table's thead and tfoot, which the
         * print engine repeats on every page and reserves space for.
         *
         * position:fixed was the obvious way and it is wrong: a fixed element
         * repeats on every page but takes no space in the flow, so body padding
         * clears it on the first page only and every page after that prints the
         * document underneath it. A thead is the one construct in paged HTML
         * that both repeats and occupies its own room.
         */
        @page { size: A4 portrait; margin: 12mm 16mm; }

        table.page { width: 100%; border-collapse: collapse; }
        table.page > thead > tr > td,
        table.page > tfoot > tr > td { padding: 0; border: 0; }
        table.page > tbody > tr > td { padding: 4mm 0 0; border: 0; vertical-align: top; }

        html, body { background: #FBFAF7; color: #16202B; }

        body {
            font-family: 'Plus Jakarta Sans', system-ui, sans-serif;
            font-size: 9.5pt;
            line-height: 1.5;
            font-variant-numeric: tabular-nums;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        /* Chrome repeats a fixed element on every printed page, which is the
           only running head available to print-to-pdf from the command line. */
        .running-head {
            display: flex;
            justify-content: space-between;
            font-family: 'JetBrains Mono', monospace;
            font-size: 7pt;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: #6B7A88;
            border-bottom: 0.5pt solid #D8D3C8;
            padding-bottom: 2mm;
        }

        .running-foot {
            font-family: 'JetBrains Mono', monospace;
            font-size: 7pt;
            color: #97A3AE;
            border-top: 0.5pt solid #D8D3C8;
            padding-top: 2mm;
        }

        .display { font-family: 'Plus Jakarta Sans', sans-serif; font-weight: 400; }
        .mono { font-family: 'JetBrains Mono', monospace; }

        h1.title { font-size: 34pt; line-height: 1.05; margin: 0; letter-spacing: -0.01em; }
        h2.section { font-size: 17pt; line-height: 1.2; margin: 0 0 4mm; }

        .label {
            font-size: 7pt;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            color: #6B7A88;
            font-weight: 600;
        }

        .gold { color: #8A6D22; }
        .rule { border: 0; border-top: 1pt solid #16202B; margin: 4mm 0; }
        .hairline { border: 0; border-top: 0.5pt solid #D8D3C8; margin: 3mm 0; }

        section { page-break-before: always; break-before: page; padding-top: 2mm; }
        section.cover { page-break-before: auto; break-before: auto; }

        table { width: 100%; border-collapse: collapse; font-size: 8pt; }
        thead { display: table-header-group; }
        tr { break-inside: avoid; }
        th {
            text-align: left;
            font-size: 6.8pt;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            color: #6B7A88;
            border-bottom: 0.75pt solid #16202B;
            padding: 0 2mm 1.5mm 0;
        }
        td { padding: 1.6mm 2mm 1.6mm 0; border-bottom: 0.4pt solid #E8E4DA; vertical-align: top; }
        td.num, th.num { text-align: right; font-family: 'JetBrains Mono', monospace; }

        .facts { display: grid; grid-template-columns: repeat(4, 1fr); gap: 5mm 6mm; }
        .fact-value { font-size: 15pt; font-family: 'JetBrains Mono', monospace; }

        .sheet { display: grid; grid-template-columns: repeat(4, 1fr); gap: 4mm; }
        .shot { break-inside: avoid; }
        .shot .frame {
            aspect-ratio: 4 / 3;
            border: 0.5pt solid #D8D3C8;
            background: #F1EEE7;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }
        .shot img { width: 100%; height: 100%; object-fit: cover; }
        .shot .missing { font-size: 6.5pt; color: #97A3AE; text-align: center; padding: 2mm; }
        .shot .caption { font-size: 6.8pt; color: #4A5A68; margin-top: 1mm; line-height: 1.35; }

        .mark { display: block; margin: 0 auto; }
        .badge {
            display: inline-block;
            border: 0.75pt solid #8A6D22;
            color: #8A6D22;
            padding: 0.8mm 2.2mm;
            font-size: 7pt;
            letter-spacing: 0.1em;
            text-transform: uppercase;
        }
        .warn { border-color: #B4762A; color: #B4762A; }
    </style>
</head>
<body>

@php
    $cell = $pack['cell'];
    $summary = $pack['summary'];
    $map = $pack['map'];
    $stroke = $map['strokeWidth'] ?? 0.00002;
@endphp

<table class="page">
<thead>
    <tr><td>
        <div class="running-head">
            <span>{{ $cell['area'] }} &middot; cell {{ $cell['h3'] }}</span>
            <span>{{ $pack['acceptedOnly'] ? 'Accepted records' : 'All records' }}</span>
        </div>
    </td></tr>
</thead>

<tfoot>
    <tr><td>
        <div class="running-foot">
            Prepared {{ \Illuminate\Support\Carbon::parse($pack['preparedAt'])->format('j F Y, H:i') }} UTC
            by {{ $pack['preparedBy'] }} &middot; GeoVerify field enumeration
        </div>
    </td></tr>
</tfoot>

<tbody><tr><td>

{{-- The cover. The mark is the cell itself with the officer's walk inside it,
     which is the same geometry the review screen draws at 240px and the queue
     draws at 26. Generated, never designed. --}}
<section class="cover">
    <p class="label gold">Evidence pack</p>
    <h1 class="title display">Cell {{ $cell['h3'] }}</h1>
    <hr class="rule">

    <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 8mm;">
        <div>
            <p class="label">Mandate</p>
            <p style="font-size:11pt; margin:0 0 3mm;">{{ $cell['area'] }}</p>

            <p class="label">Client</p>
            <p style="margin:0 0 3mm;">{{ $cell['client'] }}</p>

            @if ($cell['contractRef'])
                <p class="label">Contract</p>
                <p class="mono" style="margin:0 0 3mm;">{{ $cell['contractRef'] }}</p>
            @endif

            <p class="label">Ward / LGA</p>
            <p style="margin:0 0 3mm;">{{ $cell['ward'] ?? 'not resolved' }} / {{ $cell['lga'] ?? 'not resolved' }}</p>

            <p class="label">Area</p>
            <p class="mono" style="margin:0;">{{ $cell['hectares'] }} ha</p>
        </div>

        <div>
            <svg class="mark" viewBox="{{ $map['viewBox'] }}" width="72mm" height="72mm"
                 role="img" aria-label="The officer's walking trace inside the assigned cell">
                <path d="{{ $map['cell'] }}Z" fill="none" stroke="#C9C2B4"
                      stroke-width="{{ $stroke * 1.5 }}" />
                @foreach ($map['traces'] as $trace)
                    <path d="{{ $trace }}" fill="none" stroke="#B08D2E"
                          stroke-width="{{ $stroke * 2 }}" stroke-linejoin="round" stroke-linecap="round" />
                @endforeach
                @foreach ($map['captures'] as [$x, $y])
                    <circle cx="{{ $x }}" cy="{{ $y }}" r="{{ $stroke * 2.4 }}" fill="#8A6D22" />
                @endforeach
            </svg>
            <p class="label" style="text-align:center; margin-top:2mm;">
                Presence mark &middot; {{ $summary['fixes'] }} recorded fixes
            </p>
        </div>
    </div>

    <hr class="rule">

    <div class="facts">
        <div>
            <p class="label">Structures</p>
            <p class="fact-value">{{ number_format($summary['structures']) }}</p>
        </div>
        <div>
            <p class="label">Businesses</p>
            <p class="fact-value">{{ number_format($summary['enterprises']) }}</p>
        </div>
        <div>
            <p class="label">Detected buildings</p>
            <p class="fact-value">{{ number_format($cell['footprints']) }}</p>
        </div>
        <div>
            <p class="label">Mean confidence</p>
            <p class="fact-value">{{ $summary['meanConfidence'] ?? '&mdash;' }}</p>
        </div>
    </div>

    <hr class="hairline">

    <p style="margin:0; max-width: 100mm; color:#4A5A68;">
        @if ($pack['acceptedOnly'])
            <span class="badge">Accepted only</span>
            This pack contains the records a supervisor accepted. Work still in review,
            or returned to the officer, is not included.
        @else
            <span class="badge warn">All records</span>
            This pack contains every record captured in this cell, including work
            still in review and work that was returned. It is an internal audit
            document rather than a delivery.
        @endif
    </p>
</section>

{{-- The ground, and the walk over it. --}}
<section>
    <p class="label gold">01</p>
    <h2 class="section display">The ground and the walk</h2>

    <svg viewBox="{{ $map['viewBox'] }}" width="100%" height="150mm"
         role="img" aria-label="The cell boundary, the officer's traces, and every capture point">
        <path d="{{ $map['cell'] }}Z" fill="#F1EEE7" stroke="#8C99A5" stroke-width="{{ $stroke }}" />
        @foreach ($map['traces'] as $trace)
            <path d="{{ $trace }}" fill="none" stroke="#B08D2E" stroke-width="{{ $stroke * 1.6 }}"
                  stroke-linejoin="round" stroke-linecap="round" />
        @endforeach
        @foreach ($map['captures'] as [$x, $y])
            <circle cx="{{ $x }}" cy="{{ $y }}" r="{{ $stroke * 2 }}"
                    fill="#2F6D4F" stroke="#FBFAF7" stroke-width="{{ $stroke * 0.5 }}" />
        @endforeach
    </svg>

    <hr class="hairline">

    <div class="facts">
        <div>
            <p class="label">Distance walked</p>
            <p class="fact-value">{{ number_format($summary['distanceM'] / 1000, 1) }} km</p>
        </div>
        <div>
            <p class="label">Position fixes</p>
            <p class="fact-value">{{ number_format($summary['fixes']) }}</p>
        </div>
        <div>
            <p class="label">Sessions</p>
            <p class="fact-value">{{ $summary['sessions'] }}</p>
        </div>
        <div>
            <p class="label">Officers</p>
            <p class="fact-value">{{ $summary['officers'] }}</p>
        </div>
    </div>

    <p style="margin-top:4mm; color:#4A5A68;">
        Every boundary, trace and point on this page is drawn from the geometry held in
        the register. The trace is the officer's own recorded positions in the order they
        were recorded, not a route inferred afterwards.
        @if ($summary['firstCapture'])
            Work in this cell ran from
            {{ \Illuminate\Support\Carbon::parse($summary['firstCapture'])->format('j F Y, H:i') }}
            to {{ \Illuminate\Support\Carbon::parse($summary['lastCapture'])->format('j F Y, H:i') }} UTC.
        @endif
    </p>
</section>

{{-- The records. --}}
<section>
    <p class="label gold">02</p>
    <h2 class="section display">Records</h2>

    <table>
        <thead>
            <tr>
                <th class="num">#</th>
                <th>Structure</th>
                <th>Occupancy</th>
                <th class="num">Floors</th>
                <th class="num">Units</th>
                <th>Businesses</th>
                <th>Officer</th>
                <th class="num">Conf.</th>
                <th class="num">Longitude</th>
                <th class="num">Latitude</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($pack['records'] as $record)
                <tr>
                    <td class="num">{{ $record['id'] }}</td>
                    <td>{{ str_replace('_', ' ', $record['structure_type']) }}</td>
                    <td>{{ str_replace('_', ' ', (string) $record['occupancy_status']) }}</td>
                    <td class="num">{{ $record['floors'] ?? '' }}</td>
                    <td class="num">{{ $record['unit_count'] ?? '' }}</td>
                    <td>{{ $record['businesses'] ?? '' }}</td>
                    <td>{{ $record['officer'] }}</td>
                    <td class="num">{{ $record['confidence_score'] ?? '' }}</td>
                    <td class="num">{{ $record['longitude'] }}</td>
                    <td class="num">{{ $record['latitude'] }}</td>
                </tr>
            @empty
                <tr><td colspan="10">No records in this cell under the selected scope.</td></tr>
            @endforelse
        </tbody>
    </table>
</section>

{{-- The contact sheet. --}}
<section>
    <p class="label gold">03</p>
    <h2 class="section display">Photographs</h2>

    @if (count($pack['photographs']) === 0)
        <p style="color:#4A5A68;">No photographs were attached to the records in this cell.</p>
    @else
        <div class="sheet">
            @foreach ($pack['photographs'] as $shot)
                <figure class="shot" style="margin:0;">
                    <div class="frame">
                        @if ($shot['data_uri'])
                            <img src="{{ $shot['data_uri'] }}" alt="{{ $shot['kind'] }} of {{ $shot['subject'] }}">
                        @else
                            <span class="missing">
                                file not present<br>{{ \Illuminate\Support\Str::limit((string) $shot['disk_path'], 28) }}
                            </span>
                        @endif
                    </div>
                    <figcaption class="caption">
                        <strong>{{ str_replace('_', ' ', $shot['kind']) }}</strong><br>
                        {{ \Illuminate\Support\Str::limit((string) $shot['subject'], 30) }}<br>
                        <span class="mono">
                            {{ $shot['from_device_camera'] ? 'device camera' : 'no camera metadata' }}
                            @if ($shot['distance_from_subject_m'] !== null)
                                &middot; {{ round((float) $shot['distance_from_subject_m']) }} m
                            @endif
                        </span>
                    </figcaption>
                </figure>
            @endforeach
        </div>
    @endif
</section>

{{-- The audit log. This is the part that is the product. --}}
<section>
    <p class="label gold">04</p>
    <h2 class="section display">Audit log</h2>

    <p style="margin:0 0 4mm; max-width:130mm; color:#4A5A68;">
        Every transition of every record in this cell, in the order it happened. Nothing in
        this system is deleted or overwritten: a correction is a new observation and a
        decision is an appended row, so this log is the whole history rather than a summary
        of it.
    </p>

    <table>
        <thead>
            <tr>
                <th class="num">When (UTC)</th>
                <th>Event</th>
                <th>Subject</th>
                <th>Actor</th>
                <th>Detail</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($pack['audit'] as $entry)
                <tr>
                    <td class="num">{{ \Illuminate\Support\Carbon::parse($entry['occurredAt'])->format('Y-m-d H:i:s') }}</td>
                    <td class="mono">{{ $entry['event'] }}</td>
                    <td class="mono">{{ $entry['subject'] }}</td>
                    <td>{{ $entry['actor'] }}</td>
                    <td style="font-size:7pt; color:#4A5A68;">{{ $entry['detail_summary'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</section>

</td></tr></tbody>
</table>

</body>
</html>
