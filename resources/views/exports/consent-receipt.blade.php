<!DOCTYPE html>
<html lang="en" data-mode="daylight">
<head>
    <meta charset="utf-8">
    <title>Consent receipt {{ $receipt['reference'] }}</title>
    @vite(['resources/css/app.css'])
    <style>
        /*
         * One page, A4, and the disclosure gets most of it.
         *
         * Same frame as the certificate on purpose: a person who has both should
         * be able to see they came from the same register without reading the
         * wordmark. What is different is the hierarchy. On a certificate the
         * finding is the document; here the words the person was shown are the
         * document, and everything else is provenance for them.
         */
        @page { size: A4 portrait; margin: 14mm 16mm; }

        html, body { background: #FBFAF7; color: #16202B; }

        body {
            font-family: 'IBM Plex Sans', system-ui, sans-serif;
            font-size: 9.5pt;
            line-height: 1.5;
            font-variant-numeric: tabular-nums;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
            margin: 0;
        }

        .rule { border: 0; border-top: 1pt solid #16202B; margin: 0; }

        .masthead {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 10mm;
            padding-bottom: 3mm;
        }

        .wordmark {
            font-family: 'Newsreader', Georgia, serif;
            font-size: 16pt;
            letter-spacing: -0.01em;
            margin: 0;
        }

        .doctype {
            font-family: 'IBM Plex Mono', monospace;
            font-size: 7pt;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            color: #6B7A88;
            margin: 1mm 0 0;
        }

        .reference {
            font-family: 'IBM Plex Mono', monospace;
            font-size: 8pt;
            text-align: right;
            color: #6B7A88;
            line-height: 1.7;
        }
        .reference b { color: #16202B; font-weight: 500; display: block; font-size: 10pt; }

        .standing {
            margin: 5mm 0;
            padding: 5mm 6mm;
            border: 1pt solid #16202B;
        }
        .standing.lapsed { border-color: #8A6A1F; }

        .standing-label {
            font-family: 'IBM Plex Mono', monospace;
            font-size: 7pt;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            color: #6B7A88;
            margin: 0 0 1.5mm;
        }
        .standing-value {
            font-family: 'Newsreader', Georgia, serif;
            font-size: 19pt;
            line-height: 1.1;
            margin: 0;
        }
        .standing.lapsed .standing-value { color: #8A6A1F; }
        .subject { font-family: 'Newsreader', Georgia, serif; font-size: 13pt; margin: 2mm 0 0; }

        .section-head {
            font-family: 'IBM Plex Mono', monospace;
            font-size: 7pt;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            color: #6B7A88;
            margin: 5mm 0 2mm;
        }

        /*
         * The disclosure is set larger than the body around it. It is the only
         * part of this page anybody actually agreed to, and a document that
         * printed it in the smallest type on the sheet would be making the same
         * joke the phrase "small print" already makes.
         */
        .disclosure {
            margin: 0;
            padding: 4mm 5mm;
            border-left: 1.5pt solid #134E4A;
            background: #F4F1EA;
            font-size: 11pt;
            line-height: 1.6;
        }

        .scope-list { margin: 2mm 0 0; padding: 0; list-style: none; display: flex; flex-wrap: wrap; gap: 2mm; }
        .scope-list li {
            border: 0.5pt solid #D8D3C8;
            padding: 1mm 3mm;
            font-size: 8.5pt;
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 4mm 6mm;
            margin: 4mm 0 0;
        }
        .cell .k {
            font-family: 'IBM Plex Mono', monospace;
            font-size: 6.5pt;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: #6B7A88;
            margin: 0 0 0.8mm;
        }
        .cell .v { margin: 0; font-size: 9.5pt; }
        .cell .v.mono { font-family: 'IBM Plex Mono', monospace; font-size: 8.5pt; }

        .note { font-size: 8pt; line-height: 1.55; color: #43535F; margin: 2mm 0 0; }
        .note strong { color: #16202B; font-weight: 600; }

        .colophon {
            margin-top: 6mm;
            padding-top: 2.5mm;
            border-top: 0.5pt solid #D8D3C8;
            font-family: 'IBM Plex Mono', monospace;
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
        <p class="doctype">Consent receipt</p>
    </div>
    <div class="reference">
        <b>{{ $receipt['reference'] }}</b>
        Recorded {{ $receipt['agreed_on'] }}
    </div>
</header>

<hr class="rule">

<div class="standing {{ $receipt['state'] === 'standing' ? '' : 'lapsed' }}">
    <p class="standing-label">{{ $receipt['purpose'] }}</p>
    <p class="standing-value">
        @if ($receipt['state'] === 'standing')
            This agreement stands
        @elseif ($receipt['state'] === 'withdrawn')
            This agreement was withdrawn
        @elseif ($receipt['state'] === 'withdrawal')
            This withdrew an earlier agreement
        @else
            This was declined
        @endif
    </p>
    @if ($receipt['subject'] !== null)
        <p class="subject">{{ $receipt['subject'] }}</p>
    @endif
</div>

<p class="section-head">The words that were shown</p>
<blockquote class="disclosure">{{ $receipt['disclosure'] }}</blockquote>
<p class="note">
    Reproduced as it stood on {{ $receipt['agreed_on'] }}, wording
    <strong>{{ $receipt['disclosure_version'] }}</strong>. Wording we publish today may
    differ; this copy is unaffected by that, which is why it was written down here
    rather than pointed at.
</p>

@if ($receipt['scope'] !== [])
    <p class="section-head">What it covered</p>
    <ul class="scope-list">
        @foreach ($receipt['scope'] as $field)
            <li>{{ $field }}</li>
        @endforeach
    </ul>
    <p class="note">
        Nothing outside this list was agreed. A later, wider disclosure cannot claim
        this receipt as cover for it.
    </p>
@endif

<div class="grid">
    <div class="cell">
        <p class="k">Recorded on</p>
        <p class="v">{{ $receipt['agreed_on'] }}</p>
    </div>
    <div class="cell">
        <p class="k">Agreed by</p>
        <p class="v">{{ $receipt['agreed_by'] ?? 'Not recorded' }}</p>
    </div>
    <div class="cell">
        <p class="k">Lawful basis</p>
        <p class="v">{{ ucfirst($receipt['lawful_basis']) }}</p>
    </div>
    <div class="cell">
        <p class="k">Reference</p>
        <p class="v mono">{{ $receipt['reference'] }}</p>
    </div>
    <div class="cell">
        <p class="k">Withdrawn</p>
        <p class="v">{{ $receipt['withdrawn_on'] ?? 'Not withdrawn' }}</p>
    </div>
    <div class="cell">
        <p class="k">Recorded as</p>
        <p class="v">{{ $receipt['agreed_as'] === 'party' ? 'The business' : 'Staff' }}</p>
    </div>
</div>

@if ($receipt['state'] === 'standing')
    <p class="note" style="margin-top: 5mm;">
        <strong>You can withdraw at any time.</strong> The listing comes down immediately
        when you do. Withdrawing does not erase this receipt: it writes a second one
        saying you withdrew, because both things happened and this register keeps both.
    </p>
@endif

<div class="colophon">
    <span>GeoVerify register &middot; consent receipt {{ $receipt['reference'] }}</span>
    <span>Printed {{ now()->toDateString() }}</span>
</div>

</body>
</html>
