<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Guarantor form: GeoVerify field agent</title>
    <link rel="icon" href="/favicon.ico" sizes="any">
    <style>
        :root { color-scheme: light; }
        body { font-family: Georgia, 'Times New Roman', serif; color: #111; background: #fff; margin: 0; }
        main { max-width: 760px; margin: 0 auto; padding: 32px 24px 48px; }
        h1 { font-size: 22px; margin: 0 0 4px; }
        p, li { font-size: 14px; line-height: 1.55; }
        .muted { color: #555; }
        .row { display: flex; gap: 16px; margin: 18px 0 0; }
        .field { flex: 1; }
        .field span { display: block; font-size: 12px; text-transform: uppercase; letter-spacing: .05em; color: #555; }
        .line { border-bottom: 1px solid #222; height: 28px; }
        .box { border: 1px solid #222; padding: 12px 16px; margin-top: 18px; }
        .photo { width: 120px; height: 150px; border: 1px dashed #222; display: flex; align-items: center; justify-content: center; font-size: 12px; color: #555; text-align: center; }
        .print { margin: 0 0 24px; padding: 10px 18px; font: 600 15px system-ui, sans-serif; border: 0; border-radius: 999px; background: #0E4A44; color: #fff; cursor: pointer; }
        @media print { .print { display: none; } main { padding: 0; } }
    </style>
</head>
<body>
<main>
    <button class="print" onclick="window.print()">Print this form</button>
    <div style="display:flex;justify-content:space-between;gap:24px;align-items:flex-start">
        <div>
            <h1>Guarantor form</h1>
            <p class="muted">For a person applying to work as a GeoVerify field agent. Please fill in every line in ink, sign, and attach a copy of your government ID.</p>
        </div>
        <div class="photo">Guarantor's<br>passport<br>photograph</div>
    </div>

    <h2 style="font-size:16px;margin-top:24px">1. The applicant</h2>
    <div class="row"><div class="field"><span>Applicant's full name</span><div class="line"></div></div></div>
    <div class="row"><div class="field"><span>Applicant's phone number</span><div class="line"></div></div><div class="field"><span>Application reference (if known)</span><div class="line"></div></div></div>

    <h2 style="font-size:16px;margin-top:24px">2. You, the guarantor</h2>
    <div class="row"><div class="field"><span>Full name</span><div class="line"></div></div></div>
    <div class="row"><div class="field"><span>Occupation and employer</span><div class="line"></div></div><div class="field"><span>Phone number</span><div class="line"></div></div></div>
    <div class="row"><div class="field"><span>Home or office address</span><div class="line"></div></div></div>
    <div class="row"><div class="field"><span>How do you know the applicant, and for how long?</span><div class="line"></div></div></div>
    <div class="row"><div class="field"><span>Type and number of your ID (NIN slip, voter's card, driver's licence or passport)</span><div class="line"></div></div></div>

    <div class="box">
        <p><strong>I declare that:</strong></p>
        <ol>
            <li>I have known the applicant named above personally and believe them to be honest and of good character.</li>
            <li>I am not a relative of the applicant.</li>
            <li>If the applicant is engaged by GeoVerify and is issued equipment (such as a phone, power bank, vest or ID card), I will help GeoVerify recover it, or its value, should the applicant fail to return it.</li>
            <li>I will inform GeoVerify if I learn of anything that makes the applicant unfit to visit businesses and homes on GeoVerify's behalf.</li>
            <li>GeoVerify may contact me to confirm this form.</li>
        </ol>
    </div>

    <div class="row" style="margin-top:28px"><div class="field"><span>Guarantor's signature</span><div class="line"></div></div><div class="field"><span>Date</span><div class="line"></div></div></div>

    <p class="muted" style="margin-top:28px">GeoVerify keeps the details on this form private, encrypted, and seen only by the staff who review applications, under the Nigeria Data Protection Act 2023. Once signed, the applicant sends us a clear photo of this page and of your ID from their application.</p>
</main>
</body>
</html>
