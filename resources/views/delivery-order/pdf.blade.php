@php
    $lsp        = 'Finayo (Kilpi Technology and Service Pvt Ltd)';
    $legalName  = $lender_details['legal_name'] ?? '';
    $money      = fn ($v) => number_format((float) $v, 2, '.', ',');
    $doDate     = \Carbon\Carbon::parse($do->do_date);
    $modelName  = trim(($product->product_type ?? '') . ($product->name ?? '' ? ', ' . $product->name : ''), ', ');
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Delivery Order - {{ $loan->borrower_id }}</title>
<style>
  @page { margin: 28px 40px; }
  body { margin:0; padding:0; font-family:"Times New Roman", Times, serif; color:#000; font-size:13.5px; }

  table.hdr { width:100%; border-collapse:collapse; border-bottom:3px solid #1f5fa8; margin-bottom:14px; }
  table.hdr td { padding:0 0 8px 0; vertical-align:middle; }

  .doc-title { text-align:center; font-size:17px; font-weight:bold; text-decoration:underline; margin:6px 0 16px 0; }
  .meta-line { font-size:14px; margin:2px 0; }
  .address-block { margin:10px 0 14px 0; font-size:14px; line-height:1.5; }
  .salutation { font-size:14px; margin:14px 0 6px 0; }
  .intro-para, .body-para { font-size:14px; line-height:1.55; text-align:justify; margin:0 0 16px 0; }
  .fillin { text-decoration:underline; }

  table.details { width:100%; border-collapse:collapse; font-size:13.5px; }
  table.details td { border:1px solid #000; padding:5px 8px; vertical-align:top; }
  table.details td.label { width:40%; }
  table.details td.value { width:60%; }
  table.details td.value.bold { font-weight:bold; }
  .exception-row td { height:120px; }
  .exc-title, .exc-note { font-weight:bold; }

  .note-box { border:1px solid #000; padding:12px 14px; margin:10px 0 20px 0; font-size:14px; }
  .note-box p { margin:0 0 10px 0; font-weight:bold; }
  .note-box ol { margin:0; padding-left:22px; }
  .note-box ol li { margin-bottom:4px; }

  .thanking, .signoff { font-size:14px; font-weight:bold; margin-top:8px; }
  .signoff-note { font-size:13px; font-style:italic; margin-top:2px; }
  .pagebreak { page-break-after: always; }
</style>
</head>
<body>

{{-- ============ PAGE 1 ============ --}}
<div class="pagebreak">

  <table class="hdr">
    <tr>
      <td style="width:50%; text-align:left;">
        @if (!empty($lender_details['logo']))
          <img src="{{ url($lender_details['logo']) }}" alt="Lender" style="height:{{ $lender_details['logo_height'] }}px;">
        @endif
      </td>
      <td style="width:50%; text-align:right;">
        <img src="{{ url('Assets/pdf-image/finayo-logo-pdf.png') }}" alt="Finayo" style="height:50px;">
      </td>
    </tr>
  </table>

  <div class="doc-title">DELIVERY ORDER</div>

  <div class="meta-line">Application No: &nbsp;-&nbsp;&nbsp; {{ $loan->borrower_id }}</div>
  <div class="meta-line">Date: {{ $doDate->format('jS F Y') }}</div>

  <div class="address-block">
    To Dealer Name: {{ $do->dealer_name ?: ($dealer->name ?? '') }}<br>
    Address: {{ $dealer_address ?? '' }}
  </div>

  <div class="salutation">Dear Sir/s,</div>

  <div class="intro-para">
    We hereby request you to deliver as per the vehicle(s) details provided below under intimation to us to
    Mrs/Mr. <strong>(Customer Name)</strong> <span class="fillin">{{ $do->borrower_name }}</span> based on his request for finance with use vide his
    Loan application number <span class="fillin">{{ $loan->borrower_id }}</span> Dated <span class="fillin">{{ $doDate->format('jS-F-Y') }}</span> With reference to the above we are
    pleased to inform that we will settle /pay the amount as mentioned below towards the purchase of the
    aforementioned vehicle by the customer by one of the following:
  </div>

  <table class="details">
    <tr><td class="label">No. of Vehicle(s)</td><td class="value">1</td></tr>
    <tr><td class="label">Loan Product Type</td><td class="value">{{ $product->product_type ?? '' }}</td></tr>
    <tr><td class="label">Sanction Date</td><td class="value">{{ $doDate->format('d-m-Y') }}</td></tr>
    <tr><td class="label">Lender Name/Hypothecation in Favor</td><td class="value bold">{{ $legalName }}</td></tr>
    <tr><td class="label">LSP Name</td><td class="value bold">{{ $lsp }}</td></tr>
    <tr><td class="label">Name of Borrower</td><td class="value">{{ $do->borrower_name }}</td></tr>
    <tr><td class="label">Dealer Name</td><td class="value">{{ $do->dealer_name ?: ($dealer->name ?? '') }}</td></tr>
    <tr><td class="label">Manufacturer Name</td><td class="value">{{ $product->manufacture_name ?? '' }}</td></tr>
    <tr><td class="label">Model Name</td><td class="value">{{ $modelName }}</td></tr>
    <tr><td class="label">Vehicle Ex Showroom Cost</td><td class="value">{{ $money($do->ex_showroom_cost) }}</td></tr>
    <tr><td class="label">Loan Amount</td><td class="value">{{ $money($do->loan_amount) }}</td></tr>
    <tr><td class="label">Down Payment</td><td class="value">{{ $money($do->down_payment) }}</td></tr>
    <tr><td class="label">ROI ({{ ucfirst($do->roi_type) }})</td><td class="value">{{ rtrim(rtrim(number_format((float) $do->roi, 2, '.', ''), '0'), '.') }}</td></tr>
    <tr><td class="label">Tenure(in Month)</td><td class="value">{{ $do->tenure }}</td></tr>
    <tr><td class="label">EMI (per Month)</td><td class="value">{{ $money($do->emi) }}</td></tr>
    <tr><td class="label">File Charge</td><td class="value">{{ $money($do->file_charge) }} (including GST)</td></tr>
    <tr><td class="label">Disbursement Amount</td><td class="value">{{ $money($do->disbursement_amount) }}</td></tr>
    <tr class="exception-row">
      <td colspan="2">
        <div class="exc-title">Exception Remarks:</div>
        <div class="exc-note">Note: Kindly release the vehicle only after complete loan documentation (IT WILL BE VALID ONLY FOR 30 DAYS)</div>
      </td>
    </tr>
  </table>

</div>

{{-- ============ PAGE 2 ============ --}}
<div>

  <table class="hdr">
    <tr>
      <td style="width:50%; text-align:left;">
        @if (!empty($lender_details['logo']))
          <img src="{{ url($lender_details['logo']) }}" alt="Lender" style="height:{{ $lender_details['logo_height'] }}px;">
        @endif
      </td>
      <td style="width:50%; text-align:right;">
        <img src="{{ url('Assets/pdf-image/finayo-logo-pdf.png') }}" alt="Finayo" style="height:50px;">
      </td>
    </tr>
  </table>

  <div class="note-box">
    <p>NOTE-2 HYPOTHECATION TO BE FAVOUR OF {{ $legalName }}</p>
    <p>NOTE-3 ZIP NACH IS MANDATORY AT THE TIME OF DISBURSEMENT</p>
    <ol>
      <li>Certified true copy of the form 21/Sale letter(RTO KIT)</li>
      <li>Delivery Challan duly acknowledged by the customer to our address</li>
      <li>Vehicle Photograph as per Sanction Letter</li>
      <li>Any other pending documents required by the lender.</li>
    </ol>
  </div>

  <div class="body-para">
    In accordance with the motor vehicles act and the rules framed there under dealers are required to
    register the vehicle before delivery of the same to buyer. Please therefore be aware that you must
    ensure the permanent registration of cars, multi utility vehicles, jeeps and fully built commercial
    vehicles sold by you before effecting delivery. In case you choose to effect delivery without / before
    registration, the risk, responsibility and consequences thereof will reset with you and you will
    indemnify us against any liability arising in this regard.
  </div>

  <div class="body-para">
    In case any change in the vehicle model/Asset needs to be made, then a fresh delivery order needs
    to be obtained for the new vehicle model/asset and this delivery order become invalid.
    The above said amount is paid to you on the explicit understanding that any sum payable/ refundable
    by you under the above transaction, for any reason whatsoever should be paid/ refunded by you
    only to us &amp; not to the customer mentioned above (or) anyone else,
  </div>

  <div class="body-para">
    Delivery order is valid for 30 days from the date of this delivery letter &amp; delivery order shall
    automatically stand cancelled / terminated on the expiry of 30 days unless extended/ revalidated by
    <strong>{{ $legalName }}</strong> officer in writing. In the event the delivery of the vehicle
    happens after the expiry period, such delivery of the vehicle will be at your cost and consequences.
  </div>

  <div class="body-para" style="margin-bottom:4px;">
    Showroom will be responsible to provide RC to <strong>{{ $legalName }}</strong><br>
    Note - Payment will Release After received <strong>Vehicle Tax Deposit Receipt in Concern RTO.</strong> And
    other PDD Doc
  </div>

  <div class="thanking">Thanking You</div>
  <div class="signoff">For {{ $legalName }}</div>
  <div class="signoff-note">This is a digitally generated letter and does not require a signature</div>

</div>

</body>
</html>