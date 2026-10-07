@php
    /** @var array<string, mixed> $report */
    $r = $report['request'];
    $cac = $r['registry']['cac']['facts'] ?? null;
    $tinFacts = $r['registry']['tin']['facts'] ?? null;
    $loc = $report['location'];
    $mon = $r['monitoring'] ?? null;
    $directors = is_array($cac['directors'] ?? null) ? $cac['directors'] : [];
    $day = fn (?string $iso) => $iso === null ? '' : \Illuminate\Support\Carbon::parse($iso)->timezone(config('app.timezone'))->format('j M Y');
    $time = fn (?string $iso) => $iso === null ? '' : \Illuminate\Support\Carbon::parse($iso)->timezone(config('app.timezone'))->format('H:i');
    $registration = ($r['companyType'] === 'BUSINESS_NAME' ? 'BN ' : 'RC ').$r['rcNumber'];
    $negative = in_array($report['finding'], ['Not as described', 'Partly as described'], true);
    $tone = ['open' => '#0E7C72', 'low' => '#E9B85C', 'closed' => '#C9CFCC', 'pending' => '#8AA6F5', 'no_visit' => '#E6E9E6', 'missed' => '#FFFFFF', 'upcoming' => '#FFFFFF'];
@endphp
<!DOCTYPE html>
<html lang="en" data-mode="daylight">
<head>
    <meta charset="utf-8">
    <title>Business Verification Report {{ $r['reference'] }}</title>
    @vite(['resources/css/app.css'])
    <style>
        /*
         * A4, several pages when monitoring runs long. The masthead, the score
         * and the QR code stay together on the first page, so the part a
         * reader checks first is never separated from the proof it is genuine.
         */
        @page { size: A4 portrait; margin: 13mm 14mm 16mm; }
        html, body { background: #FFFFFF; color: #0F1A17; }
        body {
            font-family: 'Plus Jakarta Sans', system-ui, sans-serif;
            font-size: 9pt;
            line-height: 1.45;
            font-variant-numeric: tabular-nums;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
            margin: 0;
        }
        .mono { font-family: 'JetBrains Mono', monospace; }
        .muted { color: #5F6B66; }
        .masthead { display: flex; justify-content: space-between; align-items: flex-start; gap: 8mm; padding-bottom: 3mm; border-bottom: 1.2pt solid #0E7C72; }
        .brand { display: flex; align-items: center; gap: 2.5mm; }
        .brand b { font-family: 'Montserrat', sans-serif; font-size: 15pt; color: #4DB8B0; display: block; line-height: 1; }
        .brand small { font-size: 7pt; color: #5F6B66; font-weight: 700; }
        .title { text-align: right; display: flex; gap: 4mm; align-items: flex-start; }
        .title h1 { font-size: 13pt; margin: 0; font-weight: 800; }
        .title p { margin: 0.5mm 0 0; font-size: 7.5pt; }
        .qr svg { width: 22mm; height: 22mm; display: block; }
        .subject { display: flex; justify-content: space-between; gap: 6mm; margin-top: 5mm; }
        .eyebrow { font-size: 6.5pt; font-weight: 800; letter-spacing: 0.08em; text-transform: uppercase; color: #5F6B66; margin: 0; }
        .subject h2 { font-size: 15pt; margin: 1mm 0 0.5mm; font-weight: 800; }
        .chips { display: flex; gap: 2mm; margin-top: 2mm; }
        .chip { font-size: 7pt; font-weight: 800; padding: 0.8mm 2mm; border-radius: 1.2mm; background: #EEF0EE; }
        .chip.dark { background: #0F1A17; color: #FFFFFF; }
        .score { min-width: 44mm; text-align: center; padding: 4mm 5mm; border-radius: 3mm; background: #E0F3F1; }
        .score.negative { background: #FDEDE5; }
        .score b { font-size: 24pt; font-weight: 800; color: #0A5E57; line-height: 1; }
        .score.negative b { color: #A3360A; }
        .score span { font-size: 9pt; color: #5F6B66; }
        .score p { margin: 1.5mm 0 0; font-size: 8pt; font-weight: 800; color: #0A5E57; }
        .score.negative p { color: #A3360A; }
        section { margin-top: 6mm; break-inside: avoid; }
        .head { display: flex; justify-content: space-between; align-items: baseline; border-bottom: 0.6pt solid #D5DBD7; padding-bottom: 1.2mm; margin-bottom: 2mm; }
        .head h3 { font-size: 10pt; margin: 0; font-weight: 800; }
        .head .status { font-size: 7.5pt; font-weight: 800; color: #0A5E57; text-transform: uppercase; letter-spacing: 0.04em; }
        .head .status.bad { color: #A3360A; }
        .grid2 { display: grid; grid-template-columns: 1fr 1fr; column-gap: 8mm; }
        .row { display: flex; justify-content: space-between; gap: 4mm; padding: 0.9mm 0; border-bottom: 0.4pt solid #EEF0EE; }
        .row b { font-weight: 700; text-align: right; }
        ul.checks { list-style: none; margin: 0; padding: 0; }
        ul.checks li { padding: 0.8mm 0; }
        .ok { color: #0A5E57; font-weight: 800; }
        .warn { color: #7A4F0A; font-weight: 800; }
        .loc { display: grid; grid-template-columns: 42mm 1fr; gap: 6mm; align-items: start; }
        .distance { background: #EEF0EE; border-radius: 2mm; padding: 4mm; }
        .distance b { font-size: 18pt; font-weight: 800; display: block; line-height: 1; }
        .photos { display: grid; grid-template-columns: repeat(4, 1fr); gap: 3mm; margin-top: 3mm; }
        .photos figure { margin: 0; }
        .photos img { width: 100%; height: 26mm; object-fit: cover; border-radius: 1.5mm; display: block; }
        .photos figcaption { font-size: 7pt; margin-top: 0.8mm; font-weight: 700; }
        .calendar { display: flex; flex-wrap: wrap; gap: 1mm; margin: 1mm 0 3mm; }
        .calendar span { width: 4.4mm; height: 4.4mm; border-radius: 0.8mm; border: 0.4pt solid #D5DBD7; }
        table { width: 100%; border-collapse: collapse; font-size: 8pt; }
        th { text-align: left; font-size: 6.5pt; text-transform: uppercase; letter-spacing: 0.06em; color: #5F6B66; padding: 1mm 1.5mm; border-bottom: 0.6pt solid #D5DBD7; }
        td { padding: 1.2mm 1.5mm; border-bottom: 0.4pt solid #EEF0EE; vertical-align: top; }
        tr { break-inside: avoid; }
        footer { margin-top: 8mm; padding-top: 3mm; border-top: 0.6pt solid #D5DBD7; display: flex; justify-content: space-between; gap: 8mm; font-size: 7pt; color: #5F6B66; }
    </style>
</head>
<body>
    <div class="masthead">
        <div class="brand">
            <svg width="34" height="34" viewBox="0 0 48 48" aria-hidden="true"><circle cx="24" cy="24" r="21" fill="none" stroke="#4DB8B0" stroke-width="3"/><path d="M15 24.5l6.5 6.5L34 17.5" fill="none" stroke="#0E7C72" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"/></svg>
            <div><b>Enumerate</b><small>by GeoVerify</small></div>
        </div>
        <div class="title">
            <div>
                <h1>Business Verification Report</h1>
                <p class="mono muted">{{ $r['reference'] }}</p>
                <p class="muted">{{ $report['final'] ? 'Final' : 'Interim' }} · issued {{ $report['issuedAt']->format('j M Y, H:i') }}</p>
            </div>
            <div class="qr">{!! $report['qr'] !!}</div>
        </div>
    </div>

    <div class="subject">
        <div>
            <p class="eyebrow">Subject</p>
            <h2>{{ $cac['name'] ?? $r['business'] }}</h2>
            <p class="muted" style="margin:0">
                {{ $registration }}@if ($report['tin']) · TIN {{ $report['tin'] }}@endif
                @if ($r['registeredAddress']) · {{ $r['registeredAddress'] }}@endif
            </p>
            <div class="chips">
                <span class="chip dark">{{ strtoupper($r['tierLabel']) }}</span>
                <span class="chip">Requested by {{ $r['requestedBy'] ?? 'the account holder' }} · {{ $day($r['requestedAt']) }}</span>
            </div>
        </div>
        <div class="score {{ $negative ? 'negative' : '' }}">
            <b>{{ $report['score'] }}</b><span>/100</span>
            <p>{{ $report['finding'] }}</p>
            @unless ($report['final'])<p class="muted" style="font-weight:600">so far</p>@endunless
        </div>
    </div>

    <section>
        <div class="head">
            <h3>1 · Registry check</h3>
            @if ($r['registry']['outcome'] === 'passed')
                <span class="status">Passed</span>
            @elseif ($r['registry']['outcome'] === 'failed')
                <span class="status bad">Failed</span>
            @else
                <span class="status muted">Under way</span>
            @endif
        </div>
        @if ($r['registry']['outcome'] === 'failed' && $r['registry']['reason'])
            <p class="warn" style="margin:0 0 2mm">{{ $r['registry']['reason'] }}</p>
        @endif
        <div class="grid2">
            <div>
                <div class="row"><span class="muted">CAC status</span><b>{{ $cac['status'] ?? 'No answer' }}</b></div>
                <div class="row"><span class="muted">Incorporated</span><b>{{ isset($cac['incorporatedOn']) ? $day($cac['incorporatedOn']) : '·' }}</b></div>
                <div class="row"><span class="muted">Company type</span><b>{{ ucfirst(strtolower(str_replace('_', ' ', $cac['companyType'] ?? $r['companyType']))) }}</b></div>
                <div class="row"><span class="muted">Directors</span><b>{{ $directors === [] ? '·' : implode(', ', array_map(fn ($d) => $d['name'], $directors)) }}</b></div>
            </div>
            <div>
                <div class="row"><span class="muted">TIN</span><b class="mono">{{ $tinFacts['tin'] ?? 'None held' }}</b></div>
                <div class="row"><span class="muted">Name on TIN</span><b>{{ ($r['registry']['tin']['outcome'] ?? null) === 'matched' ? 'Matches CAC' : ($tinFacts['nameOnTin'] ?? '·') }}</b></div>
                <div class="row"><span class="muted">Checked against</span><b>CAC and FIRS registers</b></div>
                <div class="row"><span class="muted">Checked</span><b>{{ $r['registry']['decidedAt'] ? $day($r['registry']['decidedAt']).', '.$time($r['registry']['decidedAt']) : '·' }}</b></div>
            </div>
        </div>
    </section>

    @if ($r['tier'] > 1)
        <section>
            <div class="head">
                <h3>2 · Location verification</h3>
                @if (($loc['status'] ?? null) === 'accepted')
                    <span class="status">Confirmed · {{ $day($loc['confirmedAt']) }}</span>
                @else
                    <span class="status muted">{{ $r['registry']['outcome'] === 'failed' ? 'Not visited' : 'Under way' }}</span>
                @endif
            </div>
            @if (($loc['status'] ?? null) === 'accepted')
                <div class="loc">
                    <div class="distance">
                        <b>{{ $loc['distanceM'] === null ? '·' : round($loc['distanceM']).' m' }}</b>
                        <span class="muted">from the CAC registered address</span>
                        @if ($loc['area'])<p style="margin:2mm 0 0;font-weight:700">{{ $loc['area'] }}</p>@endif
                    </div>
                    <div>
                        <ul class="checks">
                            @foreach ($loc['checklist'] ?? [] as $c)
                                <li>
                                    <span class="{{ $c['passed'] ? 'ok' : 'warn' }}">{{ $c['passed'] ? '✓' : '!' }}</span>
                                    {{ $c['label'] }}@if ($c['detail']) <span class="muted">· {{ $c['detail'] }}</span>@endif
                                </li>
                            @endforeach
                        </ul>
                        <p class="muted" style="margin:2mm 0 0">
                            Agent {{ $loc['agentRef'] }}@if ($loc['minutesOnSite']) · {{ $loc['minutesOnSite'] }} {{ $loc['minutesOnSite'] === 1 ? 'minute' : 'minutes' }} on site @endif
                            @if ($loc['otherPhotos'] > 0) · {{ $loc['otherPhotos'] }} further photographs reviewed by our supervisor, not reproduced @endif
                        </p>
                    </div>
                </div>
                @if (($loc['photos'] ?? []) !== [])
                    <div class="photos">
                        @foreach ($loc['photos'] as $p)
                            @if ($p['data'])
                                <figure><img src="{{ $p['data'] }}" alt="{{ $p['kind'] }}"><figcaption>{{ $p['kind'] }} · {{ $time($p['at']) }}</figcaption></figure>
                            @endif
                        @endforeach
                    </div>
                @endif
            @else
                <p class="muted" style="margin:0">
                    {{ $r['registry']['outcome'] === 'failed'
                        ? 'No officer was sent: the registers did not describe the business asked about.'
                        : 'An officer visits once the registry check has passed; this section is completed then.' }}
                </p>
            @endif
        </section>
    @endif

    @if ($r['tier'] === 3 && $mon)
        <section style="break-inside:auto">
            <div class="head">
                <h3>3 · Daily activity (days {{ $mon['dayToday'] ? '1 to '.min($mon['dayToday'], $mon['days']) : '1 to '.$mon['days'] }} of {{ $mon['days'] }})</h3>
                <span class="status {{ $report['final'] ? '' : 'muted' }}">{{ $report['final'] ? 'Complete' : 'In progress' }}</span>
            </div>
            <div class="calendar">
                @foreach ($mon['calendar'] as $c)
                    <span style="background: {{ $tone[$c['state']] }};{{ $c['state'] === 'missed' ? 'border: 0.6pt dashed #A3360A;' : '' }}" title="Day {{ $c['day'] }}"></span>
                @endforeach
            </div>
            <table>
                <thead><tr><th>Day</th><th>Date</th><th>Hours seen</th><th>Staff</th><th>Activity</th><th>Agent</th></tr></thead>
                <tbody>
                    @forelse ($mon['entries'] as $e)
                        <tr>
                            <td>{{ $e['day'] }}</td>
                            <td>{{ \Illuminate\Support\Carbon::parse($e['date'])->format('D j M') }}</td>
                            <td>{{ $e['state'] === 'closed' ? 'Closed' : (($e['opens'] ?? '?').' to '.($e['closes'] ?? '?')) }}</td>
                            <td>{{ $e['staff'] ?? '·' }}</td>
                            <td>{{ $e['activity'] }}</td>
                            <td class="mono">{{ $e['agentRef'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="muted">No daily log has been accepted yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
            @if ($mon['missedDays'] > 0)
                <p class="muted" style="margin:2mm 0 0">{{ $mon['missedDays'] }} trading {{ $mon['missedDays'] === 1 ? 'day' : 'days' }} without a log{{ $mon['returnedMinor'] > 0 ? ', refunded to the requester' : '' }}.</p>
            @endif
        </section>
    @endif

    <footer>
        <span>This report records what GeoVerify agents and official registers showed on the dates stated. Scan the QR code or visit {{ $report['verifyUrl'] }} to confirm it is genuine.</span>
        <span style="text-align:right;white-space:nowrap">Generated {{ $report['issuedAt']->format('j M Y, H:i') }} WAT</span>
    </footer>
</body>
</html>
