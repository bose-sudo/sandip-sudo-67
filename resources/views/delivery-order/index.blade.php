<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Delivery Order</title>
    <style>
        :root {
            --blue: #12468a;
            --blue-2: #1c6088;
            --green: #339c86;
            --line: #e3e9f0;
            --muted: #6b7c93;
            --ink: #0f2440;
            --ok: #1e9e6a;
            --err: #d64545;
            --radius: 14px;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Inter, Roboto, sans-serif;
            background: #f4f7fb;
            color: var(--ink);
            min-height: 100vh;
            padding: 24px;
        }

        .shell {
            max-width: 1180px;
            margin: 0 auto;
            display: flex;
            flex-direction: column;
            height: calc(100vh - 48px)
        }

        .container {
            background-color: #12468a;
            background: linear-gradient(320deg, #1c6088, #339c86, #12468a, #12468a);
            box-shadow: 2px 19px 31px rgba(0, 0, 0, .2);
            background-size: cover, auto;
            background-repeat: no-repeat, repeat;
            background-blend-mode: overlay;
            backdrop-filter: blur(20px) saturate(150%);
            -webkit-backdrop-filter: blur(20px) saturate(150%);
            min-width: 215px;
            height: 100%;
            overflow-x: hidden;
            overflow-y: auto;
            position: relative;
            z-index: 2;
            border-radius: 20px;
            padding: 22px;
        }

        .hd {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 18px
        }

        .hd h1 {
            color: #fff;
            font-size: 19px;
            font-weight: 600;
            letter-spacing: .2px
        }

        .hd .tag {
            background: rgba(255, 255, 255, .16);
            color: #eaf4ff;
            font-size: 11px;
            padding: 5px 10px;
            border-radius: 20px
        }

        .card {
            background: #fff;
            border-radius: var(--radius);
            padding: 20px;
            box-shadow: 0 6px 22px rgba(9, 30, 66, .10);
            margin-bottom: 16px
        }

        .card h2 {
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 14px;
            display: flex;
            align-items: center;
            gap: 8px
        }

        .card h2::before {
            content: "";
            width: 4px;
            height: 15px;
            border-radius: 3px;
            background: linear-gradient(180deg, var(--green), var(--blue))
        }

        .grid {
            display: grid;
            gap: 14px
        }

        .g-auth {
            grid-template-columns: 1fr 1fr auto;
            align-items: end
        }

        .g-3 {
            grid-template-columns: repeat(3, 1fr)
        }

        @media(max-width:860px) {

            .g-auth,
            .g-3 {
                grid-template-columns: 1fr
            }
        }

        .field label {
            display: block;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: .5px;
            color: var(--muted);
            margin-bottom: 6px
        }

        .field input,
        .field select {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid var(--line);
            border-radius: 9px;
            font-size: 14px;
            color: var(--ink);
            background: #fbfdff;
            outline: none;
            transition: .15s;
        }

        .field input:focus,
        .field select:focus {
            border-color: var(--green);
            background: #fff;
            box-shadow: 0 0 0 3px rgba(51, 156, 134, .15)
        }

        .field input[readonly] {
            background: #f1f5f9;
            color: var(--muted)
        }

        .field .err {
            color: var(--err);
            font-size: 11px;
            margin-top: 4px;
            display: none
        }

        .field.invalid input {
            border-color: var(--err)
        }

        .field.invalid .err {
            display: block
        }

        .btn {
            border: 0;
            cursor: pointer;
            font-size: 13px;
            font-weight: 600;
            padding: 11px 20px;
            border-radius: 9px;
            transition: .15s;
            white-space: nowrap
        }

        .btn:disabled {
            opacity: .55;
            cursor: not-allowed
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--blue-2), var(--blue));
            color: #fff
        }

        .btn-green {
            background: linear-gradient(135deg, #3ab795, var(--green));
            color: #fff
        }

        .btn-ghost {
            background: #fff;
            color: var(--blue);
            border: 1px solid var(--line)
        }

        .btn:not(:disabled):hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 14px rgba(9, 30, 66, .16)
        }

        .actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 16px;
            flex-wrap: wrap
        }

        .hidden {
            display: none !important
        }

        .alert {
            padding: 11px 14px;
            border-radius: 9px;
            font-size: 13px;
            margin-bottom: 14px
        }

        .alert-ok {
            background: #e8f7f1;
            color: #136f4c;
            border: 1px solid #b9e5d4
        }

        .alert-err {
            background: #fdecec;
            color: #9b2c2c;
            border: 1px solid #f5c2c2
        }

        .preview {
            height: 70vh;
            min-height: 460px;
            border: 1px solid var(--line);
            border-radius: 10px;
            overflow: hidden;
            background: #f7f9fc
        }

        .preview iframe {
            width: 100%;
            height: 100%;
            border: 0
        }

        .spin {
            display: inline-block;
            width: 14px;
            height: 14px;
            border: 2px solid rgba(255, 255, 255, .4);
            border-top-color: #fff;
            border-radius: 50%;
            animation: sp .7s linear infinite;
            vertical-align: -2px;
            margin-right: 6px
        }

        @keyframes sp {
            to {
                transform: rotate(360deg)
            }
        }

        .overlay {
            position: fixed;
            inset: 0;
            background: rgba(9, 30, 66, .35);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 50
        }

        .overlay .box {
            background: #fff;
            padding: 18px 26px;
            border-radius: 12px;
            font-size: 13px;
            font-weight: 600;
            color: var(--blue)
        }

        .overlay .box.ok {
            color: #136f4c;
            border-left: 4px solid #1e9e6a
        }

        .overlay .box.err {
            color: #9b2c2c;
            border-left: 4px solid var(--err)
        }

        .overlay .box .ico {
            font-size: 22px;
            display: block;
            margin-bottom: 6px;
            text-align: center
        }
    </style>
