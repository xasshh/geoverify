<!DOCTYPE html>
<html lang="en" data-mode="daylight">
<head>
    <meta charset="utf-8">
    <title>Verification certificate {{ $certificate['reference'] }}</title>
    @vite(['resources/css/app.css'])
    <style>
        /*
         * One page, A4, and it has to stay one page. A certificate that runs
         * onto a second sheet gets separated from its own QR code the first
         * time somebody staples it, so everything below is sized to fit rather
         * than to flow.
         */
        @page { size: A4 portrait; margin: 14mm 16mm; }

        html, body { background: #FBFAF7; color: #16202B; }

        body {
            font-family: 'Plus Jakarta Sans', system-ui, sans-serif;
            font-size: 9.5pt;
            line-height: 1.5;
            font-variant-numeric: tabular-nums;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
            margin: 0;
        }

        .rule { border: 0; border-top: 1pt solid #16202B; margin: 0; }
        .hair { border: 0; border-top: 0.5pt solid #D8D3C8; margin: 0; }

        .masthead {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 10mm;
            padding-bottom: 3mm;
        }

        .wordmark {
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 16pt;
            letter-spacing: -0.01em;
            margin: 0;
        }

        .doctype {
            font-family: 'JetBrains Mono', monospace;
            font-size: 7pt;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            color: #6B7A88;
            margin: 1mm 0 0;
        }

        .reference {
            font-family: 'JetBrains Mono', monospace;
            font-size: 8pt;
            text-align: right;
            color: #6B7A88;
            line-height: 1.7;
        }
        .reference b { color: #16202B; font-weight: 500; display: block; font-size: 10pt; }

        /* The finding is the document. Everything else supports it. */
        .finding {
            margin: 5mm 0;
            padding: 5mm 6mm;
            border: 1pt solid #16202B;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 8mm;
        }
        .finding.negative { border-color: #8F2721; }

        .finding-label {
            font-family: 'JetBrains Mono', monospace;
            font-size: 7pt;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            color: #6B7A88;
            margin: 0 0 1.5mm;
        }
        .finding-value {
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 19pt;
            line-height: 1.1;
            margin: 0;
        }
        .finding.negative .finding-value { color: #8F2721; }

        .business-name {
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 13pt;
            margin: 0 0 1mm;
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 4mm 6mm;
            margin: 4mm 0;
        }
        .cell .k {
            font-family: 'JetBrains Mono', monospace;
            font-size: 6.5pt;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: #6B7A88;
            margin: 0 0 0.8mm;
        }
        .cell .v { margin: 0; font-size: 9.5pt; }
        .cell .v.mono { font-family: 'JetBrains Mono', monospace; font-size: 8.5pt; }

        .split { display: flex; gap: 8mm; margin-top: 4mm; }
        .split > .main { flex: 1 1 auto; }
        .split > .aside { flex: 0 0 42mm; text-align: center; }

        .qr { width: 38mm; height: 38mm; display: block; margin: 0 auto 2mm; }
        .qr-caption {
            font-family: 'JetBrains Mono', monospace;
            font-size: 6.5pt;
            line-height: 1.5;
            color: #6B7A88;
            word-break: break-all;
        }

        .shots { display: grid; grid-template-columns: repeat(4, 1fr); gap: 3mm; margin-top: 3mm; }
        .shots figure { margin: 0; }
        .shots img {
            width: 100%;
            height: 26mm;
            object-fit: cover;
            border: 0.5pt solid #D8D3C8;
            display: block;
        }
        .shots figcaption {
            font-family: 'JetBrains Mono', monospace;
            font-size: 6pt;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: #6B7A88;
            margin-top: 1mm;
        }

        .section-head {
            font-family: 'JetBrains Mono', monospace;
            font-size: 7pt;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            color: #6B7A88;
            margin: 5mm 0 2mm;
        }

        .scope {
            font-size: 8pt;
            line-height: 1.55;
            color: #43535F;
            margin: 2mm 0 0;
        }
        .scope strong { color: #16202B; font-weight: 600; }

        .colophon {
            margin-top: 5mm;
            padding-top: 2.5mm;
            font-family: 'JetBrains Mono', monospace;
            font-size: 6.5pt;
            letter-spacing: 0.06em;
            color: #97A3AE;
            display: flex;
            justify-content: space-between;
            gap: 6mm;
        }
    </style>
</head>
<body>

<header class="masthead">
    <div>
        <p class="wordmark">GeoVerify</p>
        <p class="doctype">Verification certificate</p>
    </div>
    <div class="reference">
        <b>{{ $certificate['reference'] }}</b>
        Issued {{ $certificate['issued_on'] }}
    </div>
</header>

<hr class="rule">

<div class="finding {{ $certificate['finding']['establishes_tier'] ? '' : 'negative' }}">
    <div>
        <p class="finding-label">Finding</p>
        <p class="finding-value">{{ $certificate['finding']['outcome'] }}</p>
    </div>
    <div style="text-align: right;">
        <p class="finding-label">Visited</p>
        <p class="finding-value" style="font-size: 12pt;">{{ $certificate['finding']['visited_on'] }}</p>
    </div>
</div>

<div class="split">
    <div class="main">
        <p class="business-name">{{ $certificate['business']['name'] }}</p>
        @if ($certificate['business']['registered_name'])
            <p style="margin: 0; color: #43535F; font-size: 8.5pt;">
                Registered as {{ $certificate['business']['registered_name'] }}
            </p>
        @endif

        <div class="grid">
            <div class="cell">
                <p class="k">Ward</p>
                <p class="v">{{ $certificate['place']['ward'] ?? 'Not resolved' }}</p>
            </div>
            <div class="cell">
                <p class="k">Local government</p>
                <p class="v">{{ $certificate['place']['lga'] ?? 'Not resolved' }}</p>
            </div>
            <div class="cell">
                <p class="k">State</p>
                <p class="v">{{ $certificate['place']['state'] ?? 'Not resolved' }}</p>
            </div>

            <div class="cell">
                <p class="k">Coordinates</p>
                <p class="v mono">
                    @if ($certificate['place']['latitude'] !== null)
                        {{ number_format($certificate['place']['latitude'], 6) }},
                        {{ number_format($certificate['place']['longitude'], 6) }}
                    @else
                        Not recorded
                    @endif
                </p>
            </div>
            <div class="cell">
                <p class="k">Fix accuracy</p>
                <p class="v mono">
                    {{ $certificate['place']['accuracy_m'] !== null
                        ? number_format((float) $certificate['place']['accuracy_m'], 1).' m'
                        : 'Not recorded' }}
                </p>
            </div>
            <div class="cell">
                <p class="k">Plus code</p>
                <p class="v mono">{{ $certificate['place']['plus_code'] ?? 'Not recorded' }}</p>
            </div>

            <div class="cell">
                <p class="k">Premises</p>
                <p class="v">{{ ucfirst(str_replace('_', ' ', (string) $certificate['place']['structure_type'])) }}</p>
            </div>
            <div class="cell">
                <p class="k">Attending officer</p>
                <p class="v mono">{{ $certificate['officer_reference'] }}</p>
            </div>
            <div class="cell">
                <p class="k">Tier established</p>
                <p class="v">
                    {{ $certificate['finding']['establishes_tier']
                        ? ucfirst(str_replace('_', ' ', (string) $certificate['finding']['tier']))
                        : 'None' }}
                </p>
            </div>
        </div>
    </div>

    <div class="aside">
        <div class="qr">{!! $certificate['check']['qr'] !!}</div>
        <p class="qr-caption">
            Check this certificate<br>
            {{ $certificate['check']['token'] }}
        </p>
    </div>
</div>

@if ($certificate['photographs'] !== [])
    <hr class="hair">
    <p class="section-head">Recorded at the visit</p>
    <div class="shots">
        @foreach ($certificate['photographs'] as $shot)
            <figure>
                <img src="{{ $shot['src'] }}" alt="{{ $shot['kind'] }}">
                <figcaption>{{ str_replace('_', ' ', $shot['kind']) }} &middot; {{ $shot['taken_on'] }}</figcaption>
            </figure>
        @endforeach
    </div>
@endif

<hr class="hair">
<p class="section-head">What this certificate says, and what it does not</p>

{{--
    The scope paragraph is the legally load bearing part of the document and it
    is written to be read rather than skipped. It says what was checked and by
    whom, and it refuses the reading a buyer would most like to make: that
    somebody official has approved this business. Nobody has. An officer went to
    an address and wrote down what was there.
--}}
<p class="scope">
    <strong>This certificate records a physical visit.</strong> An officer of this register
    attended the address above on {{ $certificate['finding']['visited_on'] }}, recorded a
    satellite position with the accuracy shown, and reported what they found. The ward, local
    government and state were resolved from the recorded position against official boundaries,
    not from anything the business stated about itself.
</p>
<p class="scope">
    <strong>It is not an endorsement, a licence or an approval.</strong> It carries no opinion
    from any government of the quality, solvency or conduct of this business, and it is not a
    registration under the Companies and Allied Matters Act. It says that a business trading
    under this name was found at this place on this date, and nothing further.
</p>
<p class="scope">
    <strong>It ages.</strong> This finding is
    {{ $certificate['finding']['elapsed'] }} old and currently reads as
    <strong>{{ $certificate['finding']['freshness'] }}</strong>.
    @if ($certificate['check']['valid_until'])
        The check above will report this certificate as expired after
        {{ $certificate['check']['valid_until'] }}.
    @endif
    A business may change hands or close on any day after a visit, and this document cannot know that.
</p>

<div class="colophon">
    <span>Lawful basis: {{ str_replace('_', ' ', $certificate['basis']['lawful_basis']) }} &middot; disclosure {{ $certificate['basis']['disclosure_version'] }}</span>
    <span>{{ $certificate['check']['url'] }}</span>
</div>

</body>
</html>