</head>

<body>
    <div class="shell">
        <div class="container">
            <div class="hd">
                <h1>Delivery Order</h1>
                <span class="tag" id="stateTag">Step 1 — Authenticate</span>
            </div>

            <div id="alertBox"></div>

            <!-- STEP 1 : AUTH -->
            <div class="card" id="authCard">
                <h2>Borrower Lookup</h2>
                <div class="grid g-auth">
                    <div class="field" id="f_borrower">
                        <label>Borrower ID</label>
                        <input type="text" id="borrower_id" placeholder="abc-123" autocomplete="off">
                        <span class="err">Borrower ID is required</span>
                    </div>
                    <div class="field" id="f_token">
                        <label>Access Token</label>
                        <input type="password" id="token" maxlength="16" placeholder="14–16 characters"
                            autocomplete="off">
                        <span class="err">Token must be 14–16 characters</span>
                    </div>
                    <button class="btn btn-primary" id="btnCheck">Check Details</button>
                </div>
            </div>

            <!-- STEP 2 : DETAILS -->
            <div class="card hidden" id="detailsCard">
                <h2>Loan &amp; Vehicle Details</h2>
                <div class="grid g-3">
                    <div class="field"><label>Borrower Name</label><input type="text" id="borrower_name" readonly>
                    </div>
                    <div class="field"><label>Lender</label><input type="text" id="lender_name" readonly></div>
                    <div class="field"><label>Dealer</label><input type="text" id="dealer_name" readonly></div>

                    <div class="field"><label>DO Date</label><input type="date" id="do_date"></div>
                    <div class="field"><label>Vehicle Ex-Showroom Cost</label><input type="number" step="0.01"
                            id="ex_showroom_cost"></div>
                    <div class="field"><label>Loan Amount</label><input type="number" step="0.01" id="loan_amount">
                    </div>

                    <div class="field"><label>Down Payment</label><input type="number" step="0.01"
                            id="down_payment"></div>
                    <div class="field"><label>ROI (%)</label><input type="number" step="0.01" id="roi"></div>
                    <div class="field">
                        <label>ROI Type</label>
                        <select id="roi_type">
                            <option value="reducing">Reducing</option>
                            <option value="flat">Flat</option>
                        </select>
                    </div>

                    <div class="field"><label>Tenure (Months)</label><input type="number" id="tenure"></div>
                    <div class="field"><label>EMI (per Month)</label><input type="number" step="0.01"
                            id="emi"></div>
                    <div class="field"><label>File Charge (1% + GST)</label><input type="number" step="0.01"
                            id="file_charge"></div>

                    <div class="field" id="f_disb">
                        <label>Disbursement Amount *</label>
                        <input type="number" step="0.01" id="disbursement_amount" placeholder="Enter amount">
                        <span class="err">Disbursement amount is required</span>
                    </div>
                    <div class="field"><label>Borrower Email</label><input type="email" id="borrower_email"></div>
                    <div class="field"><label>Dealer Email</label><input type="email" id="dealer_email"></div>
                </div>

                <div class="actions">
                    <button class="btn btn-ghost" id="btnReset">Reset</button>
                    <button class="btn btn-green" id="btnGenerate">Generate DO</button>
                </div>
            </div>

            <!-- STEP 3 : PREVIEW -->
            <div class="card hidden" id="previewCard">
                <h2>Preview</h2>
                <div class="preview"><iframe id="pdfFrame" src="about:blank"></iframe></div>
                <div class="actions">
                    <button class="btn btn-ghost" id="btnEdit">Edit Details</button>
                    <button class="btn btn-ghost" id="btnDownload">Open in New Tab</button>
                    <button class="btn btn-green hidden" id="btnNew">Create New DO</button>
                    <button class="btn btn-primary" id="btnSend">Send to Borrower &amp; Dealer</button>
                </div>
            </div>

        </div>
    </div>

    <div class="overlay hidden" id="overlay">
        <div class="box" id="overlayText">Processing…</div>
    </div>

    <script>
        const ROUTES = {
            details: "{{ route('delivery-order.details') }}",
            generate: "{{ route('delivery-order.generate') }}",
            send: "{{ route('delivery-order.send') }}",
        };
        const CSRF = document.querySelector('meta[name=csrf-token]').content;
        const $ = id => document.getElementById(id);

        const FIELDS = ['do_date', 'ex_showroom_cost', 'loan_amount', 'down_payment', 'roi', 'roi_type',
            'tenure', 'emi', 'file_charge', 'disbursement_amount', 'borrower_email', 'dealer_email',
            'borrower_name', 'lender_name', 'dealer_name'
        ];

        let STATE = {
            lender_id: null,
            do_id: null,
            file_url: null
        };

        function loading(on, text = 'Processing…') {
            const box = $('overlayText');
            box.className = 'box';
            box.textContent = text;
            $('overlay').classList.toggle('hidden', !on);
        }

        const sleep = ms => new Promise(r => setTimeout(r, ms));

        /* Shows a result state in the overlay for `hold` ms, then closes it. */
        async function result(type, text, hold = 1600) {
            const box = $('overlayText');
            box.className = 'box ' + (type === 'ok' ? 'ok' : 'err');
            // box.innerHTML = `<span class="ico">${type==='ok'?'✓':'✕'}</span>${text}`;
            box.textContent=text;
            $('overlay').classList.remove('hidden');
            await sleep(hold);
            $('overlay').classList.add('hidden');
            box.className = 'box';
        }

        function alertMsg(type, msg) {
            $('alertBox').innerHTML = `<div class="alert alert-${type}">${msg}</div>`;
            if (type === 'ok') setTimeout(() => $('alertBox').innerHTML = '', 4000);
        }

        function tag(t) {
            $('stateTag').textContent = t;
        }

        function invalid(id, on) {
            $(id).classList.toggle('invalid', on);
        }

        async function post(url, payload) {
            const res = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': CSRF,
                    'X-DO-Token': $('token').value.trim(),
                    'Accept': 'application/json'
                },
                body: JSON.stringify({
                    ...payload,
                    token: $('token').value.trim(),
                    borrower_id: $('borrower_id').value.trim()
                })
            });
            const data = await res.json().catch(() => ({
                message: 'Invalid server response'
            }));
            if (!res.ok) throw new Error(data.message || 'Request failed');
            return data;
        }

        function collect() {
            const o = {};
            FIELDS.forEach(f => o[f] = $(f).value);
            return o;
        }

        /* ---- Step 1: check ---- */
        $('btnCheck').onclick = async () => {
            const bid = $('borrower_id').value.trim(),
                tk = $('token').value.trim();
            invalid('f_borrower', !bid);
            invalid('f_token', tk.length < 14 || tk.length > 16);
            if (!bid || tk.length < 14 || tk.length > 16) return;

            loading(true, 'Fetching details…');
            try {
                const r = await post(ROUTES.details, {});
                const d = r.data || {};
                FIELDS.forEach(f => {
                    if (f !== 'disbursement_amount' && d[f] !== undefined && d[f] !== null) $(f).value = d[
                        f];
                });
                $('disbursement_amount').value = '';
                STATE.lender_id = d.lender_id ?? null;
                $('detailsCard').classList.remove('hidden');
                $('previewCard').classList.add('hidden');
                $('alertBox').innerHTML = '';
                tag('Step 2 — Verify & Generate');
                $('detailsCard').scrollIntoView({
                    behavior: 'smooth'
                });
            } catch (e) {
                alertMsg('err', e.message);
            } finally {
                loading(false);
            }
        };

        /* ---- Step 2: generate ---- */
        $('btnGenerate').onclick = async () => {
            const disb = $('disbursement_amount').value.trim();
            invalid('f_disb', !disb);
            if (!disb) return;

            loading(true, 'Generating Delivery Order…');
            try {
                const r = await post(ROUTES.generate, collect());
                STATE.do_id = r.do_id;
                STATE.file_url = r.file_url;
                STATE.file_url = r.file_url;
                $('pdfFrame').src = withToken(r.file_url) + '#toolbar=1&view=FitH';

                $('previewCard').classList.remove('hidden');
                $('detailsCard').classList.add('hidden');
                tag('Step 3 — Preview & Send');
                $('previewCard').scrollIntoView({
                    behavior: 'smooth'
                });
                await result('ok', 'Delivery Order generated.');
            } catch (e) {
                await result('err', e.message || 'Failed to generate Delivery Order.', 2200);
                alertMsg('err', e.message);
            } finally {
                $('overlay').classList.add('hidden');
            }
        };

        /* ---- Step 3: send / edit ---- */
        $('btnEdit').onclick = () => {
            $('previewCard').classList.add('hidden');
            $('detailsCard').classList.remove('hidden');
            tag('Step 2 — Verify & Generate');
        };

        $('btnDownload').onclick = () => STATE.file_url && window.open(withToken(STATE.file_url), '_blank');

        function withToken(url) {
            const sep = url.includes('?') ? '&' : '?';
            return url + sep + 'token=' + encodeURIComponent($('token').value.trim());
        }

        $('btnSend').onclick = async () => {
            if (!confirm('Send this Delivery Order to borrower and dealer?')) return;

            const started = Date.now();
            $('btnSend').disabled = true;
            loading(true, 'Sending email…');

            try {
                const r = await post(ROUTES.send, {
                    do_id: STATE.do_id,
                    cc: [],
                    bcc: ''
                });

                // keep the spinner visible at least 800ms so it never flashes
                const elapsed = Date.now() - started;
                if (elapsed < 800) await sleep(800 - elapsed);

                await result('ok', r.message || 'Mail sent successfully.');
                alertMsg('ok', r.message || 'Mail sent successfully.');
                $('btnSend').textContent = 'Resend to Borrower & Dealer';
                $('btnNew').classList.remove('hidden');
                tag('Sent');
            } catch (e) {
                const elapsed = Date.now() - started;
                if (elapsed < 800) await sleep(800 - elapsed);

                await result('err', e.message || 'Failed to send mail.', 2200);
                alertMsg('err', e.message || 'Failed to send mail.');
            } finally {
                $('btnSend').disabled = false;
                $('overlay').classList.add('hidden');
            }
        };

        function resetAll(keepToken = true) {
            FIELDS.forEach(f => $(f).value = '');
            $('borrower_id').value = '';
            if (!keepToken) $('token').value = '';
            $('pdfFrame').src = 'about:blank';
            $('detailsCard').classList.add('hidden');
            $('previewCard').classList.add('hidden');
            $('btnSend').disabled = false;
            $('btnSend').textContent = 'Send to Borrower & Dealer';
            $('btnNew').classList.add('hidden');
            $('alertBox').innerHTML = '';
            STATE = {
                lender_id: null,
                do_id: null,
                file_url: null
            };
            tag('Step 1 — Authenticate');
            $('borrower_id').focus();
        }

        $('btnReset').onclick = () => resetAll();
        $('btnNew').onclick = () => {
            window.scrollTo({
                top: 0,
                behavior: 'smooth'
            });
            resetAll(true);
        };

        $('token').addEventListener('input', () => invalid('f_token', false));
        $('borrower_id').addEventListener('input', () => invalid('f_borrower', false));
    </script>
</body>

</html>
