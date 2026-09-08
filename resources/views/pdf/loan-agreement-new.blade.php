<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Agreement Proforma</title>
    <style>
        body {
            /* background: rgb(226, 226, 226);  */
            margin: 0;
            box-shadow: 0;
            padding: 0;
            font-size: 14px;
            line-height: 1.2;
            font-family: 'Times New Roman';
            text-align: justify;
        }

        page {
            background: white;
            display: block;
            margin: 0 auto;
            margin-bottom: 0.3cm;
        }

        page[size="A4"] {
            width: 18.5cm;
            /* min-height: 29.7cm; */
        }

        page[size="A4"][layout="portrait"] {
            width: 25.7cm;
            /* height: 29.7cm;  */
        }

        @media print {

            body,
            page {
                margin: 0;
                box-shadow: 0;
                font-size: 14px;
                line-height: 1;
            }

            page[size="A4"][layout="portrait"] {
                width: 25.7cm;
                /* height: 29.7cm;  */
            }
        }

        .page-break {
            page-break-before: always;
        }

        .footer {
            width: 100%;
            text-align: center;
            position: fixed;
            bottom: 0px;
        }

        #repayment-schedule {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }

        /* #repayment-schedule th, td {
            border: 1px solid #ddd;
        }
        #repayment-schedule th, td {
            padding: 8px 12px;
            text-align: center;
        } */

        .running-header {
    font-size: 7.5pt;
    color: #979797;
    text-align: right;
    margin: 0 0 9px 0;
  }
   h1.doc-title { text-align: center; font-size: 14pt; font-weight: bold; margin: 4px 0 6px 0; }
  p.doc-subtitle { text-align: center; font-size: 10pt; font-weight: bold; font-style: italic; margin: 0 0 11px 0; }
  p.act-note { font-size: 8.5pt; color: #979797; font-style: italic; font-weight: 600; margin: 0 0 12px 0; text-align: justify; }

  p.body-text { margin: 0 0 8px 0; text-align: justify; }
  p.label-line { margin: 0 0 8px 0; text-align: left; }
  p.plain-line { margin: 0 0 8px 0; }

  h2.section-heading { font-weight: bold; color: rgb(0, 99, 180); margin: 9px 0 8px 0; font-size: 10pt; }

  table.schedule { width: 100%; border-collapse: collapse; font-size: 9.5pt; }
  table.schedule td { border: 1px solid #000000; padding: 6px 10px; }
  table.schedule td.label { width: 35%; background: #f2f2f2; }

  .title-rule {
    border: none;
    border-top: 2.5px solid rgb(0, 50, 90);
    margin: 0 0 14px 0;
  }

  p.date-line { margin: 0 0 14px 0; font-size: 10.3pt; }
  .blank { border-bottom: 1px solid #666; }

  .callout {
    background: #eef2f8;
    border-left: 2px solid rgb(0, 50, 90);
    padding: 9px 12px;
    margin: 0 0 12px 0;
  }
  .callout p { margin: 0 0 3px 0; }
  .callout p:last-child { margin-bottom: 0; }
  .callout .party-gap { margin-top: 7px; }

  p.body-text { margin: 0 0 9px 0; text-align: justify; }

  ol.data-list {
    margin: 2px 0 10px 26px;
    padding: 0;
  }
  ol.data-list li { margin-bottom: 2px; text-align: justify; }

  h2.section-heading {
    font-size: 10.8pt;
    font-weight: 700;
    margin: 10px 0 6px 0;
  }

  ol.lettered-list {
    list-style: none;
    margin: 2px 0 4px 0;
    padding: 0;
  }
  ol.lettered-list li {
    display: flex;
    gap: 6px;
    margin-bottom: 5px;
    text-align: justify;
  }
  ol.lettered-list li .tag { min-width: 22px; flex-shrink: 0; }

  .declaration-box {
    border: 1px solid #c3c9d4;
    border-radius: 4px;
    padding: 10px 14px;
    margin: 12px 0 24px 0;
  }
  .declaration-box h2.section-heading { margin: 0 0 6px 0; letter-spacing: 0.3px; }
  .declaration-box p { margin: 0; text-align: justify; }

  .sig-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    column-gap: 24px;
    row-gap: 22px;
    margin-top: 10px;
  }
  .sig-item { display: flex; align-items: baseline; gap: 6px; }
  .sig-item .sig-label { white-space: nowrap; font-size: 10.3pt; }
  .sig-item .sig-fill { flex: 1 1 auto; min-width: 30px; border-bottom: 1px dotted #8a8a8a; }
    </style>

</head>

<body>
    <page size="A4" style="padding: 10px;">

        <div class="header"
            style="
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            padding: 5px;
            border-bottom: 1px solid #000;
            text-align: center;
            ">

            <table width="100%">
                <tr>
                    <td style="width: 50%; text-align: left;">
                        @if (isset($lender_details) && $lender_details['company_short'] === 'AKME')
                            <img src="{{ url('Assets/pdf-image/akme-logo-pdf.jpg') }}" alt="Left Logo"
                                style="height: 50px;">
                        @elseif (isset($lender_details) && $lender_details['company_short'] === 'F MEC')
                            <img src="{{ url('Assets/pdf-image/fmec-international.png') }}" alt="Left Logo"
                                style="height: 70px;">
                        @endif
                    </td>
                    <td style="width: 50%; text-align: right;">
                        <img src="{{ url('Assets/pdf-image/finayo-logo-pdf.png') }}" alt="Right Logo"
                            style="height: 50px;">
                    </td>
                </tr>
            </table>
        </div>

        <p><strong>&nbsp;</strong></p>
        <p><strong>&nbsp;</strong></p>
        <p><strong>{{ $lender_details['company_name'] ?? '' }}</strong></p>
        <p><strong>Address: </strong>{{ $lender_details['address'] ?? '' }}</p>
        <p><strong>To, </strong></p>
        <p><strong>Mr./Mrs.&nbsp;&nbsp;
                {{ isset($borrower_details['basic']['name']) ? $borrower_details['basic']['name'] : '' }}</strong></p>
        <p><strong>S/o, W/o, D/o:
                {{ isset($borrower_details['basic']['father_name']) ? $borrower_details['basic']['father_name'] : '' }}</strong></strong>
        </p>
        <p><strong>Address:</strong>
            {{ isset($borrower_details['address']['current_address']) ? $borrower_details['address']['current_address'] : '' }}
        </p>

        <p>Dear Sir/Madam, at your request, we are pleased to Sanction a Loan on the agreed terms and conditions as per
            this letter.</p>
        <table style="width: 517.4pt;" border="1" cellspacing="0" cellpadding="0">
            <tbody>
                <tr>
                    <td style="width: 78.0pt;" valign="top">
                        <p>&nbsp; 1</p>
                    </td>
                    <td style="width: 4.0cm;" valign="top">
                        <p>Application No</p>
                    </td>
                    <td style="width: 326.0pt;" valign="top">
                        <p>&nbsp; </p>
                    </td>
                </tr>
                <tr>
                    <td style="width: 78.0pt;" valign="top">
                        <p>&nbsp; 2</p>
                    </td>
                    <td style="width: 4.0cm;" valign="top">
                        <p>Date of Application</p>
                    </td>
                    <td style="width: 326.0pt;" valign="top">
                        <p>&nbsp;</p>
                    </td>
                </tr>
                <tr>
                    <td style="width: 78.0pt;" valign="top">
                        <p>&nbsp; 3</p>
                    </td>
                    <td style="width: 4.0cm;" valign="top">
                        <p>Loan Amount</p>
                    </td>
                    <td style="width: 326.0pt;" valign="top">
                        <p> Rs. {{ $loan_details['loan_amount'] ?? '_______________' }}/-</p>
                    </td>
                </tr>
                <tr>
                    <td style="width: 78.0pt;" valign="top">
                        <p>&nbsp; 4</p>
                    </td>
                    <td style="width: 4.0cm;" valign="top">
                        <p>Tenure (in months)</p>
                    </td>
                    <td style="width: 326.0pt;" valign="top">
                        <p>&nbsp; {{ $loan_details['loan_term'] ?? '' }}</p>
                    </td>
                </tr>
                <tr>
                    <td style="width: 78.0pt;" valign="top">
                        <p>&nbsp; 5</p>
                    </td>
                    <td style="width: 4.0cm;" valign="top">
                        <p>Repayment</p>
                    </td>
                    <td style="width: 326.0pt;" valign="top">
                        <p>&nbsp; Monthly EMIs</p>
                    </td>
                </tr>
                <tr>
                    <td style="width: 78.0pt;" valign="top">
                        <p>&nbsp; 6</p>
                    </td>
                    <td style="width: 4.0cm;" valign="top">
                        <p>EMI Amount</p>
                    </td>
                    <td style="width: 326.0pt;" valign="top">
                        <p> Rs. {{ $loan_details['emi'] ?? '________' }}/-</p>
                    </td>
                </tr>
                <tr>
                    <td style="width: 78.0pt;" valign="top">
                        <p>&nbsp; 7</p>
                    </td>
                    <td style="width: 4.0cm;" valign="top">
                        <p>Rate of Interest</p>
                    </td>
                    <td style="width: 326.0pt;" valign="top">
                        <p>&nbsp; {{ $loan_details['annual_roi'] ?? '' }}%</p>
                    </td>
                </tr>
                <tr>
                    <td style="width: 78.0pt;" valign="top">
                        <p>&nbsp; 8</p>
                    </td>
                    <td style="width: 4.0cm;" valign="top">
                        <p>Type of Interest</p>
                    </td>
                    <td style="width: 326.0pt;" valign="top">
                        <p>&nbsp; {{ (isset($loan_details) && $loan_details['interest_type'] === 'flat') ? 'Fixed (Monthly Reducing)' : 'Monthly Reducing' }}</p>
                    </td>
                </tr>
                <tr>
                    <td style="width: 78.0pt;" valign="top">
                        <p>&nbsp; 9</p>
                    </td>
                    <td style="width: 4.0cm;" valign="top">
                        <p>Purpose of Loan</p>
                    </td>
                    <td style="width: 326.0pt;" valign="top">
                        <p>&nbsp; <strong>Purchase of</strong>
                            <strong>{{ $loan_details['purpose_of_loan'] ?? '' }}</strong>
                        </p>
                    </td>
                </tr>
                <tr>
                    <td style="width: 78.0pt;" valign="top">
                        <p>&nbsp; 10</p>
                    </td>
                    <td style="width: 4.0cm;" valign="top">
                        <p>Processing Fee</p>
                    </td>
                    <td style="width: 326.0pt;" valign="top">
                        <p>&nbsp; Rs.
                            {{ !empty($loan_details['processing_fee']) ? $loan_details['processing_fee'] : '_______' }}/-
                            (incl of GST)</p>
                    </td>
                </tr>
                <tr>
                    <td style="width: 78.0pt;" valign="top">
                        <p>&nbsp; 11</p>
                    </td>
                    <td style="width: 4.0cm;" valign="top">
                        <p>Documentation / Application Charges</p>
                    </td>
                    <td style="width: 326.0pt;" valign="top">
                        <p>&nbsp; Rs.
                            {{ !empty($loan_details['application_charges']) ? $loan_details['application_charges'] : '_______' }}/-
                        </p>
                    </td>
                </tr>
                <tr>
                    <td style="width: 78.0pt;" valign="top">
                        <p>&nbsp; 12</p>
                    </td>
                    <td style="width: 4.0cm;" valign="top">
                        <p>Additional Delay Interest</p>
                    </td>
                    <td style="width: 326.0pt;" valign="top">
                        <p>&nbsp;
                            {{ !empty($loan_details['add_delay_interest']) ? $loan_details['add_delay_interest'] : '_______' }}%
                            per month</p>
                    </td>
                </tr>
            </tbody>
        </table>
        <div class="footer">
            <table style="width:100%;">

                @php
                    $skipCo = $borrower_details['basic']['skip_co_applicant'] ?? true;
                    $skipGuarantor = $borrower_details['basic']['skip_guarantor'] ?? true;
                @endphp
                <tr>
                    <td style="text-align: center;vertical-align: top;">
                        ______________________<br>
                        {{-- <b>SB-6</b><br> --}}
                        (Signature)<br>
                        <b>For, LENDER</b><br>
                        Authorized Signatory
                    </td>
                    <td style="text-align:center;vertical-align:top;">
                        ______________________<br>
                        (Signature)<br>
                        <b>For, BORROWER</b>
                    </td>

                    @if (!$skipCo)
                        <td style="text-align:center;vertical-align:top;">
                            ______________________<br>
                            (Signature)<br>
                            <b>For, CO-BORROWER</b>
                        </td>
                    @endif

                    @if (!$skipGuarantor)
                        <td style="text-align:center;vertical-align:top;">
                            ______________________<br>
                            (Signature)<br>
                            <b>For, GUARANTOR</b>
                        </td>
                    @endif
                </tr>
            </table>
        </div>
    </page>
    <div class="page-break"></div>
    <page size="A4" style="padding: 10px;">
        <table style="width: 517.4pt;" border="1" cellspacing="0" cellpadding="0">
            <tbody>
                <tr>
                    <td style="width: 78.0pt;" valign="top">
                        <p>&nbsp; 13</p>
                    </td>
                    <td style="width: 4.0cm;" valign="top">
                        <p>Bounce Charges</p>
                    </td>
                    <td style="width: 326.0pt;" valign="top">
                        <p>&nbsp; Rs.
                            {{ !empty($loan_details['bouncing_charges']) ? $loan_details['bouncing_charges'] : '_______' }}/-
                            + GST</p>
                    </td>
                </tr>
                <tr>
                    <td style="width: 78.0pt;" valign="top">
                        <p>&nbsp; 14</p>
                    </td>
                    <td style="width: 4.0cm;" valign="top">
                        <p>Foreclosure / Prepayment Charges</p>
                    </td>
                    <td style="width: 326.0pt;" valign="top">
                        <p>&nbsp;
                            {{ !empty($loan_details['pre_closure_charge']) ? $loan_details['pre_closure_charge'] : '_______' }}%
                            + GST on Principal Outstanding</p>
                    </td>
                </tr>
                <tr>
                    <td style="width: 78.0pt;" valign="top">
                        <p>&nbsp; 15</p>
                    </td>
                    <td style="width: 4.0cm;" valign="top">
                        <p>Other Charges</p>
                    </td>
                    <td style="width: 326.0pt;" valign="top">
                        <p>&nbsp; As stipulated in Key Fact Statement</p>
                    </td>
                </tr>
                <tr>
                    <td style="width: 78.0pt;" valign="top">
                        <p>&nbsp; 16</p>
                    </td>
                    <td style="width: 4.0cm;" valign="top">
                        <p>Co &ndash; Borrower</p>
                    </td>
                    <td style="width: 326.0pt;" valign="top">
                        <p>&nbsp; {{ $co_applicant_details['basic']['name'] ?? '' }}</p>
                    </td>
                </tr>
                <tr style="height: 48.7pt;">
                    <td style="width: 78.0pt;" valign="top">
                        <p>&nbsp; 17</p>
                    </td>
                    <td style="width: 4.0cm;" valign="top">
                        <p>Hypothecation</p>
                    </td>
                    <td style="width: 326.0pt;" valign="top">
                        <p>&nbsp; Hypothecation of the Asset/Vehicle must be done in favor of
                            <strong>{{ !empty($lender_details['company_name']) ? $lender_details['company_name'] : '_____________________________________' }}</strong>and
                            should be shared before disbursement.
                        </p>
                        <p>&nbsp;</p>
                    </td>
                </tr>
                <tr>
                    <td style="width: 78.0pt;" valign="top">
                        <p>&nbsp; 18</p>
                    </td>
                    <td style="width: 4.0cm;" valign="top">
                        <p>NACH/e-NACH</p>
                    </td>
                    <td style="width: 326.0pt;" valign="top">
                        <p>&nbsp; NACH/e-NACH to be provided by Borrower/Co-Borrower, in favor of
                            <strong>{{ !empty($lender_details['company_name']) ? $lender_details['company_name'] : '_____________________________________' }}</strong>,
                            as required by
                            <strong>{{ !empty($lender_details['company_name']) ? $lender_details['company_name'] : '_____________________________________' }}</strong>
                        </p>
                    </td>
                </tr>
            </tbody>
        </table>

        <p>Loan Agreement Acceptance Letter</p>
        <p>The detailed terms and conditions of the agreement have been explained to you and accepted by you, as
            mentioned in the above-referred Loan Agreement. We would also like to inform you that the Fair Practices
            Code has been adopted by the Company.<br><br>You are requested to sign the duplicate copy of this letter and
            return it to us.</p>
        <p>For any further assistance, please contact our partner at the Head Office of LSP/DLA:</p>
        <table border="1" cellspacing="0" cellpadding="0">
            <tbody>
                <tr>
                    <td style="width: 216.0pt;" valign="top">
                        <p>LSP/DLA Name</p>
                    </td>
                    <td style="width: 216.0pt;" valign="top">
                        <p>FINAYO (under the trademark of Kilpi Technology Services Pvt. Ltd.)</p>
                    </td>
                </tr>
                <tr>
                    <td style="width: 216.0pt;" valign="top">
                        <p>Address</p>
                    </td>
                    <td style="width: 216.0pt;" valign="top">
                        <!-- <p>5th Floor, Iconic Corenthum Tower, Sector-62, Noida &ndash; 201301</p> -->
                        <p>Finayo, Ground Floor, H-57, H Block, Workspace by Innova, Sector 63, Noida, Uttar Pradesh - 201309</p>
                    </td>
                </tr>
            </tbody>
        </table>
        <p>If you require any further details, please contact the Head Office of the Lender:</p>
        <table border="1" cellspacing="0" cellpadding="0">
            <tbody>
                <tr>
                    <td style="width: 216.0pt;" valign="top">
                        <p>Lender Name</p>
                    </td>
                    <td style="width: 216.0pt;" valign="top">
                        <p>{{ $lender_details['company_name'] ?? '' }} </p>
                    </td>
                </tr>
                <tr>
                    <td style="width: 216.0pt;" valign="top">
                        <p>Address</p>
                    </td>
                    <td style="width: 216.0pt;" valign="top">
                        <p>{{ $lender_details['address'] ?? '' }}</p>
                    </td>
                </tr>
            </tbody>
        </table>
        <p>
            We value your relationship with us and assure you of our best services.</p>
        <p>Yours faithfully,
            <br>For
            {{ !empty($lender_details['company_name']) ? $lender_details['company_name'] : '_____________________________________' }}
            <br>Authorized Signatory
        </p>

    </page>
    <page size="A4" style="padding: 5px;">
        <div class="page-break"></div>
        <p style="text-align: center;">
            <strong style="margin-bottom: 5px; text-align: center;">KEY FACT STATEMENT</strong>
        </p>
        <p><b>Date: </b> <b style="float: right;">
                Customer ID: {{ $loan_details['borrower_id'] ?? '' }}
                &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
            </b></p>
        <p><b>Applicant&rsquo;s Name: </b> {{ $borrower_details['basic']['name'] ?? '' }}
            <b style="float: right;">
                Loan ID:
                &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
                &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
                &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
            </b>
        </p>


        <table style="width: 100%; border-collapse: collapse; margin-bottom: 20px;">
            <tr>
                <th style="border: 1px solid black; padding: 8px; text-align: left;"><strong>Sr. No.</strong></th>
                <th style="border: 1px solid black; padding: 8px; text-align: left;"><strong>Parameter</strong></th>
                <th style="border: 1px solid black; padding: 8px; text-align: left;"><strong>Details</strong></th>
            </tr>
            <tr>
                <td style="border: 1px solid black; padding: 8px; text-align: left;">i.</td>
                <td style="border: 1px solid black; padding: 8px; text-align: left;">Loan Amount (in Rupees)</td>
                <td style="border: 1px solid black; padding: 8px; text-align: left;">Rs.
                    {{ !empty($loan_details['loan_amount']) ? $loan_details['loan_amount'] : '.................' }}/-
                    @if (isset($loan_details['iot_charges']) && $loan_details['iot_charges'] > 0)
                        (Inclusive of Rs. {{ !empty($loan_details['iot_charges']) ? $loan_details['iot_charges'] : '.................' }}/-, as IOT charges.)
                    @endif
                </td>
                <!--  of the Loan Tenor -->
            </tr>
            <tr>
                <td style="border: 1px solid black; padding: 8px; text-align: left;">ii.</td>
                <td style="border: 1px solid black; padding: 8px; text-align: left;">Total interest payable during the
                    entire Tenor of the Loan (in Rupees)</td>
                <td style="border: 1px solid black; padding: 8px; text-align: left;">Rs.
                    {{ !empty($loan_details['total_interest']) ? $loan_details['total_interest'] : '.......' }}/-</td>
            </tr>
            <tr>
                <td style="border: 1px solid black; padding: 8px; text-align: left;">iii.</td>
                <td style="border: 1px solid black; padding: 8px; text-align: left;">Other Upfront Charges</td>
                <td style="border: 1px solid black; padding: 8px; text-align: left;"></td>
            </tr>
            <tr>
                <td style="border: 1px solid black; padding: 8px; text-align: left;"></td>
                <td style="border: 1px solid black; padding: 8px; text-align: left;">(a) Non-Refundable Processing Fee
                    (in Rupees) inclusive GST</td>
                <td style="border: 1px solid black; padding: 8px; text-align: left;">Rs.
                    {{ !empty($loan_details['processing_fee']) ? $loan_details['processing_fee'] : '_______' }}/-</td>
            </tr>
            <tr>
                <td style="border: 1px solid black; padding: 8px; text-align: left;"></td>
                <td style="border: 1px solid black; padding: 8px; text-align: left;">(b) IOT Charges</td>
                <td style="border: 1px solid black; padding: 8px; text-align: left;">
                    @if (isset($loan_details['iot_charges']) && $loan_details['iot_charges'] > 0)
                        Up to Rs
                        {{ !empty($loan_details['iot_charges']) ? $loan_details['iot_charges'] : '_______' }}/- for the IOT
                        charges of Loan Tenor (This is collected on behalf of the IOT Vendor as a pass-through amount. Final
                        amount shall be determined and informed to the Borrower at the time of disbursement)
                    @endif
                </td>
            </tr>
            <tr>
                <td style="border: 1px solid black; padding: 8px; text-align: left;"></td>
                <td style="border: 1px solid black; padding: 8px; text-align: left;">(c) Vehicle Insurance Premium</td>
                <td style="border: 1px solid black; padding: 8px; text-align: left;">Up to Rs
                    {{ !empty($loan_details['insurance_1st_year']) ? $loan_details['insurance_1st_year'] : '.............' }}/-
                    for the 1st year of Loan Tenor (This is collected on behalf of the insurer as a pass-through amount.
                    Final amount shall be determined and informed to the Borrower at the time of disbursement)</td>
            </tr>
            <tr>
                <td style="border: 1px solid black; padding: 8px; text-align: left;"></td>
                <td style="border: 1px solid black; padding: 8px; text-align: left;">(d) Life Insurance</td>
                <td style="border: 1px solid black; padding: 8px; text-align: left;">Rs
                    {{ !empty($loan_details['life_insurance_amount']) ? $loan_details['life_insurance_amount'] : '............' }}/-
                    (This is collected on behalf of the insurer as a pass-through amount. Final amount shall be
                    determined and informed to the Borrower at the time of disbursement)</td>
            </tr>
            <tr>
                <td style="border: 1px solid black; padding: 8px; text-align: left;">iv.</td>
                <td style="border: 1px solid black; padding: 8px; text-align: left;">Net Disbursement Amount (in
                    Rupees), to be disbursed to manufacturer / dealer <i>(equal to [(i-iii)] -- insurance charges
                        forming part of Loan amount, if any)</i></td>
                <td style="border: 1px solid black; padding: 8px; text-align: left;">Rs
                    {{ !empty($loan_details['net_disbursal_amount']) ? $loan_details['net_disbursal_amount'] : '...........' }}/-
                </td>
            </tr>
            <tr>
                <td style="border: 1px solid black; padding: 8px; text-align: left;">v.</td>
                <td style="border: 1px solid black; padding: 8px; text-align: left;">Total amount to be paid by the
                    borrower (in Rupees) <i>(i+ii+iii)</i></td>
                <td style="border: 1px solid black; padding: 8px; text-align: left;">Rs.
                    {{ !empty($loan_details['total_payment_by_borrower']) ? $loan_details['total_payment_by_borrower'] : '.........' }}/-
                </td>
            </tr>
            <tr>
                <td style="border: 1px solid black; padding: 8px; text-align: left;">vi.</td>
                <td style="border: 1px solid black; padding: 8px; text-align: left;">Interest Rate (Annualized Reducing
                    Rate)</td>
                <td style="border: 1px solid black; padding: 8px; text-align: left;">Flat Rate:
                    {{ !empty($loan_details['flat_rate']) ? $loan_details['flat_rate'] : '...' }}%<br>Reducing Balance Rate:
                    {{ !empty($loan_details['reducing_roi']) ? $loan_details['reducing_roi'] : '.......' }}%</td>
            </tr>
            <tr>
                <td style="border: 1px solid black; padding: 8px; text-align: left;">vii.</td>
                <td style="border: 1px solid black; padding: 8px; text-align: left;">Annual Percentage Rate --
                    Effective annualized interest rate</td>
                <td style="border: 1px solid black; padding: 8px; text-align: left;">Flat Rate:
                    {{ !empty($loan_details['flat_rate']) ? $loan_details['flat_rate'] : '...' }}%<br>Reducing
                    Balance Rate:
                    {{ !empty($loan_details['reducing_roi']) ? $loan_details['reducing_roi'] : '.......' }}%
                </td>
            </tr>
            <tr>
                <td style="border: 1px solid black; padding: 8px; text-align: left;">viii.</td>
                <td style="border: 1px solid black; padding: 8px; text-align: left;">The tenor of the loan</td>
                <td style="border: 1px solid black; padding: 8px; text-align: left;">
                    {{ !empty($loan_details['loan_term']) ? $loan_details['loan_term'] : '.....' }} month(s)</td>
            </tr>
            <tr>
                <td style="border: 1px solid black; padding: 8px; text-align: left;">ix.</td>
                <td style="border: 1px solid black; padding: 8px; text-align: left;">Repayment frequency</td>
                <td style="border: 1px solid black; padding: 8px; text-align: left;">Monthly</td>
            </tr>
            <tr>
                <td style="border: 1px solid black; padding: 8px; text-align: left;">x.</td>
                <td style="border: 1px solid black; padding: 8px; text-align: left;">Number of instalments of repayment
                </td>
                <td style="border: 1px solid black; padding: 8px; text-align: left;">
                    {{ !empty($loan_details['loan_term']) ? $loan_details['loan_term'] : '.....' }}</td>
            </tr>
        </table>
    </page>
    <div class="page-break"></div>
    <page>
        <table style="width: 100%; border-collapse: collapse; margin-bottom: 20px;">
            <tr>
                <td style="border: 1px solid black; padding: 8px; text-align: left;">xi.</td>
                <td style="border: 1px solid black; padding: 8px; text-align: left;">Amount of each instalment of
                    repayment (in Rupees)</td>
                <td style="border: 1px solid black; padding: 8px; text-align: left;">Rs.
                    {{ !empty($loan_details['emi']) ? $loan_details['emi'] : '.........' }}/-</td>
            </tr>
            <tr>
                <td style="border: 1px solid black; padding: 8px; text-align: left;" colspan="3"><strong>Details
                        about Contingent Charges</strong></td>
            </tr>
            <tr>
                <td style="border: 1px solid black; padding: 8px; text-align: left;">xii.</td>
                <td style="border: 1px solid black; padding: 8px; text-align: left;">Additional interest on late
                    payment of EMI</td>
                <td style="border: 1px solid black; padding: 8px; text-align: left;">36% per annum on the amount
                    outstanding for the period of delay</td>
            </tr>
            <tr>
                <td style="border: 1px solid black; padding: 8px; text-align: left;">Xiii</td>
                <td style="border: 1px solid black; padding: 8px; text-align: left;">Duplicate Statement of Accounts
                </td>
                <td style="border: 1px solid black; padding: 8px; text-align: left;">INR 500/- + GST</td>
            </tr>
            <tr>
                <td style="border: 1px solid black; padding: 8px; text-align: left;">Xiv</td>
                <td style="border: 1px solid black; padding: 8px; text-align: left;">Duplicate NOC</td>
                <td style="border: 1px solid black; padding: 8px; text-align: left;">INR 500/- + GST</td>
            </tr>
            <tr>
                <td style="border: 1px solid black; padding: 8px; text-align: left;">xiii.</td>
                <td style="border: 1px solid black; padding: 8px; text-align: left;">Bounce Charges (in Rupees)</td>
                <td style="border: 1px solid black; padding: 8px; text-align: left;">INR {{ !empty($loan_details['bouncing_charges']) ? $loan_details['bouncing_charges'] : '_______' }}/- + GST per bounce</td>
            </tr>
            <tr>
                <td style="border: 1px solid black; padding: 8px; text-align: left;">xiv.</td>
                <td style="border: 1px solid black; padding: 8px; text-align: left;">Legal/Incidental charges in case
                    of default</td>
                <td style="border: 1px solid black; padding: 8px; text-align: left;">At actual</td>
            </tr>
            <tr>
                <td style="border: 1px solid black; padding: 8px; text-align: left;">xv.</td>
                <td style="border: 1px solid black; padding: 8px; text-align: left;">Loan cancellation charges</td>
                <td style="border: 1px solid black; padding: 8px; text-align: left;">NIL</td>
            </tr>
            <tr>
                <td style="border: 1px solid black; padding: 8px; text-align: left;" colspan="3"><strong>Other
                        Disclosures</strong></td>
            </tr>
            <tr>
                <td style="border: 1px solid black; padding: 8px; text-align: left;">xvi.</td>
                <td style="border: 1px solid black; padding: 8px; text-align: left;">Prepayment penalty</td>
                <td style="border: 1px solid black; padding: 8px; text-align: left;">4% + GST</td>
            </tr>
            <tr>
                <td style="border: 1px solid black; padding: 8px; text-align: left;">xvii.</td>
                <td style="border: 1px solid black; padding: 8px; text-align: left;">Cooling-off/Look-up period</td>
                <td style="border: 1px solid black; padding: 8px; text-align: left;">The borrower can exit the loan at
                    any stage within the mandatory cooling period of 3 days or after completion of the cooling period by
                    paying the principal and the proportionate APR without any penalty.</td>
            </tr>
            <tr>
                <td style="border: 1px solid black; padding: 8px; text-align: left;">xviii.</td>
                <td style="border: 1px solid black; padding: 8px; text-align: left;">Details of LSP acting as recovery
                    agent and authorized to approach borrower</td>
                <td style="border: 1px solid black; padding: 8px; text-align: left;">Kilpi technology & Services India
                    Pvt Ltd<br>Address: Ground Floor, LG- 007 - 02, LG Floor, MGF Metropolis Mall, MG Road, Gurugram,
                    Haryana- 122002.,<br>Website: <a href="https://finayo.tech/">https://finayo.tech/</a><br>Mobile
                    Application: FINAYO: EV-Loan App</td>
            </tr>
            <tr>
                <td style="border: 1px solid black; padding: 8px; text-align: left;">xix.</td>
                <td style="border: 1px solid black; padding: 8px; text-align: left;">Name, designation, address, and
                    phone number of nodal grievance redressal officer of LSP</td>
                <td style="border: 1px solid black; padding: 8px; text-align: left;">Ms. Pratibha -- Customer
                    Support Team<br>Kilpi Technology and Services Private Limited,<br>Mail ID:
                    credit.ops@finayo.tech<br>Mobile No. +91-7669107106</td>
            </tr>
            <tr>
                <td style="border: 1px solid black; padding: 8px; text-align: left;">xx.</td>
                <td style="border: 1px solid black; padding: 8px; text-align: left;">Name, designation, address, and
                    phone number of nodal grievance redressal officer designated specifically to deal with
                    FinTech/digital lending related complaints/issues</td>
                <td style="border: 1px solid black; padding: 8px; text-align: left;">Mr. Yogendra Sharma Head of
                    Digital Operations,<br>Mobile No. +91-7669107110<br>Mail ID. yogendra.sharma@finayo.tech</td>
            </tr>
        </table>
    </page>
    <div class="page-break"></div>
    <page>

        <div style="margin-bottom: 50px;">
            <h3 style="padding-bottom: 5px;">DECLARATION:</h3>
            <p>I/We, the undersigned, hereby declare that:</p>
            <ol>
                <li>I/We have read and understood the key terms of the loan, including the loan amount, interest rate,
                    tenure, EMI, and applicable charges.</li>
                <li>I/We agree to repay the loan as per the agreed schedule and acknowledge the consequences of default,
                    including penalties and legal actions.</li>
                <li>I/We confirm that all information provided is true and accurate. Any misrepresentation may lead to
                    legal consequences.</li>
                <li>I/We understand that in case of default, the lender has the right to take necessary recovery
                    actions, including repossession of the financed <strong>
                        {{ !empty($loan_details['purpose_of_loan']) ? $loan_details['purpose_of_loan'] : '________________________________' }}</strong>.
                </li>
                <li><i>(If applicable)</i> The Guarantor acknowledges full responsibility for repayment in case of
                    default by the Borrower and Co-Borrower.</li>
            </ol>
        </div>

        <div style="margin-bottom: 20px;">
            <p>The disbursement of the loan will be subject to the execution of the loan agreement, setting up of the
                repayment facility, and completion of further documentary checks.</p>
            <p>For any queries, you may reach us at <strong>credit.ops@finayo.tech</strong> or
                <strong>+91-7669107106</strong>.
            </p>
            <p>Please find attached the following exhibits for your reference:</p>
            <ul style="padding-left: 40px">
                <li><strong>Exhibit 1:</strong> Acknowledgment of Loan Disbursement</li>
                <li><strong>Exhibit 2:</strong> Recovery Mechanism</li>
                <li style="margin-bottom: 50px;"><strong>Exhibit 3:</strong> Repayment Schedule</li>
            </ul>
        </div>

        <div style="margin-bottom: 50px;">
            <p style="margin-bottom: 50px;"><strong>Best Regards,</strong></p>
            <p><strong>[Your Name]</strong><br>
                Designation<br>
            <p></p>
            <strong>[Lender Name]</strong></p>
        </div>
    </page>
    <div class="page-break"></div>
    <page>
        <!-- Exhibit 1 -->
        <div style="margin-bottom: 50px">
            <h3 style="text-decoration: underline; text-align: center;"><strong>Exhibit 1</strong></h3>
            <p style="text-align: center;">Acknowledgment of Loan Disbursement</p>
            <p>This is to acknowledge the receipt of the loan disbursement of <strong> Rs.
                    {{ !empty($loan_details['net_disbursal_amount']) ? $loan_details['net_disbursal_amount'] : '_________' }}</strong>
                on <strong>
                    {{ !empty($loan_details['disbursement_date']) ? $loan_details['disbursement_date'] : '_________' }}</strong>
                as per the agreed terms under Loan Account No. <strong>
                    <u>{{ !empty($loan_details['borrower_id']) ? $loan_details['borrower_id'] : '_____________________________________' }}</u></strong>.
            </p>

            <h4 style="margin-top: 20px; text-align: center;"><strong>E-Rickshaw Disbursement Details (Account to which
                    Disbursement to be made)</strong></h4>
            <table style="width: 100%; border-collapse: collapse; margin-bottom: 20px;">
                <tr>
                    <td style="border: 1px solid black; padding: 8px;"><strong>Amount</strong></td>
                    <td style="border: 1px solid black; padding: 8px;">Rs.
                        {{ $loan_details['net_disbursal_amount'] ?? '' }}</td>
                    <td style="border: 1px solid black; padding: 8px;"><strong>Name of Bank</strong></td>
                    <td style="border: 1px solid black; padding: 8px;">{{ $loan_details['dealer_bank_name'] ?? '' }}
                    </td>
                </tr>
                <tr>
                    <td style="border: 1px solid black; padding: 8px;"><strong>Account Name</strong></td>
                    <td style="border: 1px solid black; padding: 8px;">
                        {{ $loan_details['dealer_account_name'] ?? '' }}</td>
                    <td style="border: 1px solid black; padding: 8px;"><strong>IFSC Code</strong></td>
                    <td style="border: 1px solid black; padding: 8px;">{{ $loan_details['dealer_ifsc_code'] ?? '' }}
                    </td>
                </tr>
                <tr>
                    <td style="border: 1px solid black; padding: 8px;"><strong>Account No.</strong></td>
                    <td style="border: 1px solid black; padding: 8px;">
                        {{ $loan_details['dealer_account_number'] ?? '' }}</td>
                    <td style="border: 1px solid black; padding: 8px;"></td>
                    <td style="border: 1px solid black; padding: 8px;"></td>
                </tr>
            </table>

            @if (isset($loan_details['iot_charges']) && $loan_details['iot_charges'] > 0)
                <h4><strong>IOT Disbursement Details (Account to which Disbursement to be made)</strong></h4>
                <table style="width: 100%; border-collapse: collapse; margin-bottom: 20px;">
                    <tr>
                        <td style="border: 1px solid black; padding: 8px;"><strong>Amount</strong></td>
                        <td style="border: 1px solid black; padding: 8px;">Rs. {{ $loan_details['iot_charges'] ?? '' }}/-
                        </td>
                        <td style="border: 1px solid black; padding: 8px;"><strong>Name of Bank</strong></td>
                        <!-- <td style="border: 1px solid black; padding: 8px;">HDFC BANK</td> -->
                        <td style="border: 1px solid black; padding: 8px;">
                            &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
                        </td>
                    </tr>
                    <tr>
                        <td style="border: 1px solid black; padding: 8px;"><strong>Account Name</strong></td>
                        <!-- <td style="border: 1px solid black; padding: 8px;">KILPI TECHNOLOGY AND SERVICES PVT LTD</td> -->
                        <td style="border: 1px solid black; padding: 8px;">
                            &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
                        </td>
                        <td style="border: 1px solid black; padding: 8px;"><strong>IFSC Code</strong></td>
                        <!-- <td style="border: 1px solid black; padding: 8px;">HDFC0001202</td> -->
                        <td style="border: 1px solid black; padding: 8px;">
                            &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</td>
                    </tr>
                    <tr>
                        <td style="border: 1px solid black; padding: 8px;"><strong>Account No.</strong></td>
                        <!-- <td style="border: 1px solid black; padding: 8px;">50200065320572</td> -->
                        <td style="border: 1px solid black; padding: 8px;">
                            &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</td>
                        <td style="border: 1px solid black; padding: 8px;"></td>
                        <td style="border: 1px solid black; padding: 8px;"></td>
                    </tr>
                </table>
            @endif
        </div>
    </page>
    <div class="page-break"></div>
    <page>
        <!-- Exhibit 2 -->
        <div style="margin-bottom: 30px;">
            <h3 style="text-decoration: underline; text-align: center;"><strong>Exhibit 2</strong></h3>
            <p style="text-decoration: underline; text-align: center;">Recovery Mechanism</p>
            <p style="text-align: center;">
                {{ !empty($lender_details['company_name']) ? $lender_details['company_name'] : '..............................................................' }}
                ('Lender' or 'lender') follows the following recovery practices:</p>
            <ol type="i" style="margin-left: 20px;">
                <li>Telephonic follow ups by the in-house recovery team of the lender;</li>
                <li>Telephonic follow ups by the outsourced agents of the lender for recovery of the loans;</li>
                <li>Digital email communications from the lender's recovery team;</li>
                <li>Digital email communications by the outsourced agents of the lender for recovery of the loans;</li>
                <li>In-person visit for collection by the in-house recovery team of the lender or outsourced agents of
                    the lender.</li>
            </ol>

            <p>Where the Lender is unable to recover the loan repayment from the borrower, the lender follows the
                following recovery mechanism:</p>

            <ol style="margin-left: 20px;">
                <li>
                    <p>Issuance of legal notice to the borrowers</p>
                    <p>If the payment remains overdue after a certain period, usually around 90 days past due (DPD),
                        ...........will initiate the legal measures for recovery of the loan including repossession of
                        the financed vehicle after providing the due notice in writing. Any genuine difficulties
                        expressed/ disputes raised by the customer will be considered by Lender Name before initiating
                        recovery measures.</p>
                    <p>Lender Name may at its sole discretion consider requests from the borrowers for compromise or
                        settlement of the outstanding loan.</p>
                </li>
                <li>
                    <p>Repossession of financed vehicle</p>
                    <p>Repossession of financed vehicle is aimed at recovery of dues and not to deprive the borrower of
                        the financed vehicle. The recovery process through repossession of financed vehicle will involve
                        repossession, valuation of financed vehicle and realisation of financed vehicle through
                        appropriate means. All these would be carried out in a fair and transparent manner. Due process
                        of law will be followed while taking repossession of the financed vehicle.</p>
                </li>
                <li>
                    <p>Valuation & sale of financed vehicle</p>
                    <p>Valuation and sale of secured assets repossessed by Lender Name will be carried out as per law
                        and in a fair and transparent manner. Lender Name will have right to recover from the borrower
                        the balance due if any, after sale of financed vehicle. Excess amount if any, obtained on sale
                        of the assets shall be paid to the person entitled thereto in accordance with his rights and
                        interests, after meeting all the related expenses, provided Lender Name is not having any other
                        claims against the customer.</p>
                    <p>In the case of hypothecated assets after taking possession if no payment is forthcoming, a sale
                        notice of 7 days' time to respond will be sent to the borrower. Thereafter Lender Name will
                        arrange for sale of the hypothecated assets in such manner as deemed fit by Lender Name.</p>
                </li>
            </ol>
        </div>
    </page>
    <div class="page-break"></div>
    <page>

        <!-- Exhibit 3 -->
        <div style="margin-bottom: 20px;">
            <p style="text-align: center;">
                <strong>Exhibit 2: Detailed Repayment Schedule</strong>
            </p>
            <table style="width: 100%; border-collapse: collapse; margin-bottom: 10px;">
                <tr>
                    <th style="border: 1px solid black; padding: 5px; text-align: center;"><strong>Installment
                            No.</strong></th>
                    <th style="border: 1px solid black; padding: 5px; text-align: center;"><strong>Outstanding
                            Principal (in Rupees)</strong></th>
                    <th style="border: 1px solid black; padding: 5px; text-align: center;"><strong>Principal (in
                            Rupees)</strong></th>
                    <th style="border: 1px solid black; padding: 5px; text-align: center;"><strong>Interest (in
                            Rupees)</strong></th>
                    <th style="border: 1px solid black; padding: 5px; text-align: center;"><strong>Installment (in
                            Rupees)</strong></th>
                </tr>
                @if (isset($loan_details['repayment_schedule']['schedule']))
                    @foreach ($loan_details['repayment_schedule']['schedule'] as $emi)
                        <tr>
                            <td style="border: 1px solid black; padding: 5px; text-align: center;">
                                {{ $emi['month'] }}</td>
                            <td style="border: 1px solid black; padding: 5px; text-align: center;">
                                {{ round($emi['remaining_balance']) + round($emi['principal']) }}</td>
                            <td style="border: 1px solid black; padding: 5px; text-align: center;">
                                {{ round($emi['principal']) }}</td>
                            <td style="border: 1px solid black; padding: 5px; text-align: center;">
                                {{ round($emi['interest']) }}</td>
                            <td style="border: 1px solid black; padding: 5px; text-align: center;">
                                {{ round($emi['total_payment']) }}</td>
                        </tr>
                    @endforeach
                @endif

            </table>

            <p>The Difference in repayment amount calculated from the total of instalments given under the detailed
                repayment schedule is due to rounding off the instalment amount to the next whole rupee.</p>
        </div>
    </page>
    <div class="page-break"></div>
    <page>
        <p style="text-align: center;"><strong><u>LOAN CUM HYPOTHECATION AGREEMENT</u></strong></p>
        <p>This Agreement made at on this&nbsp;&nbsp;&nbsp; ______ day of _____, ______ between
            "{{ !empty($lender_details['company_name']) ? $lender_details['company_name'] : '______________________________________________' }}"
            a company registered under the Indian Companies Act, 1956/2013, having its registered Office at <a
                name="_Hlk192945204"></a>{{ !empty($lender_details['address']) ? $lender_details['address'] : '_________________________________________________________________________________' }}
            and Corporate Office at
            {{ !empty($lender_details['corporate_address']) ? $lender_details['corporate_address'] : '________________________________________________________________________________________' }}
            (hereinafter referred to as
            "<strong>{{ !empty($lender_details['company_short']) ? $lender_details['company_short'] : '______________' }}</strong>&rdquo;
            or &ldquo;<strong>Lender</strong>" which expression shall unless repugnant to the meaning or context there
            of mean and including its successors and assigns)</p>

        <p style="text-align: center;">AND</p>
        <p>Mr./Ms.<strong>{{ !empty($borrower_details['basic']['name']) ? $borrower_details['basic']['name'] : '__________________________________' }}</strong>
            S/o, D/o., W/o. <strong>&nbsp;Mr./Ms.</strong>
            <strong>{{ !empty($borrower_details['basic']['father_name']) ? $borrower_details['basic']['father_name'] : '' }}</strong>
            residing at
            <strong>&nbsp;{{ !empty($borrower_details['address']['current_address']) ? $borrower_details['address']['current_address'] : '____________________________________________________________________________________' }}</strong>
            (Individual/Sole Proprietor/ Partnership Firm/ Company), hereinafter referred to as "Borrower(s)" which
            expression shall unless repugnant to the meaning or context thereof mean and include their Legal heirs.
            Executors permitted assigns. Administrators. Official receivers. Successors.
        </p>
        <p>AND</p>
        <p>Mr./Ms.<strong> {{
                    !empty($co_applicant_details['basic']['name'])
                        ? $co_applicant_details['basic']['name']
                        : (!empty($guarantor_details['basic']['name'])
                            ? $guarantor_details['basic']['name']
                            : '__________________________________________')
                }} </strong>S/o, D/o., W/o.<strong> Mr</strong>.
            <strong> {{
            !empty($co_applicant_details['basic']['father_name'])
                ? $co_applicant_details['basic']['father_name']
                : (!empty($guarantor_details['basic']['father_name'])
                    ? $guarantor_details['basic']['father_name']
                    : '__________________________________')
        }} </strong>
            residing at
            <strong>&nbsp;{{
            !empty($co_applicant_details['address']['permanent_address'])
                ? $co_applicant_details['address']['permanent_address']
                : (!empty($guarantor_details['address']['permanent_address'])
                    ? $guarantor_details['address']['permanent_address']
                    : '____________________________________________________________________________________')
        }}</strong>
            (Individual/Sole Proprietor/ Partnership Firm/ Company), hereinafter referred to as
            "Co-Borrower/Guarantor&rdquo;- which expression shall unless repugnant to the meaning or context thereof
            mean and include their Legal heirs. Executors, permitted assigns, administrators, official receivers,
            successors business, title etc. as the case may be.
        </p>


        <p>{{ !empty($lender_details['company_short']) ? $lender_details['company_short'] : '______________' }} or
            Lender is RBI Registered Non-Banking Financial Company engaged in the business of granting loans to the
            retail individual borrowers in India.</p>

        <p>Whereas each of the Borrower(s) has identified and is desirous of purchasing
            <strong>{{ !empty($loan_details['purpose_of_loan']) ? $loan_details['purpose_of_loan'] : '_______________________' }}</strong>
            (herein referred to as the "<strong>Asset</strong>&rdquo; and for this&nbsp; &nbsp;purpose&nbsp;
            &nbsp;the&nbsp; &nbsp;Borrower has applied for a
            <strong>{{ !empty($loan_details['purpose_of_loan']) ? $loan_details['purpose_of_loan'] : '_______________________' }}</strong>
            loan of Rs.
            {{ !empty($loan_details['loan_amount']) ? $loan_details['loan_amount'] : '__________________' }}<strong>/-
            </strong>(Rupees<strong>
                {{ !empty($loan_details['loan_amount_in_words']) ? $loan_details['loan_amount_in_words'] : '________________________________________________________' }}</strong>)
            from the Lender.
        </p>

        <p>AND WHEREAS the Co-borrower/Guarantor has undertaken to guarantee the Lender, the due performance by the
            Borrower of all clauses, covenants and conditions of this agreement and agrees to pay on demand any money
            due or which may become due and payable to the Lender(s) under this agreement (when not paid by the
            Borrower) either by way of Principal, interest, additional interest, expenses, damages, repairs,
            replacements or other compensations and other costs relating to the loan being granted.</p>
        <p>And the Lender have agreed to provide financial assistance to the Borrower(s) subject to the terms and
            conditions herein after referred.</p>

        <p>
            <strong>

                NOW THIS AGREEMENT WITNESS AND THE BORROWER(S) HEREBY AGREES AND UNDERTAKES AS FOLLOWS.
            </strong>
        </p>

        <ol>
            <li>The Lender agree to extend financial facility to the "Borrower(s)"and each of the "Borrower(s)" agrees
                to avail the said financial facility in respect of the vehicle more particularly described in the
                Schedule attached thereto.
                The Borrower(s) shall repay to the Lender. The loan amount along with the interest at the rate of
                percent on flat rate basis (Annualized Rate of Interest percent per annum) Calculated for the entire
                loan amount and for the tenure of the loan in Equated monthly instalments (EMI) on the due date without
                any reminder.
            </li>
        </ol>
        <ol start="2">
            <li>The EMI shall be paid through NACH/ECS/Cash or any other legally recognized modes of fund transfer as
                per the mandate given by the borrower(s).</li>
            <li>The Lender will disburse the loan by means of bank transfer/crossed account payee cheques or demand
                draft in favor of the dealer who provides the asset, by any legally recognized means.</li>
            <li>All such disbursements shall be deemed to be due disbursement of the loan amount to the Borrower(s) as
                contemplated in the agreement and the Borrower(s) and Guarantor shall be bound by the same.</li>
        </ol>
    </page>
    <div class="page-break"></div>
    <page>
        <p>5. FINANCING ARRANGEMENTS:</p>
        <ol>
            <li style="list-style: none;">
                <ol style="list-style-type: lower-alpha;">
                    <li style="margin-bottom: 10px;">The present agreement being entered into between "The Lender" &
                        "Borrower(s)" is purely a finance arrangement and the "Borrower(s)" accepts and acknowledges
                        that the entire risk, non-performance, non- delivery, breach of supply of inferior or damaged
                        vehicle by the dealer and the Vehicle details, specifications and description are within the
                        knowledge of the "Borrower(s)"and shall be exclusively borne by the "Borrower(s)" and The Lender
                        shall not be liable at any time to any of the Borrower (s) for any liability, claim, loss or
                        expense of any kind whatsoever caused either directly or indirectly to the vehicle nor shall
                        warrant or qualify either express or implied, for the quality, condition or fitness of the
                        vehicle.</li>
                    <li style="margin-bottom: 10px;">The &ldquo;Borrower(s)&rdquo; agrees and undertakes at all times
                        during the&nbsp; &nbsp;subsistence&nbsp; &nbsp;of&nbsp; &nbsp;this&nbsp; &nbsp;agreement,&nbsp;
                        &nbsp;to make the payment of all the installments due on the respective&nbsp; due&nbsp; dates
                        including all&nbsp; &nbsp;their&nbsp; &nbsp;monies due and owing towards
                        &ldquo;{{ !empty($lender_details['company_short']) ? $lender_details['company_short'] : '______________' }}&rdquo;
                        and all the &ldquo;Borrower(s)&rdquo; further agrees and&nbsp; acknowledge&nbsp; that the
                        &ldquo;Borrower(s)&rdquo; shall remain liable and shall continue to pay the installments on the
                        respective due dates regardless of whether the vehicle is under repair or not working or not
                        delivered or where the vehicle is delivered, the same has been confiscated, or taken into
                        &nbsp;custody by any authority or is stolen.</li>
                    <li style="margin-bottom: 10px;">The &ldquo;Borrower(s)&rdquo; shall be liable to pay to
                        &ldquo;{{ !empty($lender_details['company_short']) ? $lender_details['company_short'] : '______________' }}&rdquo;
                        (whatsoever) amounts that have been paid by Lender to Dealer, if any, towards advance, booking
                        costs etc., on behalf of &ldquo;Borrower(s)&rdquo;.</li>
                    <li style="margin-bottom: 10px;">Such amount shall be repayable to
                        &ldquo;{{ !empty($lender_details['company_short']) ? $lender_details['company_short'] : '______________' }}&rdquo;
                        together with Fixed interest @30.93% per month, compounded monthly and shall form part of the
                        total repayment obligations under this agreement.</li>
                </ol>
            </li>
        </ol>

        <p>
            <strong>
                6. OTHER CONDITIONS:
            </strong>
        </p>
        <ol>
            <li style="list-style: none;">
                <ol style="list-style-type: lower-alpha;">
                    <li style="margin-bottom: 10px;">The &ldquo;Borrower(s)&rdquo; agrees and undertakes to make
                        payment of all the EMI and&nbsp; all&nbsp; other&nbsp; monies&nbsp; due and payable to
                        &ldquo;{{ !empty($lender_details['company_short']) ? $lender_details['company_short'] : '______________' }}&rdquo;
                        on the due dates as specified and set out in the schedule and the monthly installments including
                        interest and all other monies every month as mentioned in the schedule&nbsp;&nbsp; amounts to
                        shall be considered as effectual discharge of repayment of loan obligations owing and due
                        towards
                        &ldquo;{{ !empty($lender_details['company_short']) ? $lender_details['company_short'] : '______________' }}&rdquo;.
                    </li>
                    <li style="margin-bottom: 10px;">The &ldquo;Borrower(s)&rdquo; shall be liable to pay late payment
                        interest @ 36% per annum for the period of Late Payment, on the overdue amount. In addition, the
                        &ldquo;Borrower(s)&rdquo; shall be liable for overdue charges on the outstanding amount from the
                        due date till the date of receipt of payment and shall also be liable to reimburse all the costs
                        and expenses whatsoever, including legal fees incurred or suffered or sustained by in the
                        process of collection of payments from &ldquo;Borrower&rdquo;. Nothing contained in this clause
                        shall prejudice the other rights of in law or otherwise.</li>
                </ol>
            </li>
        </ol>

        <p style="font-weight: bold;">7. SECURITY</p>
        <ol>
            <li style="list-style: none;">
                <ol style="list-style-type: lower-alpha;">
                    <li style="margin-bottom: 10px;">The &ldquo;Borrower(s)&rdquo; hereby agrees and undertakes to
                        Hypothecate the said Vehicle as and by way of first and exclusive charge in favour of
                        &ldquo;{{ !empty($lender_details['company_short']) ? $lender_details['company_short'] : '______________' }}&rdquo;
                        for the financial facility availed from
                        &ldquo;{{ !empty($lender_details['company_short']) ? $lender_details['company_short'] : '______________' }}&rdquo;
                        by making hypothecation endorsement in the R.C. Book. The &ldquo;Borrower(s)&rdquo; is also
                        estopped under law from taking the plea that on the date of execution of the agreement, the
                        exact details of the vehicle was not available and or not known to the
                        &ldquo;Borrower(s)&rdquo;.</li>
                    <li style="margin-bottom: 10px;">Each of the Borrower(s) shall also execute a Promissory Note in
                        favour of the Lender
                        ({{ !empty($lender_details['company_short']) ? $lender_details['company_short'] : '______________' }})
                        for the amount of facility as <br> specified in the schedule together with interest.</li>
                    <li style="margin-bottom: 10px;">The Borrower(s) shall arrange the execution of the documents
                        regarding the loan as per requirement of the Lender and shall also furnish a photograph of the
                        each of the Borrower(s)/ Guarantor and also copies of any&nbsp; other identification/address
                        proof of each of the Borrower(s)/Guarantors as may be required by the Lender.</li>
                    <li style="margin-bottom: 10px;">The Borrower(s) shall ensure that the Vehicle has appropriate
                        <em>VAHAN Registration</em> from Ministry of Transport and the appropriate registration
                        certificate (RC) is obtained within the defined timelines as per the applicable law(s).
                    </li>
                </ol>
            </li>
        </ol>
    </page>
    <div class="page-break"></div>
    <page>
        <ol>
            <li style="list-style: none;">
                <ol style="list-style-type: none;">
                    <li style="margin-bottom: 10px;">e. Each of the Borrower(s) shall ensure that the Hypothecation
                        endorsement in the Registration certificate Book is made in favour of the Lender and a copy of
                        the same is delivered to Lender within a month from the date of disbursement of loan amount or
                        as required by Lender.</li>
                    <li style="margin-bottom: 10px;">f. The &ldquo;Borrower(s)&rdquo; or and shall ensure that the
                        Vehicle is covered by a Comprehensive Insurance policy during the pendency of the loan without
                        any break and the &ldquo;Borrower(s)&rdquo; and shall ensure that insurance claims are
                        satisfactorily settled case any untoward incident / accident happens.</li>
                </ol>
            </li>
        </ol>
        <p style="font-weight: bold;">8. OTHER CONDITIONS:</p>
        <p>Each of the Borrower(s) agrees to the following conditions:</p>
        <ol style="list-style-type: lower-alpha;">
            <li style="list-style: none;">
                <ol style="list-style-type: lower-alpha;">
                    <li style="margin-bottom: 10px;">To secure the facility by creation of first ranking exclusive
                        charge by way of hypothecation of the vehicle in favour of
                        {{ !empty($lender_details['company_short']) ? $lender_details['company_short'] : '______________' }}
                        by due endorsement in the Registration Certificate book.</li>
                    <li style="margin-bottom: 10px;">To permit the Lender and / or any of its agents / authorized
                        representative to inspect the vehicle from time to time.</li>
                    <li style="margin-bottom: 10px;">To keep the vehicle in good and serviceable and running order and
                        condition to the satisfaction of the Lender and bear all expenses for maintaining the same. Any
                        reasonable wear and tear and such wear and tear caused by force majeure event shall be accepted.
                        In the event of any "Borrower(s)" not maintaining the same in serviceable and running order and
                        condition to the satisfaction of the Lender, then the Lender reserve the right to maintain the
                        vehicle at the risk and cost of the Borrower(s) and all the expenses incurred by the Lender in
                        this respect would be borne by the Borrower(s) <br>/ reimbursed by the Borrower(s) to the Lender
                        from time to time.</li>
                    <li style="margin-bottom: 10px;">Not to have the Vehicle re-registered during the subsistence of
                        this facility without the prior written consent of the Lender (i.e. Not to sell, assign, pledge,
                        mortgage. or encumber or in any way art with the possession of the vehicle without the prior
                        written consent of the Lender.)</li>
                    <li style="margin-bottom: 10px;">Not to remove / replace any or all parts of the vehicle.</li>
                    <li style="margin-bottom: 10px;">To intimate the Lender immediately of any change in the
                        residential / office address, constitution as well as any change in the Bank Account.</li>
                    <li style="margin-bottom: 10px;">The &ldquo;Borrower(s)&rdquo; hereby agrees and acknowledges that
                        &ldquo;{{ !empty($lender_details['company_short']) ? $lender_details['company_short'] : '______________' }}&rdquo;
                        has authorized to undertake all collection, repossession and other related activities on its
                        behalf and to that extent the &ldquo;Borrower(s)&rdquo; agrees to make payment of EMI late
                        payment charges, penalty for dishonor of Cheques and other charges, to as may be required. The
                        &ldquo;Borrower(s)&rdquo; also agrees to the
                        &ldquo;{{ !empty($lender_details['company_short']) ? $lender_details['company_short'] : '______________' }}&rdquo;
                        right to repossess the vehicle financed by
                        &ldquo;{{ !empty($lender_details['company_short']) ? $lender_details['company_short'] : '______________' }}&rdquo;
                        in case the &ldquo;Borrower(s)&rdquo; is in default of any of the monthly installments.</li>
                    <li style="margin-bottom: 10px;">The Borrower(s) hereby agrees and acknowledges that its
                        LSP/Lending Service Provider (i.e. Kilpi Technology and Services Private Limited) through its
                        employees/Representative will undertake the collection and recovery activity. The Borrower
                        expressly recognizes and accepts that the LSP shall jointly or severally without prejudice to
                        their rights to perform such activities themselves or through their office employees be entitled
                        and have full power and authority so to appoint one or more third parties to delegate to such
                        party all or any of their functions, rights, and power relating to the appointment of other
                        third party recovery agents, registration of E- Nach mandates, collection, administration,
                        monitoring of the loan and to perform and execute all lawful acts, deeds, matters and things
                        connected therewith and incidental thereto including sending notices, contacting Borrower,
                        receiving repayment instruments from the Borrower, instituting legal proceedings, collection
                        activities, in favor of the Lender(s) or LSP, etc.</li>
                    <li style="margin-bottom: 10px;">The Borrower may approach Lender(s) in case of any grievances
                        pertaining to the loan as process under .
                        {{ !empty($lender_details['approach_via_link']) ? $lender_details['approach_via_link'] : '___________________________________________________' }}
                    </li>
                </ol>
            </li>
        </ol>
    </page>
    <div class="page-break"></div>
    <page>
        <p style="font-weight: bold;">9. PREPAYMENT:</p>
        <ol>
            <li style="list-style: none;">
                <ol style="list-style-type: lower-alpha;">
                    <li>The &ldquo;Borrower(s)&rdquo; may repay the entire Loan by giving to
                        &ldquo;{{ !empty($lender_details['company_short']) ? $lender_details['company_short'] : '______________' }}&rdquo;
                        not less than 7 days&rsquo; notice in writing about his/her intention to exercise such option to
                        terminate/prepay the loan facility. The &ldquo;Borrower(s)&rdquo; shall pay to the stipulated
                        installments and other sums due up to the date of such termination/prepayment.</li>
                    <li>If The Borrower(s) proposes to foreclose the loan either pre delivery or post-delivery of the
                        asset, the Lender shall charge prepayment charges including taxes if applicable, as mentioned in
                        the schedule and the Key Fact Statement.</li>
                </ol>
            </li>
        </ol>

        <p style="font-weight: bold;">10. EVENT OF DEFAULTS</p>
        <p>10.1 An event of default shall be deemed to have occurred if the &ldquo;Borrower(s)&rdquo;: </p>
        <ol>
            <li style="list-style: none;">
                <ol style="list-style-type: lower-alpha;">
                    <li>fails to make repayment of any installment of the Loan, whether principal or interest, or any
                        other due amount within 7 (seven) days from the due date; or</li>
                    <li>fails to perform any obligations; or</li>
                    <li>Any Fraud or misrepresentation, or concealment of any material information which may have
                        affected the decision of the Lender; or</li>
                    <li>Commits a breach of any of the terms, covenants, representations or conditions mentioned herein
                        above, or</li>
                    <li>Death, lunacy, or any other disability of the Borrower; or</li>
                    <li>Change of residency status of the Borrower with the consent of the Lender; or</li>
                    <li>The Borrower's financial condition deteriorates and/or the Borrower commits an act of bankruptcy
                        or makes assignment for the benefits of creditors or consents to appointment of a Trustee or
                        Receiver or Liquidation proceedings, voluntary or otherwise, consents for being / to be
                        initiated against the &ldquo;Borrower(s)&rdquo;</li>
                    <li>The Borrower sells, transfers, or attempts to sell or transfer the Vehicle without the prior
                        written consent of the NBFC.</li>
                    <li>The Borrower uses the Vehicle for illegal activities, or it is involved in accidents causing
                        significant damage.</li>
                    <li>Upon occurrence of any of the events of default and at any time, thereafter, &ldquo;
                        {{ !empty($lender_details['company_short']) ? $lender_details['company_short'] : '______________' }}&rdquo;
                        shall declare that all sums for the full terms of the loan facility to have immediately become
                        due and payable and upon the &ldquo;Borrower(s)&rdquo; failing to make the said payments within
                        7 days, thereof
                        &ldquo;{{ !empty($lender_details['company_short']) ? $lender_details['company_short'] : '______________' }}&rdquo;
                        may, at its sole discretion;</li>
                    <li>Demand that the &ldquo;Borrower(s)&rdquo; shall return the&nbsp;&nbsp; vehicle to
                        &ldquo;{{ !empty($lender_details['company_short']) ? $lender_details['company_short'] : '______________' }}&rdquo;
                        in the same condition as delivered, reasonable wear and tear excepted and in the event of
                        failure of the &ldquo;Borrower&rdquo; to comply with the same within 7 days from the date of the
                        demand, enter upon the premises where such vehicle is located and take immediate possession of
                        and remove the same, all without the liability of the Lender or any of its authorized
                        representative for such entry or for damage caused to the property or otherwise. For the purpose
                        of repossession of the vehicle, each of the Borrower(s) expressly grants irrevocable authority
                        to the Lender or its agents or other authorized representative for the purpose of exercising its
                        rights under this clause.</li>
                    <li>Sell the vehicle by public auction, private sale with or without a notice to the Borrower(s)
                        /Guarantor or otherwise dispose off, hold, use, operate, lease to other, or keep idle such
                        vehicle, without any duty to account to the Borrower(s) for such action or inaction or for any
                        proceeds with respect there to.</li>
                    <li>Require the Borrower(s) in respect of the facility to pay liquidated damages equal to all unpaid
                        installment which in the absence of a default would have been payable by the Borrower for the
                        full term hereof; and shall additional interest as agreed herein in case of Default; or</li>
                    <li>Exercise any other rights or remedy, which may be available to it under the applicable laws.
                    </li>
                    <li>The Borrower(s) shall also be liable for all legal and other costs and expenses resulting from
                        the foregoing defaults or the exercise of the Lender and/or remedies, including repossession of
                        any vehicle.</li>
                    <li>In case of any shortfall in respect of the amount due to the &ldquo;Lender&rdquo; and or
                        &ldquo;after the sale of vehicle&rdquo;, and shall be entitled to proceed against the
                        Borrower(s) and /or Guarantor for recovery of such deficiency.</li>
                </ol>
            </li>
        </ol>

        <p>11. Repossession(s) of Vehicle in case of Event of Default:</p>
        <p>11.1. Default Conditions: The Borrower agrees that the Lender shall have a right to re-possess the Vehicle in
            case of any Event of Default, as determined under this Agreement and as per the applicable law(s).</p>
    </page>
    <div class="page-break"></div>
    <page>

        <p>11.2. Repossession Rights: Upon the occurrence of any Event of Default described above, the Lender shall have
            the right, without further notice, to repossess the Vehicle. The Borrower agrees that:</p>
        <p>(i) The Lender may take possession of the Vehicle at any time during normal business hours or at any other
            reasonable time, by entering the Borrower's premises or any other location where the Vehicle is located.</p>
        <p>(ii) The Borrower hereby explicitly grants the Lender, its agents, or representative&rsquo;s full access to
            the premises and/or the Vehicle, and confirms that the Borrower shall not interfere with the repossession
            process.</p>
        <p>(iii) The Lender may remove the Vehicle, irrespective of whether it is in the possession of the Borrower or
            any third party.</p>
        <p>11.3. Procedure for Repossession:</p>
        <p>(i) Notice of Default: Prior to repossession, the Lender shall provide a notice of default to the Borrower,
            specifying the nature of the default and the opportunity to cure the default, if applicable, within a period
            of 7 (seven) days.</p>
        <p>(ii) Repossession Process: (a) the Borrower shall be given a final chance for making an immediate repayment
            of loan before the sale/ auction of the Vehicle; (b) If the Borrower fails to comply with (a) then the
            repossession will be conducted in a lawful and commercially reasonable manner and the Lender will take all
            necessary actions to ensure the security and preservation of the Vehicle during repossession.</p>
        <p>11.4. Post-Repossession:</p>
        <p>(i) Sale of Vehicle: After repossession, the Lender may sell the Vehicle in accordance with applicable laws
            and regulations. Any proceeds from the sale will be applied towards the outstanding Loan balance. If the
            sale proceeds are insufficient to cover the Loan, the Borrower shall remain liable for the remaining
            balance.</p>
        <p>(ii) Deficiency Judgment: If the sale of the Vehicle does not fully cover the outstanding Loan balance, the
            Lender reserves the right to seek a deficiency judgment against the Borrower for the shortfall.</p>
        <p>11.5. Rights and Waivers: The Borrower acknowledges and agrees that:</p>
        <p>(i) The Borrower waives any claims against the Lender related to the repossession of the Vehicle, provided
            that the repossession is conducted in accordance with the terms of this Agreement and applicable laws.</p>
        <p>(ii) The Borrower may, at any time prior to repossession, cure the default by paying all overdue amounts,
            including any fees or costs incurred by the Lender related to the default, repossession, and the enforcement
            of this Agreement.</p>

        <p style="font-weight: bold;">12. CLASSIFICATION AS SPECIAL MENTION ACCOUNT (SMA) AND NON- PERFORMING ASSET
            (NPA)</p>
        <p>12.1 The Borrower agrees and understands that as per the applicable circular issued by RBI from time to time
            on &ldquo;Prudential Framework for Resolution of Stressed Assets&rdquo;, the Lender is required to recognize
            incipient stress in Borrower&rsquo;s accounts, immediately on default, by classifying them as Special
            Mention Account (SMA) and Non- Performing Asset (NPA).</p>
        <p>The Prudential Norms require the Lender(s) to recognize incipient stress in Borrower accounts, immediately on
            default, by classifying them as SMAs. An account/facility would be classified as SMA category if the amount
            due and payable to Lender(s) is not paid by the Borrower by the due date. Basis aging of overdues, the
            Lender(s) reports the Borrower under the following SMA categories.</p>

        <p>12.2 Classification as SMA and Applicable Categories:</p>
    </page>
    <div class="page-break"></div>
    <page>

        <table width="100%" border="1" cellspacing="0" cellpadding="0">
            <tbody>
                <tr style="height: 28.5pt;">
                    <td style="width: 50%;" colspan="2" valign="top">
                        <p>Loan other than revolving facilities</p>
                    </td>
                    <td style="width: 197.45pt;" colspan="2" valign="top">
                        <p>Loan in the nature of revolving facilities like cash credit/overdraft</p>
                    </td>
                </tr>
                <tr style="height: 104.15pt;">
                    <td style="width: 143.85pt;" valign="top">
                        <p>SMA sub- categories</p>
                    </td>
                    <td style="width: 68.8pt; text-align: center;" valign="top">
                        <p>Basis for classification - Principal or interest payment or any other amount wholly or partly
                            overdue:</p>
                    </td>
                    <td style="width: 108.95pt;" valign="top">
                        <p>SMA sub- categories</p>
                    </td>
                    <td style="width: 88.5pt; text-align: center;" valign="top">
                        <p>Basis for classification - Outstanding balance remains continuously in excess of the
                            sanctioned limit or drawing power,</p>
                    </td>
                </tr>
                <tr style="height: 28.4pt;">
                    <td style="width: 143.85pt;" valign="top">
                        <p>&nbsp;</p>
                    </td>
                    <td style="width: 68.8pt;" valign="top">
                        <p>&nbsp;</p>
                    </td>
                    <td style="width: 108.95pt;" valign="top">
                        <p>&nbsp;</p>
                    </td>
                    <td style="width: 88.5pt; text-align: center;" valign="top">
                        <p>whichever lower, for a period of:</p>
                    </td>
                </tr>
                <tr style="height: 20.45pt;">
                    <td style="width: 143.85pt;" valign="top">
                        <p>SMA-0</p>
                    </td>
                    <td style="width: 68.8pt; text-align: center;" valign="top">
                        <p>Up to 30 days</p>
                    </td>
                    <td style="width: 108.95pt;" valign="top">
                        <p>NA</p>
                    </td>
                    <td style="width: 88.5pt;" valign="top">
                        <p>NA</p>
                    </td>
                </tr>
                <tr style="height: 27.55pt;">
                    <td style="width: 143.85pt;" valign="top">
                        <p>SMA-1</p>
                    </td>
                    <td style="width: 68.8pt; text-align: center;" valign="top">
                        <p>More than 30 days and up to 60 days</p>
                    </td>
                    <td style="width: 108.95pt;" valign="top">
                        <p>SMA-1</p>
                    </td>
                    <td style="width: 88.5pt; text-align: center;" valign="top">
                        <p>More than 30 days and up to 60 days</p>
                    </td>
                </tr>
                <tr style="height: 27.95pt;">
                    <td style="width: 143.85pt;" valign="top">
                        <p>SMA-2</p>
                    </td>
                    <td style="width: 68.8pt; text-align: center;" valign="top">
                        <p>More than 60 days and up to 90 days</p>
                    </td>
                    <td style="width: 108.95pt;" valign="top">
                        <p>SMA-2</p>
                    </td>
                    <td style="width: 88.5pt; text-align: center;" valign="top">
                        <p>More than 60 days and up to 90 days</p>
                    </td>
                </tr>
            </tbody>
        </table>

        <p>12.3 Classification as Non-Performing Asset (NPA)</p>
        <p>NPA: A Credit Line/Loan shall be classified as an NPA where:</p>
        <p>(a)&nbsp;&nbsp; interest applied at a specified date in respect of a term loan remains overdue for more than
            90 days. Classification of Borrower account as NPA shall be done as part of day end process of the relevant
            date and NPA classification date shall be the calendar date for which day end process is run.</p>
        <p>(b)&nbsp; The bill remains overdue for a period of more than 90 days in the case of bills purchased and
            discounted.</p>
        <p>(c)&nbsp;&nbsp; In the case of interest payments, if the interest due and charged during any quarter is not
            serviced fully within 90 days from the end of the quarter.</p>

        <p>Illustrative Example explaining above concepts</p>
        <p>If the due date of a loan account is March 31, 2025, and full dues are not received before Lender(s) runs the
            day-end process for this date, the date of overdue shall be March 31, 2025, and the account shall be
            classified as SMA-0. If it continues to remain overdue, then this account shall get tagged as SMA-1 upon
            running the day-end process on April 30, 2025, i.e., upon completion of 30 days of being continuously
            overdue. Accordingly, the date of SMA-1 classification for that account shall be April 30, 2025.</p>
        <p>Similarly, if the account continues to remain overdue, it shall get tagged as SMA-2 upon running the day-end
            process on May 30, 2025, and if it continues to remain overdue further, it shall get classified as NPA upon
            running the day-end process on June 29, 2025.</p>

        <p>12.4 Upgradation of accounts classified as NPA:</p>
        <p>All the Credit Line accounts of a Borrower will be classified as NPA in case any one of the accounts gets
            classified as NPA and the loan accounts classified as NPAs may be upgraded as &lsquo;Standard&rsquo; assets,
            only if the entire arrears of interest and principal are paid by the Borrower.</p>
    </page>
    <div class="page-break"></div>
    <page>

        <p style="font-weight: bold;">13. ASSIGNMENT:</p>
        <p>The Lender shall have full and absolute right to transfer, assign and sell (including by way of
            securitization or transfer of loan exposure/direct assignment transactions) in any manner, in whole or in
            part, the outstanding and&nbsp; dues&nbsp; to any bank or financial institution without any reference or
            intimation to the Borrower(s). Notwithstanding any such transfer, assignment or sale the Lender shall be
            fully empowered to proceed against the Borrower(s) jointly or severally with any such purchaser, assignee,
            or creditor to recover the outstanding amount from the Borrower(s). Such assignment shall not in any manner
            release the Borrower(s) of their liability towards the Lender.</p>

        <p style="font-weight: bold;">14. COMPLIANCE WITH REGULATIONS:</p>
        <p>The Borrower(s) and the Lender agree to comply jointly and severally with all applicable laws and regulations
            from time to time in force which may be attracted and Borrower(s) agrees to indemnify the Lender in respect
            of any loss, claim or expense to the lender as Guarantor a result of non-compliance with any such laws and
            regulations.</p>
        <p>The Borrower(s) agrees that the amounts payable in respect of the facility shall be increased by incremental
            taxes whether sales or excise, interest, tax and any other related and consequential charges taxes levied on
            this transaction now or hereafter to be levied as also any increase in the price of the vehicle to be
            purchased between the date of placement of the order and delivery date. Any such increase will be paid
            directly by the Borrower(s) to the dealer /the Lender without affecting the payment of installments in
            respect of the facility.</p>

        <p style="font-weight: bold;">15. ARBITRATION:</p>
        <p>All loan documents shall be governed by and construed in accordance with the laws of India.</p>
        <p>All disputes, differences and/or claims arising out of these presents or as to the construction, meaning or
            effect hereof or as to the right and liabilities of the parties under the loan documents shall be settled by
            arbitration in accordance with the provisions of the Arbitration and Conciliation Act, 1996 or any statutory
            amendments thereof or any statute enacted for replacement therefore and shall be referred to a Sole
            Arbitrator to be appointed by the Lender(s) or Lender&rsquo;s Agent or any Arbitration center that the
            Lender(s) or Lender&rsquo;s Agent may nominate. The place of arbitration shall be Udaipur. The Arbitrator
            may lay down from time to time the procedure to be followed by him in conducting arbitration proceedings and
            shall conduct arbitration proceedings in such manner as he considers appropriate and is hereby expressly
            authorized to adopt such summary/fast track procedure for the conduct of the proceedings as she/he may deem
            fit, including dispensing with oral hearings and issue the award within three (3) months from the date the
            arbitral tribunal enters upon the reference. The awards, including interim reliefs/awards of the
            arbitration, shall be final and binding on all parties concerned. The Borrower agrees that the final or
            Interim reliefs/Award may inter alia include directions to submit a list of their financial details and
            properties, that they may not dispose of, part with the possession of or create third party interests over
            their properties, attachment of their properties, freezing of their bank accounts etc. The arbitrator may
            pass the award without stating any reasons for such an award. Further, the present clause shall survive the
            termination of loan documents. The Courts at Udaipur, Rajasthan shall have exclusive jurisdiction (subject
            to the arbitration proceedings which are to be also conducted in Udaipur, Rajasthan) over any or all
            disputes arising out of the loan documents.</p>

        <p style="font-weight: bold;">16. GOVERNING LAWS AND JURISDICTION:</p>
        <p>This agreement shall be constructed in accordance with the laws in India. Any or all disputes arising out of
            this agreement shall be subject the exclusion jurisdiction of the courts at Udaipur &nbsp;&nbsp;only</p>
        <p>&nbsp;</p>
        <p>We Agree:</p>

    </page>
    <div class="page-break"></div>
    <page>
        <div>
            <p style="font-weight: bold;" align="center">SCHEDULE</p>
            <div style="display: flex; justify-content: space-around;">
                <p><strong>
                        Loan Agreement :
                        &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
                        Place of Agreement :
                        &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
                        Date :
                        &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
                    </strong></p>
            </div>
            <table border="1" cellspacing="0" cellpadding="0" width="100%">
                <tbody>
                    <tr style="height: 17.25pt;">
                        <td style="width: 484.0pt; text-align: center;" colspan="2" valign="top">
                            <p><strong>NAME AND ADDRESS </strong></p>
                        </td>
                    </tr>
                    <tr style="height: 33.55pt;">
                        <td style="width: 184.9pt;" valign="top">
                            <p>Borrower</p>
                        </td>
                        <td style="width: 299.1pt;" valign="top">
                            <p>&nbsp;{{ !empty($borrower_details['basic']['name']) ? $borrower_details['basic']['name'] : '' }}
                            </p>
                        </td>
                    </tr>
                    <tr style="height: 44.6pt;">
                        <td style="width: 184.9pt;" valign="top">
                            <p>Co-borrower</p>
                        </td>
                        <td style="width: 299.1pt;" valign="top">
                            <p>&nbsp;{{ !empty($co_applicant_details['basic']['name']) ? $co_applicant_details['basic']['name'] : '' }}
                            </p>
                        </td>
                    </tr>
                    <tr style="height: 44.85pt;">
                        <td style="width: 184.9pt;" valign="top">
                            <p>Guarantor</p>
                        </td>
                        <td style="width: 299.1pt;" valign="top">
                            <p>&nbsp;{{ $guarantor_details['basic']['name'] ?? '' }}</p>
                        </td>
                    </tr>
                </tbody>
            </table>
            <p><strong>&nbsp;</strong></p>
            <table border="1" cellspacing="0" cellpadding="0" width="100%">
                <tbody>
                    <tr style="height: 18.45pt;">
                        <td style="width: 483.25pt;" colspan="7" valign="top">
                            <p><strong>LOAN DETAILS</strong></p>
                        </td>
                    </tr>
                    <tr style="height: 11.25pt;">
                        <td style="width: 96.75pt;" valign="top">
                            <p>Dealer/Seller Name</p>
                        </td>
                        <td style="width: 386.5pt;" colspan="6" valign="top">
                            <p> {{ $loan_details['dealer_name'] ?? '' }}</p>
                        </td>
                    </tr>
                    <tr style="height: 14.6pt;">
                        <td style="width: 96.75pt;" valign="top">
                            <p>Asset/Vehicle Cost</p>
                        </td>
                        <td style="width: 122.5pt;" colspan="3" valign="top">
                            <p> Rs. {{ $loan_details['ex_showroom_price'] ?? '___________________' }}/-</p>
                        </td>
                        <td style="width: 108.45pt;" colspan="2" valign="top">
                            <p>Loan Amount</p>
                        </td>
                        <td style="width: 155.55pt;" valign="top">
                            <p> Rs.
                                {{ !empty($loan_details['loan_amount']) ? $loan_details['loan_amount'] : '__________________' }}/-
                            </p>
                        </td>
                    </tr>
                    <tr style="height: 13.65pt;">
                        <td style="width: 96.75pt;" valign="top">
                            <p>Loan Tenure</p>
                        </td>
                        <td style="width: 122.5pt;" colspan="3" valign="top">
                            <p> {{ $loan_details['loan_term'] ?? '_____' }} Month</p>
                        </td>
                        <td style="width: 108.45pt;" colspan="2" valign="top">
                            <p>EMI Scheme</p>
                        </td>
                        <td style="width: 155.55pt;" valign="top">
                            <p>Arrears</p>
                        </td>
                    </tr>
                    <tr style="height: 12.2pt;">
                        <td style="width: 96.75pt;" valign="top">
                            <p>Advance EMI</p>
                        </td>
                        <td style="width: 122.5pt;" colspan="3" valign="top">
                            <p>&nbsp;</p>
                        </td>
                        <td style="width: 108.45pt;" colspan="2" valign="top">
                            <p>Advance EMI Amount</p>
                        </td>
                        <td style="width: 155.55pt;" valign="top">
                            <p>&nbsp;</p>
                        </td>
                    </tr>
                    <tr style="height: 12.9pt;">
                        <td style="width: 96.75pt;" valign="top">
                            <p>Rate of Interest {{ (isset($loan_details) && $loan_details['interest_type'] === 'flat') ? '(Fixed)' : '(Reducing)' }}</p>
                        </td>
                        <td style="width: 115.45pt;" colspan="3" valign="top">
                            <p><strong>&nbsp; &nbsp;
                                    {{ !empty($loan_details['annual_roi']) ? $loan_details['annual_roi'] : '____' }}% p.a</strong>
                            </p>
                        </td>
                        <td style="width: 108.45pt;" colspan="2" valign="top">
                            <p>Repayment</p>
                        </td>
                        <td style="width: 155.55pt;" valign="top">
                            <p>Monthly</p>
                        </td>
                    </tr>
                    <tr style="height: 11.25pt;">
                        <td style="width: 96.75pt;" valign="top">
                            <p>EMI Amount</p>
                        </td>
                        <td style="width: 122.5pt;" colspan="3" valign="top">
                            <p> Rs. {{ !empty($loan_details['emi']) ? $loan_details['emi'] : '________' }}/-</p>
                        </td>
                        <td style="width: 108.45pt;" colspan="2" valign="top">
                            <p>&nbsp;</p>
                        </td>
                        <td style="width: 155.55pt;" valign="top">
                            <p>&nbsp;</p>
                        </td>
                    </tr>
                    <tr style="height: 14.35pt;">
                        <td style="width: 96.75pt;" valign="top">
                            <p>EMI Start Date</p>
                        </td>
                        <td style="width: 122.5pt;" colspan="3" valign="top">
                            <p> {{ !empty($loan_details['emi_start_date']) ? $loan_details['emi_start_date'] : '' }}
                            </p>
                        </td>
                        <td style="width: 108.45pt;" colspan="2" valign="top">
                            <p>EMI Date</p>
                        </td>
                        <td style="width: 155.55pt;" valign="top">
                            <p> {{ !empty($loan_details['monthly_emi_date']) ? $loan_details['monthly_emi_date'] : '____' }}<sup>th</sup>
                                of every month</p>
                        </td>
                    </tr>
                </tbody>
            </table>
    </page>
    <div class="page-break"></div>
    <page>
        <table border="1" cellspacing="0" cellpadding="0" width="100%">
            <tbody>
                <tr style="height: 22.5pt;">
                    <td style="width: 483.25pt;" colspan="7" valign="top">
                        <p><strong>&nbsp;</strong></p>
                        <p style="text-align: center;"><strong>CHARGES</strong></p>
                    </td>
                </tr>
                <tr style="height: 50.35pt;">
                    <td style="width: 103.8pt;" colspan="2" valign="top">
                        <p>Bouncing charges per presentation</p>
                    </td>
                    <td style="width: 101.05pt;" valign="top">
                        <p>Rs.{{ !empty($loan_details['bouncing_charges']) ? $loan_details['bouncing_charges'] : '_______' }}/-
                            +GST</p>
                    </td>
                    <td style="width: 115.2pt;" colspan="2" valign="top">
                        <p>Late payment/Penal/Default</p>
                        <p>charges</p>
                    </td>
                    <td style="width: 163.2pt;" colspan="2" valign="top">
                        <p>36% per annum (Exclusive of GST) of the unpaid amount from the Due Date till the date of
                            Repayment</p>
                    </td>
                </tr>
                <tr style="height: 20.35pt;">
                    <td style="width: 103.75pt;" colspan="2" valign="top">
                        <p>Processing fees</p>
                    </td>
                    <td style="width: 101.1pt;" valign="top">
                        <p>Rs.{{ !empty($loan_details['processing_fee']) ? $loan_details['processing_fee'] : '________' }}/-
                        </p>
                    </td>
                    <td style="width: 115.25pt;" colspan="2" valign="top">
                        <p>Foreclosure/Prepayment charges</p>
                    </td>
                    <td style="width: 163.3pt;" colspan="2" valign="top">
                        <p>{{ !empty($loan_details['pre_closure_charge']) ? $loan_details['pre_closure_charge'] : '_______' }}%
                            of principle outstanding amount</p>
                    </td>
                </tr>
                <tr style="height: 29.35pt;">
                    <td style="width: 103.75pt;" colspan="2" valign="top">
                        <p>Documentation</p>
                        <p>Charges</p>
                    </td>
                    <td style="width: 101.1pt;" valign="top">
                        <p>NIL</p>
                    </td>
                    <td style="width: 115.25pt;" colspan="2" valign="top">
                        <p>Stamping Charges</p>
                    </td>
                    <td style="width: 163.3pt;" colspan="2" valign="top">
                        <p>NIL</p>
                    </td>
                </tr>
            </tbody>
        </table>
        <p>&nbsp;</p>
        <p>&nbsp;</p>
        <p style=""><strong><u>Vehicle Details:
                    {{ !empty($loan_details['purpose_of_loan']) ? $loan_details['purpose_of_loan'] : '______________________' }},
                    Model: -
                    {{ !empty($loan_details['model']) ? $loan_details['model'] : '______________________' }}</u></strong>
        </p>

        <p>Hypothecation of the Vehicle to be done in favor of The Lender and all the terms and conditions of the
            Agreement and other associated facility documents must be fulfilled by the Borrower/Co-Borrower/Guarantor.
            Otherwise the Delay Interest and other Penalty shall be levied accordingly.</p>

        <p>{{ !empty($lender_details['company_name']) ? $lender_details['company_name'] : '______________________________________________' }}
            does not accept any payment either in cash or kind along with or in connection with its products at the time
            of applying for the Loan. Charges mentioned or prescribed herein the Schedule or the Associated Documents
            are the only Charges levied. The Lender or any of its Directors/Employees/ and/or its Officers shall neither
            be held liable for any Payment (if any) made in Cash or Kind to any Third Party.</p>
        <p>&nbsp;</p>
        <p>We Agree:</p>

        </p>
        </div>
    </page>
    <div class="page-break"></div>
    <page>
        <div>

            <table border="1" cellspacing="0" cellpadding="0" width="100%">
                <tbody>
                    <tr style="height: 23.95pt;">
                        <td style="width: 504.2pt;" colspan="2" valign="top">
                            <p style="text-align: center;"><strong>SCHEDULE</strong></p>
                        </td>
                    </tr>
                    <tr style="height: 22.5pt;">
                        <td style="width: 252.1pt;" valign="top">
                            <p>Place of Agreement</p>
                        </td>
                        <td style="width: 252.1pt;" valign="top">
                            <p>{{ !empty($loan_details['place_of_aggrement']) ? $loan_details['place_of_aggrement'] : '' }}
                            </p>
                        </td>
                    </tr>
                    <tr style="height: 22.25pt;">
                        <td style="width: 252.1pt;" valign="top">
                            <p>Date of Agreement</p>
                        </td>
                        <td style="width: 252.1pt;" valign="top">
                            <p>{{ !empty($loan_details['date_of_aggrement']) ? $loan_details['date_of_aggrement'] : '' }}
                            </p>
                        </td>
                    </tr>
                    <tr style="height: 12.2pt;">
                        <td style="width: 252.1pt;" valign="top">
                            <p>Name of Lender</p>
                        </td>
                        <td style="width: 252.1pt;" valign="top">
                            <p>{{ !empty($lender_details['company_name']) ? $lender_details['company_name'] : '' }}
                            </p>
                        </td>
                    </tr>
                    <tr style="height: 22.5pt;">
                        <td style="width: 252.1pt;" valign="top">
                            <p>Address Of Lender</p>
                        </td>
                        <td style="width: 252.1pt;" valign="top">
                            <p>Registered Office:</p>
                            <p>{{ !empty($lender_details['address']) ? $lender_details['address'] : '' }}</p>
                            <p>Corporate Office:</p>
                            <p>{{ !empty($lender_details['corporate_address']) ? $lender_details['corporate_address'] : '' }}
                            </p>
                        </td>
                    </tr>
                    <tr style="height: 22.25pt;">
                        <td style="width: 252.1pt;" valign="top">
                            <p>Name(s) of the Borrower</p>
                        </td>
                        <td style="width: 252.1pt;" valign="top">
                            <p>{{ !empty($borrower_details['basic']['name']) ? $borrower_details['basic']['name'] : '' }}
                            </p>
                        </td>
                    </tr>
                    <tr style="height: 43.85pt;">
                        <td style="width: 252.1pt;" valign="top">
                            <p>Address(s) of the Borrower(s)</p>
                        </td>
                        <td style="width: 252.1pt;" valign="top">
                            <p>{{ !empty($borrower_details['address']['current_address']) ? $borrower_details['address']['current_address'] : '' }}
                            </p>
                        </td>
                    </tr>
                    <tr style="height: 28.3pt;">
                        <td style="width: 252.1pt;" valign="top">
                            <p>Name(s) of the Co-Borrower</p>
                        </td>
                        <td style="width: 252.1pt;" valign="top">
                            <p>{{ !empty($co_applicant_details['basic']['name'])
                                ? $co_applicant_details['basic']['name']
                                : '' }}</p>
                        </td>
                    </tr>
                    <tr style="height: 45.4pt;">
                        <td style="width: 252.1pt;" valign="top">
                            <p>Address(s) of the Co-Borrower(s)</p>
                        </td>
                        <td style="width: 252.1pt;" valign="top">
                            <p>{{ !empty($co_applicant_details['address']['current_address']) ? $co_applicant_details['address']['current_address'] : '' }}
                            </p>
                        </td>
                    </tr>
                    <tr style="height: 41.3pt;">
                        <td style="width: 252.1pt;" valign="top">
                            <p>Name and Address of the Guarantor / Owner of the Asset</p>
                        </td>
                        <td style="width: 252.1pt;" valign="top">
                            <p>Asset Owner:
                                {{ !empty($borrower_details['basic']['name']) ? $borrower_details['basic']['name'] : '' }}
                            </p>
                        </td>
                    </tr>
                    <tr style="height: 36.7pt;">
                        <td style="width: 252.1pt;" valign="top">
                            <p>Place of Business and status of the Borrower(s)(Viz Private Limited Co./ Public Ltd. Co.
                                / Individual / Firm /sole proprietor /HUF etc.)</p>
                        </td>
                        <td style="width: 252.1pt;" valign="top">
                            <p>{{ $borrower_details['address']['current_address'] ?? '' }}</p>
                        </td>
                    </tr>
                    <tr style="height: 20.8pt;">
                        <td style="width: 252.1pt;" valign="top">
                            <p>Age of Borrower</p>
                        </td>
                        <td style="width: 252.1pt;" valign="top">
                            <p>{{ $borrower_details['basic']['age'] ?? '' }} Year</p>
                        </td>
                    </tr>
                    <tr style="height: 22.5pt;">
                        <td style="width: 252.1pt;" valign="top">
                            <p>Purpose of the Loan</p>
                        </td>
                        <td style="width: 252.1pt;" valign="top">
                            <p>Loan for Purchas of {{ $loan_details['purpose_of_loan'] ?? '' }}</p>
                        </td>
                    </tr>
                    <tr style="height: 33.5pt;">
                        <td style="width: 252.1pt;" valign="top">
                            <p>Loan Amount</p>
                        </td>
                        <td style="width: 252.1pt;" valign="top">
                            <p> Rs.
                                {{ !empty($loan_details['loan_amount']) ? $loan_details['loan_amount'] : '______________' }}/-
                            </p>
                        </td>
                    </tr>
                </tbody>
            </table>
    </page>
    <div class="page-break"></div>
    <page>
        <div>

            <table border="1" cellspacing="0" cellpadding="0" width="100%">
                <tbody>
                    <tr style="height: 33.55pt;">
                        <td style="width: 252.1pt;" valign="top">
                            <p>Tenure of the Loan</p>
                        </td>
                        <td style="width: 252.1pt;" valign="top">
                            <p>{{ $loan_details['loan_term'] ?? '' }} Month</p>
                        </td>
                    </tr>
                    <tr style="height: 33.55pt;">
                        <td style="width: 252.1pt;" valign="top">
                            <p>Date by which Conditions Precedent to be fulfilled</p>
                        </td>
                        <td style="width: 252.1pt;" valign="top">
                            <p>NA</p>
                        </td>
                    </tr>
                    <tr style="height: 27.8pt;">
                        <td style="width: 252.1pt;" valign="top">
                            <p>Rate of interest (% per annum)</p>
                        </td>
                        <td style="width: 252.1pt;" valign="top">
                            <p>{{ !empty($loan_details['annual_roi']) ? $loan_details['annual_roi'] : '_____' }} % per
                                annum ({{ $loan_details['interest_type'] ?? 'Flat' }})</p>
                        </td>
                    </tr>
                    <tr style="height: 26.8pt;">
                        <td style="width: 252.1pt;" valign="top">
                            <p>Repayment Schedule *</p>
                            <p>(a) Amount of each installment</p>
                        </td>
                        <td style="width: 252.1pt;" valign="top">
                            <p>Rs. {{ !empty($loan_details['emi']) ? $loan_details['emi'] : '_____' }} /-</p>
                        </td>
                    </tr>
                    <tr style="height: 17.45pt;">
                        <td style="width: 252.1pt;" valign="top">
                            <p>(b) number of installment</p>
                        </td>
                        <td style="width: 252.1pt;" valign="top">
                            <p>{{ $loan_details['loan_term'] ?? '' }} Month</p>
                        </td>
                    </tr>
                    <tr style="height: 17.2pt;">
                        <td style="width: 252.1pt;" valign="top">
                            <p>(c) The first installment shall be paid on or before</p>
                        </td>
                        <td style="width: 252.1pt;" valign="top">
                            <p>{{ $loan_details['emi_start_date'] ?? '' }}</p>
                            <!-- <p>5<sup>th</sup> of March 2025</p> -->
                        </td>
                    </tr>
                    <tr style="height: 29.25pt;">
                        <td style="width: 252.1pt;" valign="top">
                            <p>And the substance installments shall be paid on or before the of every succeeding month:
                            </p>
                        </td>
                        <td style="width: 252.1pt;" valign="top">
                            <p>{{ !empty($loan_details['monthly_emi_date']) ? $loan_details['monthly_emi_date'] : '______' }}
                                th of every month</p>
                        </td>
                    </tr>
                    <tr style="height: 28.95pt;">
                        <td style="width: 252.1pt;" valign="top">
                            <p>EMI END DATE</p>
                        </td>
                        <td style="width: 252.1pt;" valign="top">
                            <p>{{ $loan_details['emi_end_date'] ?? '' }}</p>
                            <!-- <p>5th of August 2026</p> -->
                        </td>
                    </tr>
                    <tr style="height: 16.05pt;">
                        <td style="width: 252.1pt;" valign="top">
                            <p>1 Advance EMI</p>
                        </td>
                        <td style="width: 252.1pt;" valign="top">
                            <p>{{ $loan_details['advance_emi'] ?? '' }}</p>
                        </td>
                    </tr>
                    <tr style="height: 18.4pt;">
                        <td style="width: 252.1pt;" valign="top">
                            <p>Additional interest in case of default</p>
                        </td>
                        <td style="width: 252.1pt;" valign="top">
                            <p>36% per annum</p>
                        </td>
                    </tr>
                    <tr style="height: 85.6pt;">
                        <td style="width: 252.1pt;" valign="top">
                            <p>Electric Vehicle Details:</p>
                            <p>&nbsp;</p>
                            <p>Make and Model:</p>
                            <p>Year of Manufacture:</p>
                            <p>Vehicle Registration Number:</p>
                        </td>
                        <td style="width: 252.1pt;" valign="top">
                            <p>&nbsp;</p>
                            <p>&nbsp;</p>
                            <p>{{ $loan_details['model'] ?? '' }}</p>
                            <p>&nbsp;{{ $loan_details['vehicle_manufacture_year'] ?? '' }}</p>
                            <p>&nbsp;</p>
                            <p>{{ $loan_details['registration_number'] ?? '' }}</p>
                            <p>{{ $loan_details['other_vehicle_numbers'] ?? '' }}</p>
                        </td>
                    </tr>
                    <tr style="height: 18.15pt;">
                        <td style="width: 252.1pt;" valign="top">
                            <p>No. of Post Dated Cheques/ Ach Issued By The Borrower's</p>
                        </td>
                        <td style="width: 252.1pt;" valign="top">
                            <p>e-NACH</p>
                        </td>
                    </tr>
                    <tr style="height: 18.65pt;">
                        <td style="width: 252.1pt;" valign="top">
                            <p>Bouncing Charges</p>
                        </td>
                        <td style="width: 252.1pt;" valign="top">
                            <p>Rs.
                                {{ !empty($loan_details['bouncing_charges']) ? $loan_details['bouncing_charges'] : '_______' }}/-
                                per bounce + GST</p>
                        </td>
                    </tr>
                    <tr style="height: 18.45pt;">
                        <td style="width: 252.1pt;" valign="top">
                            <p>Documentation/Application Fees or Charges</p>
                        </td>
                        <td style="width: 252.1pt;" valign="top">
                            <p>{{ $loan_details['application_charges'] ?? '' }}</p>
                        </td>
                    </tr>
                    <tr style="height: 18.4pt;">
                        <td style="width: 252.1pt;" valign="top">
                            <p>Processing Fee (P.F)</p>
                        </td>
                        <td style="width: 252.1pt;" valign="top">
                            <p>Rs.
                                {{ !empty($loan_details['processing_fee']) ? $loan_details['processing_fee'] : '________' }}/-
                                (incl of GST)
                                <!-- 4,237/- + GST -->
                            </p>
                        </td>
                    </tr>

                </tbody>
            </table>
    </page>
    <div class="page-break"></div>
    <page>
        <div>

            <table border="1" cellspacing="0" cellpadding="0" width="100%">
                <tbody>
                    <tr style="height: 18.4pt;">
                        <td style="width: 252.1pt;" valign="top">
                            <p>Foreclosure/Prepayment Charges</p>
                        </td>
                        <td style="width: 252.1pt;" valign="top">
                            <p>{{ !empty($loan_details['pre_closure_charge']) ? $loan_details['pre_closure_charge'] : '_______' }}%
                                of the principal outstanding amount</p>
                        </td>
                    </tr>
                    <tr style="height: 25.1pt;">
                        <td style="width: 504.2pt;" colspan="2" valign="top">
                            <p>*The Repayment schedule mentioned above is subject to change in the case of floating rate
                                of interest</p>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

    </page>
    <div class="page-break"></div>
    <page>
        <p style="font-weight:bold; text-align: center;"><u>LOAN APPLICATION FORM</u></p>
        <div>
            <p><em>(Please write in BLOCK LETTERS</em><em>, </em><em>Complete the application &amp; help us serve you
                    better)</em></p>
            <p>DATE: ____/____/____</p>
        </div>
        <div>
            <p>I hereby apply to
                {{ !empty($lender_details['company_name']) ? $lender_details['company_name'] : '______________________________________________' }}
                ("the Company") to grant me (EV) Vehicle as mentioned in this application Form, I furnish my particulars
                as mentioned below</p>
            <table border="1" cellspacing="0" cellpadding="0" width="100%">
                <tbody>
                    <tr style="height: 19.65pt;">
                        <td style="width: 432.95pt;" colspan="6" valign="top">
                            <p><strong>E-mail ID</strong>: {{ $borrower_details['basic']['email'] ?? '' }}</p>
                        </td>
                        <td style="width: 80pt;" rowspan="3" valign="top">
                            <!-- <p><strong>PHOTO</strong></p> -->
                            <!-- @if (isset($documents['borrower_avtar']) && !empty($documents['borrower_avtar']))
<img style="-webkit-transform: rotate(90deg);" height="120px" width="120px" src="https://insta.finayo.tech/{{ $documents['borrower_avtar'] }}" alt="">
@endif -->
                            @if (isset($documents['borrower_avtar']) && !empty($documents['borrower_avtar']))
                                @php
                                    // $path = public_path($documents['borrower_avtar']);
                                    // $type = pathinfo($path, PATHINFO_EXTENSION);
                                    // $data = file_get_contents($path);
                                    $base64 = ''; //'data:image/' . $type . ';base64,' . base64_encode($data);
                                @endphp
                                <img style="" height="120px" width="120px" src="{{ $base64 }}"
                                    alt="">
                            @endif
                            <!-- <p><strong>with </strong><strong>cross </strong><strong>signature</strong></p> -->
                        </td>
                    </tr>
                    <tr style="height: 14.8pt;">
                        <td style="width: 432.95pt;" colspan="6" valign="top">
                            <p><strong>Location</strong>: {{ $borrower_details['address']['current_address'] ?? '' }}
                            </p>
                        </td>
                    </tr>
                    <tr style="height: 19.15pt;">
                        <td style="width: 432.95pt;" colspan="6" valign="top">
                            <p><strong>Borrower Name:</strong> {{ $borrower_details['basic']['name'] ?? '' }}</p>
                        </td>
                    </tr>
                    <tr style="height: 47.45pt;">
                        <td style="width: 272.55pt;" colspan="4" valign="top">
                            <p><strong>Gender</strong>: {{ $borrower_details['basic']['gender'] ?? '' }}
                                </br>
                                <strong> Date of Birth: </strong> {{ $borrower_details['basic']['dob_date'] ?? '' }}
                            </p>
                        </td>
                        <td style="width: 160.4pt;" colspan="3" valign="top">
                            <p><strong>Mobile Phone</strong>: {{ $borrower_details['basic']['mobile'] ?? '' }} </p>
                        </td>
                    </tr>
                    <tr style="height: 22.05pt;">
                        <td style="width: 501.15pt;" colspan="7" valign="top">
                            <p><strong>Mother Name</strong>: </br>
                                <strong>Marital Status</strong>:
                                {{ $borrower_details['basic']['marital_status'] ?? '' }}
                            </p>
                        </td>
                    </tr>
                    <tr style="height: 17.25pt;">
                        <td style="width: 501.15pt;" colspan="7" valign="top">
                            <p><strong>Father's Name</strong>: {{ $borrower_details['basic']['father_name'] ?? '' }}
                                <br>
                                <strong>Spouse Name</strong>: {{ $borrower_details['basic']['husband_name'] ?? '' }}
                                <br>
                                <strong>Citizenship</strong>: INDIAN
                            </p>
                        </td>
                    </tr>
                    <tr style="height: 15.55pt;">
                        <td style="width: 272.55pt;" colspan="2" valign="top">
                            <p><strong>PAN Card No.</strong>: {{ $borrower_details['basic']['pan'] ?? '' }}</p>
                        </td>
                        <td style="width: 228.6pt;" colspan="5" valign="top">
                            <p><strong>Annual Gross Salary</strong>: Rs.
                                {{ $borrower_details['occupation']['total_income'] ?? '_______________' }}/-</p>
                        </td>
                    </tr>
                    <tr style="height: 15.05pt;">
                        <td style="width: 501.15pt;" colspan="7" valign="top">
                            <p><strong>Residential Status</strong>: Resident Individual</p>
                        </td>
                    </tr>
                    <tr style="height: 14.85pt;">
                        <td style="width: 501.15pt;" colspan="7" valign="top">
                            <p><strong>Property Status</strong>:
                                {{ $borrower_details['address']['resident_type'] ?? '' }}</p>
                        </td>
                    </tr>
                    <tr style="height: 15.05pt;">
                        <td style="width: 501.15pt;" colspan="7" valign="top">
                            <p><strong>Occupation </strong>:
                                {{ $borrower_details['occupation']['occupation_type'] ?? '' }}</p>
                        </td>
                    </tr>
                    <tr style="height: 14.15pt;">
                        <td style="width: 501.15pt;" colspan="7" valign="top">
                            <p>Nature of Business:</p>
                        </td>
                    </tr>
                    <tr style="height: 46.05pt;">
                        <td style="width: 386.85pt;" colspan="5" valign="top">
                            <p>Bank Name: {{ $borrower_details['bank']['bank_name'] ?? '' }}
                                <br> Branch Name: {{ $borrower_details['bank']['branch_name'] ?? '' }}

                            </p>
                        </td>
                        <td style="width: 114.3pt;" colspan="2" valign="top">
                            <p>Account Type:</p>
                        </td>
                    </tr>
                    <tr style="height: 29.95pt;">
                        <td style="width: 272.55pt;" colspan="2" valign="top">
                            <p>Account No.: {{ $borrower_details['bank']['account_number'] ?? '' }}
                                <br>
                                IFSC CODE: {{ $borrower_details['bank']['ifsc'] ?? '' }}
                            </p>
                        </td>
                        <td style="width: 114.3pt;" colspan="3" valign="top">
                            <p>Account&nbsp; Opening Date:</p>
                        </td>
                        <td style="width: 114.3pt;" colspan="2" valign="top">
                            <p>&nbsp;</p>
                        </td>
                    </tr>
                </tbody>
            </table>

    </page>
    <div class="page-break"></div>
    <page size="A4" style="padding: 10px;">
        <table border="1" cellspacing="0" cellpadding="0" width="100%">
            <tbody>
                <tr style="height: 33.6pt;">
                    <td style="width: 103.75pt;" rowspan="4" valign="top">
                        <p>&nbsp;</p>
                        <p>&nbsp;</p>
                        <p>PRESENT &nbsp;ADDRESS</p>
                    </td>
                    <td style="width: 397.4pt;" colspan="6" valign="top">
                        <p>Address Line I: {{ $borrower_details['address']['current_address'] ?? '' }}</p>
                    </td>
                </tr>
                <tr style="height: 41.25pt;">
                    <td style="width: 397.4pt;" colspan="6" valign="top">
                        <p>Address Line II:</p>
                    </td>
                </tr>
                <tr style="height: 44.35pt;">
                    <td style="width: 211.8pt;" colspan="3" valign="top">
                        <p>City: {{ $borrower_details['address']['city'] ?? '' }}</p>
                        <p>District: {{ $borrower_details['address']['district'] ?? '' }}</p>
                    </td>
                    <td style="width: 185.6pt;" colspan="3" valign="top">
                        <p>State: {{ $borrower_details['address']['state'] ?? '' }}</p>
                        <p>COUNTRY: INDIA</p>
                    </td>
                </tr>
                <tr style="height: 41.5pt;">
                    <td style="width: 211.8pt;" colspan="3" valign="top">
                        <p>Nearest Landmark: {{ $borrower_details['address']['landmark'] ?? '' }}</p>
                    </td>
                    <td style="width: 185.6pt;" colspan="3" valign="top">
                        <p>Pin Code: {{ $borrower_details['address']['pincode'] ?? '' }}</p>
                    </td>
                </tr>
                <tr style="height: 7.35pt;">
                    <td style="width: 103.75pt;" rowspan="" valign="top">
                        <p>Present Property Status:</p>
                    </td>
                    <td style="width: 201.35pt;" colspan="3" valign="top">
                        <p>{{ $borrower_details['address']['resident_type'] ?? '' }}</p>
                    </td>
                    <td style="width: 185.6pt;" colspan="3" rowspan="" valign="top">
                        <p>Years in Current Address: {{ $borrower_details['address']['years_living'] ?? '' }}</p>
                    </td>
                </tr>
                <tr style="height: 38.6pt;">
                    <td style="width: 103.75pt;" rowspan="2" valign="top">
                        <p>&nbsp;</p>
                        <p>PERMANENT</p>
                    </td>
                    <td style="width: 397.4pt;" colspan="6" valign="top">
                        <p>Address Line I: {{ $borrower_details['address']['permanent_address'] ?? '' }}</p>
                    </td>
                </tr>
                <tr style="height: 12.9pt;">
                    <td style="width: 397.4pt;" colspan="6" valign="top">
                        <p>Address Line II:</p>
                    </td>
                </tr>
            </tbody>
        </table>
        <table border="1" cellspacing="0" cellpadding="0" width="100%">
            <tbody>
                <tr style="height: 38.6pt;">
                    <td style="width: 103.75pt;" colspan="2" rowspan="3" valign="top">
                        <p>ADDRESS</p>
                    </td>
                    <td style="width: 397.4pt;" colspan="6" valign="top">
                        <p>&nbsp;</p>
                    </td>
                </tr>
                <tr style="height: 38.35pt;">
                    <td style="width: 211.75pt;" valign="top">
                        <p>City:{{ $borrower_details['address']['p_city'] ?? '' }}</p>
                        <p>District:{{ $borrower_details['address']['p_district'] ?? '' }}</p>
                    </td>
                    <td style="width: 185.65pt;" colspan="5" valign="top">
                        <p>State:{{ $borrower_details['address']['p_state'] ?? '' }}</p>
                        <p>Country: India</p>
                    </td>
                </tr>
                <tr style="height: 54.9pt;">
                    <td style="width: 211.75pt;" valign="top">
                        <p>Nearest Landmark: {{ $borrower_details['address']['landmark'] ?? '' }}</p>
                    </td>
                    <td style="width: 185.65pt;" colspan="5" valign="top">
                        <p>Pin code: {{ $borrower_details['address']['p_pincode'] ?? '' }}</p>
                    </td>
                </tr>
                <tr style="height: 22.25pt;">
                    <td style="width: 332.8pt;" colspan="8" valign="top">
                        <p><strong>Property Status</strong></p>
                        <p><strong>Property Status</strong>:{{ $borrower_details['address']['resident_type'] ?? '' }}
                        </p>
                    </td>
                </tr>
                <tr style="height: 75.7pt;">
                    <td style="width: 501.15pt;" colspan="8" valign="top">
                        <p><strong>Applicant Thumb Impression (RIGHT OR LEFT) OR Signature</strong></p>
                    </td>
                </tr>
            </tbody>
        </table>
    </page>
    <div class="page-break"></div>
    <page size="A4" style="padding: 10px;">

        <p style="font-weight:bold; text-align: center;">CO-BORROWER DETAILS</p>
        <table border="1" cellspacing="0" cellpadding="0" width="100%">
            <tbody>
                <tr style="height: 16.85pt;">
                    <td style="width: 432.95pt;" colspan="7" valign="top">
                        <p><strong>CO-BORROWER</strong><strong> </strong><strong>DETAILS</strong></p>
                    </td>
                    <td style="width: 80;" rowspan="4" valign="top">
                        <!-- <p>PHOTO</p> -->
                        <!-- @if (isset($documents['coapplicant_avtar']) && !empty($documents['coapplicant_avtar']))
<img style="-webkit-transform: rotate(90deg);" height="120px" width="120px"
                    src="https://insta.finayo.tech/{{ $documents['coapplicant_avtar'] }}"
                    alt="">
@endif -->
                        @if (isset($documents['coapplicant_avtar']) && !empty($documents['coapplicant_avtar']))
                            @php
                                // $path = public_path($documents['coapplicant_avtar']);
                                // $type = pathinfo($path, PATHINFO_EXTENSION);
                                // $data = file_get_contents($path);
                                $base64 = ''; //'data:image/' . $type . ';base64,' . base64_encode($data);
                            @endphp
                            <img style="" height="120px" width="120px" src="{{ $base64 }}"
                                alt="">
                        @endif
                        <!-- <p>with cross Signature</p> -->
                    </td>
                </tr>
                <tr style="height: 8.65pt;">
                    <td style="width: 90.65pt;" valign="top">
                        <p>Co-Borrower</p>
                    </td>
                    <td style="width: 342.3pt;" colspan="6" rowspan="2" valign="top">
                        <p>{{ $co_applicant_details['basic']['name'] ?? '' }}</p>
                    </td>
                </tr>
                <tr style="height: 15.75pt;">
                    <td style="width: 90.65pt;" valign="top">
                        <p>Name: </p>
                    </td>
                </tr>
                <tr style="height: 37.0pt;">
                    <td style="width: 432.95pt;" colspan="7" valign="top">
                        <p>Parent's/Spouse Name: {{ $co_applicant_details['basic']['father_name'] ?? '' }}</p>
                    </td>
                </tr>
                <tr style="height: 20.05pt;">
                    <td style="width: 432.95pt;" colspan="7" valign="top">
                        <p>Mobile No:
                            {{ isset($co_applicant_details['basic']['mobile']) ? $co_applicant_details['basic']['mobile'] : '' }}
                        </p>
                    </td>
                    <td style="width: 68.2pt;" rowspan="" valign="top">
                        <p>&nbsp;</p>
                    </td>
                </tr>

                <tr style="height: 15.3pt;">
                    <td style="width: 103.75pt;" colspan="2" valign="top">
                        <p>PRESENT</p>
                        <p>ADDRESS</p>
                    </td>
                    <td style="width: 329.2pt;" colspan="6" valign="top">
                        <p>Address Line I:
                            {{ isset($co_applicant_details['address']['permanent_address']) ? $co_applicant_details['address']['permanent_address'] : '' }}
                        </p>
                    </td>
                </tr>
                <tr style="height: 2.85pt;">
                    <td style="width: 68.2pt;" colspan="2" valign="top">
                        <p>&nbsp;</p>
                    </td>
                    <td style="width: 329.2pt;" colspan="6" valign="top">
                        <p>Address Line II:</p>
                    </td>
                </tr>
                <tr style="height: 11.2pt;">
                    <td style="width: 103.75pt;" colspan="2" valign="top">
                        <p>&nbsp;</p>
                    </td>
                    <td style="width: 229.05pt;" colspan="6" valign="top">
                        <p>
                            City:
                            {{ isset($co_applicant_details['address']['city']) ? $co_applicant_details['address']['city'] : '' }}
                            <br>
                            District:
                            {{ isset($co_applicant_details['address']['district']) ? $co_applicant_details['address']['district'] : '' }}
                            <br>
                            State:
                            {{ isset($co_applicant_details['address']['state']) ? $co_applicant_details['address']['state'] : '' }}
                            <br>
                            Country: INDIA
                        </p>
                    </td>

                </tr>
                <tr style="height: 23.35pt;">
                    <td style="width: 103.75pt;" colspan="2" valign="top">
                        <p>&nbsp;</p>
                    </td>
                    <td style="width: 397.4pt;" colspan="6" valign="top">
                        <p>Nearest Landmark:
                            {{ isset($co_applicant_details['address']['landmark']) ? $co_applicant_details['address']['landmark'] : '' }}
                        </p>
                        <p>Pin code:
                            {{ isset($co_applicant_details['address']['pincode']) ? $co_applicant_details['address']['pincode'] : '' }}
                        </p>
                        <p>Yrs. In Current Address:
                            {{ isset($co_applicant_details['address']['years_living']) ? $co_applicant_details['address']['years_living'] : '' }}
                        </p>
                    </td>
                </tr>

                <tr style="height: 74.1pt;">
                    <td style="width: 332.8pt;" colspan="8" valign="top">
                        <p><strong>Co-Borrower Thumb Impression (RIGHT OR LEFT) OR Signature</strong></p>
                        <p>&nbsp;</p>
                        <p>&nbsp;</p>
                        <p style="display: flex; justify-content: end; margin-right: 13px;"><strong>Co-Applicant
                                Signature</strong></p>
                    </td>
                </tr>
            </tbody>
        </table>
        <p>&nbsp;</p>
        <p>Note:
            {{ !empty($lender_details['company_name']) ? $lender_details['company_name'] : '______________________________________________' }}
            shall be entitled at its sole and absolution discretion to approve/reject this Application Form submitted by
            Applicant/ Co-applicant.</p>
        <p>&nbsp;</p>
        <p>*The EV Vehicle Loan Is available without other offering.</p>
        </div>
    </page>
    <div class="page-break"></div>
    <page size="A4" style="padding: 10px;">
        <div>
            <p style="font-weight:bold; text-align: center;">GUARANTOR DETAILS</p>

            <table border="1" cellspacing="0" cellpadding="0" width="100%">
                <tbody>
                    <tr style="height: 19.4pt;">
                        <td style="width: 427.75pt;" colspan="5" valign="top">
                            <p><strong>E-mail ID</strong>:
                                {{ isset($guarantor_details['basic']['email']) ? $guarantor_details['basic']['email'] : '' }}
                            </p>
                        </td>
                        <td style="width: 190px;" rowspan="4" valign="top">
                            <!-- <p><strong>&nbsp;</strong></p> -->
                            <!-- <p><strong>&nbsp;</strong></p> -->
                            <!-- <p style="text-align: center;"><strong>PHOTO</strong></p> -->
                            <!-- <p style="text-align: center;"><strong>with </strong><strong>cross </strong><strong>signature</strong></p> -->
                            <!-- @if (isset($documents['guarantor_avtar']) && !empty($documents['guarantor_avtar']))
<img style="-webkit-transform: rotate(0deg);" height="120px" width="120px" src="https://insta.finayo.tech/{{ $documents['guarantor_avtar'] }}" alt="">
@endif
              @if (isset($documents['guarantor_avtar']) && !empty($documents['guarantor_avtar']))
@php
    // $path = public_path($documents['guarantor_avtar']);
    // $type = pathinfo($path, PATHINFO_EXTENSION);
    // $data = file_get_contents($path);
    $base64 = ''; //'data:image/' . $type . ';base64,' . base64_encode($data);
@endphp
                <img style="" height="120px" width="120px" src="{{ $base64 }}" alt="">
@endif -->
                        </td>
                    </tr>
                    <tr style="height: 15.05pt;">
                        <td style="width: 427.75pt;" colspan="5" valign="top">
                            <p><strong>Location:
                                </strong>{{ isset($guarantor_details['address']['current_address']) ? $guarantor_details['address']['current_address'] : '' }}
                            </p>
                        </td>
                    </tr>
                    <tr style="height: 18.9pt;">
                        <td style="width: 427.75pt;" colspan="5" valign="top">
                            <p><strong>Borrower Name:</strong>
                                {{ isset($guarantor_details['basic']['name']) ? $guarantor_details['basic']['name'] : '' }}
                            </p>
                        </td>
                    </tr>
                    <tr style="height: 47.5pt;">
                        <td style="width: 272.55pt;" colspan="2" valign="top">
                            <p><strong>Gender</strong>:
                                {{ isset($guarantor_details['basic']['gender']) ? $guarantor_details['basic']['gender'] : '' }}
                                <br>Date of Birth:
                                </strong>{{ isset($guarantor_details['basic']['dob_date']) ? $guarantor_details['basic']['dob_date'] : '' }}
                            </p>
                        </td>
                        <td style="width: 155.2pt;" colspan="3" valign="top">
                            <p><strong>Mobile Phone</strong>:
                                {{ isset($guarantor_details['basic']['mobile']) ? $guarantor_details['basic']['mobile'] : '' }}
                            </p>
                        </td>
                    </tr>
                    <tr style="height: 22.25pt;">
                        <td style="width: 501.25pt;" colspan="6" valign="top">
                            <p><strong>Mother Name</strong>:
                                <br><strong>Marital Status</strong>:
                                {{ isset($guarantor_details['basic']['marital_status']) ? $guarantor_details['basic']['marital_status'] : '' }}
                            </p>
                        </td>
                    </tr>
                    <tr style="height: 17.0pt;">
                        <td style="width: 501.25pt;" colspan="6" valign="top">
                            <p>
                                <strong>Father's Name</strong>:
                                {{ isset($guarantor_details['basic']['father_name']) ? $guarantor_details['basic']['father_name'] : '' }}
                                <br>
                                <strong>Spouse Name</strong>:
                                {{ isset($guarantor_details['basic']['husband_name']) ? $guarantor_details['basic']['husband_name'] : '' }}
                                <br>
                                <strong>Citizenship</strong>: INDIAN
                            </p>
                        </td>
                    </tr>
                    <tr style="height: 15.55pt;">
                        <td style="width: 272.55pt;" colspan="2" valign="top">
                            <p><strong>PAN Card No.</strong>:
                                {{ isset($guarantor_details['basic']['pan']) ? $guarantor_details['basic']['pan'] : '' }}
                            </p>
                        </td>
                        <td style="width: 228.7pt;" colspan="4" valign="top">
                            <p><strong>Annual Gross Salary</strong>:
                                {{ isset($guarantor_details['address']['total_income']) ? $guarantor_details['address']['total_income'] : '' }}
                            </p>
                        </td>
                    </tr>
                    <tr style="height: 15.1pt;">
                        <td style="width: 501.25pt;" colspan="6" valign="top">
                            <p><strong>Residential Status</strong>: Resident Individual</p>
                        </td>
                    </tr>
                    <tr style="height: 15.05pt;">
                        <td style="width: 501.25pt;" colspan="6" valign="top">
                            <p><strong>Property Status</strong>:
                                {{ isset($guarantor_details['address']['resident_type']) ? $guarantor_details['address']['resident_type'] : '' }}
                            </p>
                        </td>
                    </tr>
                    <tr style="height: 14.8pt;">
                        <td style="width: 501.25pt;" colspan="6" valign="top">
                            <p><strong>Occupation</strong>:
                                {{ isset($guarantor_details['address']['occupation_type']) ? $guarantor_details['address']['occupation_type'] : '' }}
                            </p>
                        </td>
                    </tr>
                    <tr style="height: 14.1pt;">
                        <td style="width: 501.25pt;" colspan="6" valign="top">
                            <p>Nature of Business:</p>
                        </td>
                    </tr>
                    <tr style="height: 46.3pt;">
                        <td style="width: 386.9pt;" colspan="4" valign="top">
                            <p>Bank
                                Name:&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
                                Branch Name:</p>
                        </td>
                        <td style="width: 114.35pt;" colspan="2" valign="top">
                            <p>Account Type:</p>
                        </td>
                    </tr>
                    <tr style="height: 29.95pt;">
                        <td style="width: 272.55pt;" colspan="2" valign="top">
                            <p>Account No.:
                                <br>
                                IFSC CODE:
                            </p>
                        </td>
                        <td style="width: 114.35pt;" colspan="2" valign="top">
                            <p>Account Opening Date: <br></p>
                        </td>
                        <td style="width: 114.35pt;" colspan="2" valign="top">
                            <p>&nbsp;</p>
                        </td>
                    </tr>
                    <tr style="height: 33.8pt;">
                        <td style="width: 103.75pt;" rowspan="3" valign="top">
                            <p><strong>&nbsp;</strong></p>
                            <p><strong>&nbsp;</strong></p>
                            <p>PRESENT &nbsp;ADDRESS</p>
                        </td>
                        <td style="width: 397.5pt;" colspan="5" valign="top">
                            <p>Address Line I:
                                {{ isset($guarantor_details['address']['current_address']) ? $guarantor_details['address']['current_address'] : '' }}
                            </p>
                        </td>
                    </tr>
                    <tr style="height: 41.25pt;">
                        <td style="width: 397.5pt;" colspan="5" valign="top">
                            <p>Address Line II:</p>
                        </td>
                    </tr>
                    <tr style="height: 35.25pt;">
                        <td style="width: 211.8pt;" colspan="2" valign="top">
                            <p>City:
                                {{ isset($guarantor_details['address']['city']) ? $guarantor_details['address']['city'] : '' }}
                            </p>

                            <p>District:
                                {{ isset($guarantor_details['address']['district']) ? $guarantor_details['address']['district'] : '' }}
                            </p>
                        </td>
                        <td style="width: 185.7pt;" colspan="3" valign="top">
                            <p>State:
                                {{ isset($guarantor_details['address']['state']) ? $guarantor_details['address']['state'] : '' }}
                            </p>
                            <p>COUNTRY: INDIA</p>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </page>
    <div class="page-break"></div>
    <page size="A4" style="padding: 10px;">
        <div>
            <table border="1" cellspacing="0" cellpadding="0" width="100%">
                <tbody>

                    <tr style="height: 41.7pt;">
                        <td style="width: 211.8pt;" colspan="3" valign="top">
                            <p>Nearest Landmark:
                                {{ isset($guarantor_details['address']['landmark']) ? $guarantor_details['address']['landmark'] : '' }}
                            </p>
                        </td>
                        <td style="width: 185.7pt;" colspan="3" valign="top">
                            <p>Pin Code:
                                {{ isset($guarantor_details['address']['pincode']) ? $guarantor_details['address']['pincode'] : '' }}
                            </p>
                        </td>
                    </tr>
                    <tr style="height: 41.7pt;">
                        <td style="width: 211.8pt;" colspan="3" valign="top">
                            <p>Present Property Status:
                                {{ isset($guarantor_details['address']['resident_type']) ? $guarantor_details['address']['resident_type'] : '' }}
                            </p>
                        </td>

                        <td style="width: 185.7pt;" colspan="3" valign="top">
                            <p>Years in Current Address:
                                {{ isset($guarantor_details['address']['years_living']) ? $guarantor_details['address']['years_living'] : '' }}
                            </p>
                        </td>
                    </tr>
                    <tr style="height: 38.6pt;">
                        <td style="width: 103.75pt;" rowspan="2" valign="top">
                            <p><strong>&nbsp;</strong></p>
                            <p>PERMANENT ADDRESS</p>
                        </td>
                        <td style="width: 397.5pt;" colspan="5" valign="top">
                            <p>Address Line I:
                                {{ isset($guarantor_details['address']['permanent_address']) ? $guarantor_details['address']['permanent_address'] : '' }}
                            </p>
                        </td>
                    </tr>
                    <tr style="height: 64.55pt;">
                        <td style="width: 397.5pt;" colspan="5" valign="top">
                            <p>Address Line II:</p>
                        </td>
                    </tr>

                </tbody>
            </table>
            <table border="1" cellspacing="0" cellpadding="0" width="100%">
                <tbody>
                    <tr style="height: 30.65pt;">
                        <td style="width: 103.75pt;" rowspan="2" valign="top">
                            <p>&nbsp;</p>
                        </td>
                        <td style="width: 211.75pt;" valign="top">
                            <p>City:
                                {{ isset($guarantor_details['address']['p_city']) ? $guarantor_details['address']['p_city'] : '' }}
                            </p>
                            <p>District:
                                {{ isset($guarantor_details['address']['p_district']) ? $guarantor_details['address']['p_district'] : '' }}
                            </p>
                        </td>
                        <td style="width: 185.6pt;" valign="top">
                            <p>State:
                                {{ isset($guarantor_details['address']['p_state']) ? $guarantor_details['address']['p_state'] : '' }}
                            </p>
                            <p>Country: INDIA</p>
                        </td>
                    </tr>
                    <tr style="height: 54.95pt;">
                        <td style="width: 211.75pt;" valign="top">
                            <p>Nearest Landmark:
                                {{ isset($guarantor_details['address']['landmark']) ? $guarantor_details['address']['landmark'] : '' }}
                            </p>
                        </td>
                        <td style="width: 185.6pt;" valign="top">
                            <p>Pin code:
                                {{ isset($guarantor_details['address']['p_pincode']) ? $guarantor_details['address']['p_pincode'] : '' }}
                            </p>
                        </td>
                    </tr>
                    <tr style="height: 29.45pt;">
                        <td style="width: 501.1pt;" colspan="3" valign="top">
                            <p><strong>&nbsp;</strong></p>
                            <p>Property Status:
                                {{ isset($guarantor_details['address']['resident_type']) ? $guarantor_details['address']['resident_type'] : '' }}
                            </p>
                        </td>
                    </tr>
                    <tr style="height: 92.85pt;">
                        <td style="width: 501.1pt;" colspan="3" valign="top">
                            <p><strong>&nbsp;</strong></p>
                            <p><strong>Guarantor Thumb Impression (RIGHT OR LEFT) OR Signature</strong></p>
                        </td>
                    </tr>
                </tbody>
            </table>
    </page>
    <div class="page-break"></div>
    <page size="A4" style="padding: 10px;">
        <p style="text-align: center; font-weight: bold;">IRREVOCABLE POWER ATTORNEY</p>
        <p><strong>&nbsp;</strong></p>
        <p>To all whom these present shall come I,________________________________________________________, residing at
        </p>_________________________________________________________________________________________ </u>(hereinafter
        called the "Borrower&rdquo; which expression shall unless repugnant to the context or meaning thereof, shall
        mean and include their heirs, successors, assigns, legal representatives, executors, administrators, survivors,
        nominees and representatives in interest.)</p>
        <p>&nbsp;</p>
        <p>Whereas
            <strong>{{ !empty($lender_details['company_name']) ? $lender_details['company_name'] : '______________________________________________' }}</strong>,
            a company incorporated under the Provisions of companies Act, 1956, having place of business at Regd.
            Office:
            {{ !empty($lender_details['address']) ? $lender_details['address'] : '_________________________________________________________________________________' }}
            (hereinafter called the "Lender") has sanctioned me, a sum of Rs.______________________/- (Rupees
            ______________________________________________________________________________) by way of loan for EV
            vehicle, against security of the vehicle (hereinafter called the "vehicle")
        </p>

        <p>AND WHEREAS I/We have executed a Vehicle Loan Cum Hypothecation Agreement No.___________________________dated
            ___________________, and also Guarantee dated ______________________</u>, for the aforesaid purpose and have
            agreed that I/We shall execute in favour of the Lender an irrevocable Power of Attorney being these present
            authorizing the Lender to deal with the vehicle described in the said Vehicle Loan cum Hypothecation
            Agreement (or in the Schedule to the said agreement or in any other communication relating thereto) and
            exercise all rights in respect thereof in the manner hereinafter appearing.</p>

        <p>NOW KNOW WE ALL THESE PRESENT WITNESS that we do hereby irrevocably and absolutely nominate constitute Lender
            acting through any of its officers as my/our true and lawful attorney for me/us on my/our behalf and at
            my/our cost and risk to do, execute and perform all or any of the following acts, deeds, matters and things
            that is to say:</p>

        <ol>
            <li>To take delivery and possession of the vehicle as and when deemed necessary by the Lender.</li>
        </ol>

        <ol start="2">
            <li>To transfer, sell, dispose of, give delivery of and otherwise howsoever deal with the vehicle and to
                sign and execute all Agreements, contracts, declaration forms, Instruments and other writings whatsoever
                as may be necessary or expedient in that behalf.</li>
        </ol>
        <ol start="3">
            <li>To appoint or, engage any broker or other agent for effecting any such transfer, sale, disposition,
                realisation, delivery of or dealing with the vehicle as the case may be.</li>
        </ol>
        <ol start="4">
            <li>To give notice to appropriate authority for registration of the vehicle upon the sale, transfer,
                disposal of, delivery thereof.</li>
        </ol>
        <ol start="5">
            <li>To receive consideration by sale, transfer, disposition or delivery of or dealing with the vehicle and
                to give proper receipt and valid effectual discharge for the same.</li>
        </ol>
        <ol start="6">
            <li>To orally, in writing or otherwise, hypothecate the vehicle in favour of the Lender on the terms &amp;
                conditions contained in the Loan cum Hypothecation Agreement entered into between myself/ourselves and
                the Lender or on such other terms as the Lender may think fit.</li>
        </ol>
        <ol start="7">
            <li>To appear before the office of Registering Authority and any other authorities through advocates or any
                authorized persons deemed necessary by the Lender to effect endorsement of hypothecation in Registration
                certificate and transfer the Vehicle.</li>
        </ol>

        <ol start="8">
            <li>In the event of the lender deciding to recall the Loan amount for any reason whatsoever, the Lender
                would have the irrevocable authority to cancel the booking of the vehicle with the manufacturer and to
                receive the booking amount any other deposit with the dealer/ manufacturer and also to utilize the same
                towards discharging my/our debt towards the lender.</li>
        </ol>
    </page>
    <div class="page-break"></div>
    <page size="A4" style="padding: 10px;">
        <ol start="9">
            <li>To receive the insurance receivable marked in favour of the Lender.</li>
        </ol>

        <ol start="10">
            <li>To Fill in, amend or complete such forms, documents or papers that would be lying with the Lender duly
                signed to give full and complete affect thereof.</li>
        </ol>

        <ol start="11">
            <li>To fill in and complete any cheque that may be lying now or hereafter with the Lender duly signed by
                me/us with such amount, data and/or name of the payee that may be deemed fit by the Lender.</li>
        </ol>

        <ol start="12">
            <li>To delegate all/any or more than one of the powers, authorities and liberties herein vested and to
                appoint any substitutes to anyone or more purpose or purposes as the Lender shall from time to time
                desire in that behalf.</li>
        </ol>

        <ol start="13">
            <li>To appear before any Court, Tribunal or Authority to commence, institute, maintain, prosecute, defend
                any proceeding(s) relating to or connected with the recovery of the dues/outstanding.</li>
        </ol>

        <ol start="14">
            <li>To take on superdari to vehicle from any Court. Tribunal or Authority.</li>
        </ol>

        <ol start="15">
            <li>To appoint Advocate(s), to sign and verify pleadings, documents, memorandum, petition and all such
                documents that are required to be filled before any Court, Tribunal or Authority.</li>
        </ol>

        <ol start="16">
            <li>And generally to do perform and execute all acts, deeds, matters, and things relating to or concerning
                to touching the vehicle and these presents as fully and effectually as if we/ were personally present
                and the acts have been done performed or executed by me/us.</li>
        </ol>

        <ol start="17">
            <li>And I/we hereby agree to ratify and confirm all whatsoever the Lender shall do or course to be done in
                all about the premises by virtue of these presents.</li>
        </ol>

        <ol start="18">
            <li>I/We undertake to defray and reimburse all costs and expenses that the attorney may incure in carrying
                out these presents.</li>
        </ol>

        <ol start="19">
            <li>This power of attorney has been issued for consideration and shall therefore remain effective, valid and
                irrevocable till the loan granted by the Lender-has been fully realised to the satisfaction of the
                Lender and a Certificate of Discharge to that effect has been issued by the Lender.</li>
        </ol>
        <p>&nbsp;</p>
        <p>In witness whereof I/we here unto set my/our hand/ Seal at _________________on this ______________ day of
            _______________________.</p>

    </page>
    <div class="page-break"></div>
    <page size="A4" style="padding: 10px;">
        <div>
            <p style="text-align: center;"><u>LETTER OF GUARANTEE</u></p>
            <p>In consideration of
                <strong>&ldquo;{{ !empty($lender_details['company_name']) ? $lender_details['company_name'] : '______________________________________________' }}</strong>&rdquo;
                a company registered under the Indian Companies Act, 1956/2013, having its registered Office at
                {{ !empty($lender_details['address']) ? $lender_details['address'] : '_________________________________________________________________________________' }}
                (hereinafter referred as
                {{ !empty($lender_details['company_short']) ? $lender_details['company_short'] : '______________' }}
                or Lender&rdquo;) entering into loan agreement number................................... With
                Mr/Ms.......................................................................... s/o
                ............................................................................. residing at
                .........................................................................................................&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;
                Called the &ldquo;Borrower&rdquo; in respect of
                the...................................................................................... more fully
                described in the Schedule to the above mentioned agreement.
            </p>

            <p>I, Mr./Ms.
                &hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;s/o&nbsp;&nbsp;&nbsp;
                &nbsp;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;,&nbsp;&nbsp;&nbsp;
                &nbsp;residing at
                &hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;......................................................................&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;
                hereby called as &ldquo;Guarantor&rdquo; do hereby undertake and guarantee personally and from out of my
                personal assets , the due and punctual payment of at and every sum payable by the Borrower under the
                said loan agreement and the due performance and observance by the Borrower of all stipulations and
                conditions contained therein and do further agree to pay Lender all the sums whichfrom time to time may
                become due to the Lender under the said agreement and such amount shall not exceed</p>
            <p>&nbsp;</p>
            <p>Rs........................................................................................................
                /-</p>
            <p>(Rupees&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;
            </p>
            <p>&hellip;&hellip;&hellip;&hellip;.).</p>
            <p>&nbsp;</p>
            <p>I , further agree that this guarantee shall be a continuing and irrevocable guarantee until the loan is
                fully repaid and shall not be avoided,released or affected by Lender giving time to borrower for payment
                of any sums due or making any variation by Lender and the Borrowerin terms of the said agreement.All the
                rights of Lender shall remain in full force, notwithstanding any neglect or forbearance or delay in the
                enforcement by Lender of any &ldquo;Of the terms of the aforesaid agreement with the Borrower. It is
                agreed that only in the event of the said agreement being completely fulfilled by the Borrower, I shall
                be entitled to obtain only by demand from Lender a full and complete discharge of all my obligations
                under the terms and conditions of the Guarantee. The Guarantee contained herein shall not be affected by
                any change in theconstitution of Lender orof the Borrower.</p>

            <p>The Expression &ldquo;Lender&rdquo;,&rdquo; The Borrower&rdquo; and the &ldquo;Guarantor&rdquo; shall
                include wherever the context so admits, or means, their respective successors, assigned administrators,
                executors and legal representatives.</p>

            <p>IN WITNESS WHERE OF,
                I&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;.,
                Signed the Guarantee at
                &hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;on
                this day &hellip;&hellip;&hellip;.of
                &hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;,
                20&hellip;&hellip;</p>

            <p>Signature Name and Address</p>
        </div>
    </page>
    <div class="page-break"></div>
    <page size="A4" style="padding: 10px;">
        <div>
            <p style="text-align: center;"><strong><u>DEMAND PROMISSORY NOTE</u></strong></p>
            <p><strong>&nbsp;</strong></p>
            <p><strong>RS.................................................. /-</strong></p>
            <p><strong>&nbsp;</strong></p>
            <p>ON &nbsp;DEMAND, &nbsp;I/We &nbsp;Mr.
                &nbsp;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;.
                &nbsp;S/o................................................ severally and or jointly</p>
            <p>unconditionally promise to pay
                {{ !empty($lender_details['company_name']) ? $lender_details['company_name'] : '______________________________________________' }},
                a company registered&nbsp; under&nbsp; the Companies Act 1956/2013 and having its registered office at
                {{ !empty($lender_details['address']) ? $lender_details['address'] : '_________________________________________________________________________________' }}
                INDIA, on its order at its office mentioned above, the sum of
                Rs...................................................................................................................................
                /-</p>
            <p>(Rupees
                &hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;
                only) with Interest thereon&nbsp; &nbsp;at the rate of</p>
            <p>...............% (......................................... ) per annum for value received with monthly
                rests till date of payment in full,</p>
            <p>subject to change as per company&rsquo;s policy. Presentment for payment and protest of the note are
                hereby unconditionally and irrevocably waived.</p>

            <table style="width:100%;margin-top:20px">
                <tr>
                    <td style="text-align: center;vertical-align: top;">
                        <div
                            style="width: 120px;height: 150px;border: 1px solid;text-align: center;line-height: 5;margin:auto;">
                            Revenue stamp</div>
                    </td>
                    <td style="text-align: center;vertical-align: top;">
                        <div
                            style="width: 120px;height: 150px;border: 1px solid;text-align: center;line-height: 5;margin:auto;">
                            Revenue stamp</div>
                    </td>
                    <td style="text-align: center;vertical-align: top;">
                        <div
                            style="width: 120px;height: 150px;border: 1px solid;text-align: center;line-height: 5;margin:auto;">
                            Revenue stamp</div>
                    </td>
                </tr>
                <tr>
                    <td style="text-align: center;vertical-align: top;padding-top: 20px;">
                        BORROWER
                    </td>
                    <td style="text-align: center;vertical-align: top;padding-top: 20px;">
                        GUARANTOR
                    </td>
                    <td style="text-align: center;vertical-align: top;padding-top: 20px;">
                        CO-BORROWER
                    </td>
                </tr>
                <tr>
                    <td style="text-align: center;vertical-align: top;padding-top: 20px;">
                        NAME:……………………………
                    </td>
                    <td style="text-align: center;vertical-align: top;padding-top: 20px;">
                        NAME:……………………………
                    </td>
                    <td style="text-align: center;vertical-align: top;padding-top: 20px;">
                        NAME:……………………………
                    </td>
                </tr>
                <tr>
                    <td style="text-align: left;vertical-align: top;padding-top: 50px;">
                        DATE:……………………………<br><br>
                        PLACE:……………………………
                    </td>
                    <td style="text-align: center;vertical-align: top;padding-top: 0px;">
                    </td>
                    <td style="text-align: center;vertical-align: top;padding-top: 0px;">
                    </td>
                </tr>
            </table>

        </div>

    </page>
    <div class="page-break"></div>
    <page size="A4" style="padding: 10px;">
        <div>
            <p style="text-align: center;"><strong><u>DELIVERY RECEIPT</u></strong></p>

            <p>Me undersigned
                <strong>{{ isset($borrower_details['basic']['name']) ? $borrower_details['basic']['name'] : '' }}
                </strong> {{ isset($borrower_details['basic']) && $borrower_details['basic']['gender'] == 'Male' ? 'S/o' : 'D/o' }} <strong>
                    {{ isset($borrower_details['basic']['father_name']) ? $borrower_details['basic']['father_name'] : '' }}</strong>
            </p>

            <p>have taken the delivery of vehicle Scooter Cycle/Car/Rickshaw, Regd.
                No.................................................................</p>
            <p>bearing Engine</p>

            <p>No ............................................. Chassis
                No.................................................... Model No........................................
                from</p>

            <p>Shri....................................................................................................................................................................
                through as per</p>

            <p>signed &amp; addressed, for my personal use, complete in all respect with registration&nbsp;
                &nbsp;documents and tax paid upto</p>
            <p>&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;..</p>

            <p>I will be fully responsible for its maintenance, Accident, Road Taxes, Police Challans,other offences
                &amp; misuse of any kind.</p>

            <p>&nbsp;</p>
            <p>Received By &hellip;..&hellip;&hellip;&hellip;..................</p>
            <p>Place: &hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;..</p>
            <p> Signature...................................................</p>
            <p>&nbsp;</p>
            <p>NAME:
                &hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;
            </p>

            <p>ADDRESS:
                &hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;..
            </p>

            <p>DATE:
                &nbsp;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;.
            </p>

            <p>PHONE:&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;..
            </p>
            <p>&nbsp;</p>

            <p>Received
                To&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;.
            </p>

            <p>Place:
                &hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;
            </p>
            <p> Signature...................................................</p>

            <p>NAME:
                &hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;
            </p>

            <p>ADDRESS:
                &hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;..
            </p>

            <p>DATE:
                &nbsp;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;.
            </p>

            <p>PHONE:&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;..
            </p>
        </div>
    </page>
    <div class="page-break"></div>
    <page size="A4" style="padding: 10px;">
        <div>
            <p>&nbsp;</p>
            <p style="text-align: center;"><strong><u>DECLARATION OF VERNACULAR LANGUAGE</u></strong></p>
            <p><strong>&nbsp;</strong></p>
            <p>I, Mr./Ms.___________________________________, hereby declare that
                Mr.____________________________________ </u>have read out and explained in vernacular language the
                contents and nature of the loan cum Hypothecation agreement and other documents to me (Borrower) and all
                the documents are signed by me in the capacity of Borrower to avail the loan facility from
                {{ !empty($lender_details['company_name']) ? $lender_details['company_name'] : '______________________________________________' }}..
                I, do hereby acknowledge the same.</p>
            <p>&nbsp;</p>
            <p>That I am signing all the documents and the agreements after understanding the nature andcontents of all
                the documents.</p>

            <p>&nbsp;</p>
            <p>&nbsp;</p>
            <p>Name &amp; Signature of the
                Officer&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
                DECLARANT</p>

        </div>
    </page>
    {{-- form-60 --}}

    <div class="page-break"></div>
    <page size="A4" style="padding: 10px;">
        <!-- Form heading -->
        <div style="text-align:center; width:80%; margin:30px auto 0 ;">
            <h4
                style="font-size: 14px; font-weight: 600; border-bottom:1px solid grey; width:150px; margin:auto; padding-bottom:5px;">
                <sup>1</sup>[FORM NO. 60]
            </h4>

            <p style="margin:10px 0; font-size: 12px; font-weight: 300; font-family: italic;">[See second
                proviso to rule
                114B]</p>

            <h6 style="font-size:12px; font-weight:500;">
                Form for declaration to be filed by any person (other than a company or firm) or a foreign
                company covered by the third proviso to rule 114B, who does not have a permanent account
                number and who enters into any transaction specified in rule 114B
            </h6>
        </div>

        <!-- Form Starts here-->
        <div style="margin-top: 5px; font-size: 12px;">
            <!-- name section & dob, point 1 to 2 -->
            <div>
                <table style="width: 100%; border-collapse: collapse; margin-bottom: 20px;">
                    <tbody>
                        @php
                            $fullName = $borrower_details['basic']['name'] ?? '';
                            $nameParts = explode(' ', trim($fullName));

                            $firstName = '';
                            $middleName = '';
                            $surname = '';

                            // Decide first/middle/surname based on word count
                            if (count($nameParts) == 2) {
                                $firstName = $nameParts[0];
                                $surname = $nameParts[1];
                            } elseif (count($nameParts) == 4) {
                                $firstName = $nameParts[0];
                                $surname = $nameParts[3];
                            } elseif (count($nameParts) == 3) {
                                $firstName = $nameParts[0];
                                $middleName = $nameParts[1];
                                $surname = $nameParts[2];
                            } elseif (count($nameParts) == 1) {
                                $firstName = $nameParts[0];
                            } else {
                                $firstName = $nameParts[0];
                                $surname = $nameParts[count($nameParts) - 1];
                            }

                            // Convert to letters and pad to 15 boxes
                            $firstNameLetters = array_pad(str_split($firstName), 15, '');
                            $middleNameLetters = array_pad(str_split($middleName), 15, '');
                            $surnameLetters = array_pad(str_split($surname), 15, '');
                        @endphp
                        <tr>
                            <!-- Name sec -->
                            <td rowspan="4"
                                style="padding:2px 3px; background: #d5d3d3; width: 3.1%; border: solid 1px #686767; vertical-align: top;">
                                1</td>
                            <td style="padding:2px 3px; width:10%; background: #d5d3d3; border: solid 1px #686767;">
                                First Name</td>

                            @foreach ($firstNameLetters as $letter)
                                <td style="padding:2px 3px; width: 3%; border: solid 1px #3b3b3b;">
                                    {{ $letter }}</td>
                            @endforeach
                            <!-- date of birth -->
                            <td rowspan="3"
                                style="padding:2px 3px; background: #d5d3d3; width: 3%; border: solid 1px #686767; vertical-align: top;">
                                2</td>
                            <td style="padding:2px 3px; width: 5%; border: solid 1px #3b3b3b; background: #d5d3d3;"
                                colspan="8">Date of
                                Birth/ Incorporation of declarant
                            </td>
                        </tr>
                        <tr>
                            <td style="padding:2px 3px; width:10%; border: solid 1px #3b3b3b; background: #d5d3d3;">
                                Middle Name</td>

                            @foreach ($middleNameLetters as $letter)
                                <td style="padding:2px 3px; width: 3%; border: solid 1px #3b3b3b;">
                                    {{ $letter }}</td>
                            @endforeach

                            @php
                                $dob = $borrower_details['basic']['dob_date'] ?? '';
                                $dobDigits = ['', '', '', '', '', '', '', '']; // 8 boxes: DD MM YYYY

                                if ($dob) {
                                    // Convert to DDMMYYYY
                                    $date = \Carbon\Carbon::parse($dob);
                                    $dobDigits = str_split($date->format('dm') . $date->format('Y'));
                                    // Actually, str_split('DDMMYYYY') will give 8 digits? let's check:
                                    // DD = 2, MM = 2, YYYY = 4 => total 8 digits, yes
                                }
                            @endphp

                            <td style="padding:2px 3px; width: 3%; border: solid 1px #3b3b3b; text-align: center;"
                                rowspan="2">
                                {{ $dobDigits[0] ?? '' }}
                            </td>
                            <td style="padding:2px 3px; width: 3%; border: solid 1px #3b3b3b; text-align: center;"
                                rowspan="2">
                                {{ $dobDigits[1] ?? '' }}
                            </td>
                            <td style="padding:2px 3px; width: 3%; border: solid 1px #3b3b3b; text-align: center;"
                                rowspan="2">
                                {{ $dobDigits[2] ?? '' }}
                            </td>
                            <td style="padding:2px 3px; width: 3%; border: solid 1px #3b3b3b; text-align: center;"
                                rowspan="2">
                                {{ $dobDigits[3] ?? '' }}
                            </td>
                            <td style="padding:2px 3px; width: 3%; border: solid 1px #3b3b3b; text-align: center;"
                                rowspan="2">
                                {{ $dobDigits[4] ?? '' }}
                            </td>
                            <td style="padding:2px 3px; width: 3%; border: solid 1px #3b3b3b; text-align: center;"
                                rowspan="2">
                                {{ $dobDigits[5] ?? '' }}
                            </td>
                            <td style="padding:2px 3px; width: 3%; border: solid 1px #3b3b3b; text-align: center;"
                                rowspan="2">
                                {{ $dobDigits[6] ?? '' }}
                            </td>
                            <td style="padding:2px 3px; width: 3%; border: solid 1px #3b3b3b; text-align: center;"
                                rowspan="2">
                                {{ $dobDigits[7] ?? '' }}
                            </td>

                        </tr>
                        <tr>
                            <td style="padding:2px 3px; width:10%; border: solid 1px #3b3b3b; background: #d5d3d3;">
                                Surname</td>

                            @foreach ($surnameLetters as $letter)
                                <td style="padding:2px 3px; width: 3%; border: solid 1px #3b3b3b;">
                                    {{ $letter }}</td>
                            @endforeach
                        </tr>
                        <tr>
                            <td style="background: #d5d3d3; height: 5px; border: solid 1px #3b3b3b;" colspan="25">
                            </td>
                        </tr>
                        @php
                            $father_fullName = $borrower_details['basic']['father_name'] ?? '';
                            $father_nameParts = explode(' ', trim($father_fullName));

                            $father_firstName = '';
                            $father_middleName = '';
                            $father_surname = '';

                            // Decide first/middle/surname based on word count
                            if (count($father_nameParts) == 2) {
                                $father_firstName = $father_nameParts[0];
                                $father_surname = $father_nameParts[1];
                            } elseif (count($father_nameParts) == 4) {
                                $father_firstName = $father_nameParts[0];
                                $father_surname = $father_nameParts[3];
                            } elseif (count($father_nameParts) == 3) {
                                $father_firstName = $father_nameParts[0];
                                $father_middleName = $father_nameParts[1];
                                $father_surname = $father_nameParts[2];
                            } elseif (count($father_nameParts) == 1) {
                                $father_firstName = $father_nameParts[0];
                            } else {
                                $father_firstName = $father_nameParts[0];
                                $father_surname = $father_nameParts[count($father_nameParts) - 1];
                            }

                            // Convert to letters and pad to 15 boxes
                            $father_firstNameLetters = array_pad(str_split($father_firstName), 17, '');
                            $father_middleNameLetters = array_pad(str_split($father_middleName), 24, '');
                            $father_surnameLetters = array_pad(str_split($father_surname), 24, '');
                        @endphp
                        <tr>
                            <td rowspan="3"
                                style="padding:2px 3px; background: #d5d3d3; width: 3%; border: solid 1px #686767; vertical-align: top; border-top: none;">
                                3</td>
                            <td colspan="6"
                                style="width: 23%; padding:2px 3px; background: #d5d3d3; width: 3%; border: solid 1px #686767; vertical-align: top; border-top: none;">
                                Father's Name (in case of
                                individual)</td>
                            <td colspan="2"
                                style="width: 8%; padding: 2px 5px; background: #d5d3d3; border: solid 1px #3b3b3b; border-top: none;">
                                First Name </td>
                            @foreach ($father_firstNameLetters as $letter)
                                <td style="padding:2px 3px; width: 3%; border: solid 1px #3b3b3b; border-top: none;">
                                    {{ $letter }}</td>
                            @endforeach
                        </tr>
                        <tr>
                            <td
                                style="width: 6%; padding: 2px 5px; background: #d5d3d3; height: 15px; border: solid 1px #3b3b3b; border-top: none;">
                                Middle Name</td>

                            @foreach ($father_middleNameLetters as $letter)
                                <td style="padding:2px 3px; width: 3%; border: solid 1px #3b3b3b; border-top: none;">
                                    {{ $letter }}</td>
                            @endforeach
                        </tr>
                        <tr>
                            <td
                                style="width: 6%; padding: 2px 5px; background: #d5d3d3; height: 15px; border: solid 1px #3b3b3b; border-top: none;">
                                Surname</td>
                            @foreach ($father_surnameLetters as $letter)
                                <td style="padding:2px 3px; width: 3%; border: solid 1px #3b3b3b; border-top: none;">
                                    {{ $letter }}</td>
                            @endforeach
                        </tr>
                        <tr>
                            <td
                                style="height: 40px; padding:2px 3px; background: #d5d3d3; width: 3%; border: solid 1px #686767; vertical-align: top; border-top: none;">
                                4</td>
                            <td style="padding:2px 3px; width: 3%; border: solid 1px #3b3b3b; vertical-align: top; border-top: none;"
                                colspan="9">
                                Flat/ Room No.
                                <div style="padding: 2px 0;">
                                    <!-- Value Here -->
                                </div>
                            </td>
                            <td
                                style="height: 40px; padding:2px 3px; background: #d5d3d3; width: 3%; border: solid 1px #686767; vertical-align: top; border-top: none;">
                                5</td>
                            <td style="padding:2px 3px; width: 3%; border: solid 1px #3b3b3b; vertical-align: top; border-top: none;"
                                colspan="15">
                                Floor No.
                                <div style="padding: 2px 0;">
                                    <!-- Value Here -->
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <td
                                style="height: 40px; padding:2px 3px; background: #d5d3d3; width: 3%; border: solid 1px #686767; vertical-align: top; border-top: none;">
                                6</td>
                            <td style="padding:2px 3px; width: 3%; border: solid 1px #3b3b3b; vertical-align: top; border-top: none;"
                                colspan="9">
                                Name of premises
                                <div style="padding: 2px 0;">
                                    <!-- Value Here -->
                                </div>
                            </td>
                            <td
                                style="height: 40px; padding:2px 3px; background: #d5d3d3; width: 3%; border: solid 1px #686767; vertical-align: top; border-top: none;">
                                7</td>
                            <td style="padding:2px 3px; width: 3%; border: solid 1px #3b3b3b; vertical-align: top; border-top: none;"
                                colspan="15">
                                Block Name/No.
                                <div style="padding: 2px 0;">
                                    <!-- Value Here -->
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <td
                                style="height: 40px; padding:2px 3px; background: #d5d3d3; width: 3%; border: solid 1px #686767; vertical-align: top; border-top: none;">
                                8</td>
                            <td style="padding:2px 3px; width: 3%; border: solid 1px #3b3b3b; vertical-align: top; border-top: none;"
                                colspan="9">
                                Road/ Street/ Lane
                                <div style="padding: 2px 0;">
                                    <!-- Value Here -->
                                </div>
                            </td>
                            <td
                                style="height: 40px; padding:2px 3px; background: #d5d3d3; width: 3%; border: solid 1px #686767; vertical-align: top; border-top: none;">
                                9</td>
                            <td style="padding:2px 3px; width: 3%; border: solid 1px #3b3b3b; vertical-align: top; border-top: none;"
                                colspan="15">
                                Area/ Locality
                                <div style="padding: 2px 0;">
                                    <!-- Value Here -->
                                    {{ $borrower_details['address']['landmark'] ?? '' }}
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <td
                                style="height: 40px; padding:2px 3px; background: #d5d3d3; width: 3%; border: solid 1px #686767; vertical-align: top; border-top: none;">
                                10</td>
                            <td style="padding:2px 3px; width: 3%; border: solid 1px #3b3b3b; vertical-align: top; border-top: none;"
                                colspan="9">
                                Town/ City
                                <div style="padding: 2px 0;">
                                    <!-- Value Here -->
                                    {{ $borrower_details['address']['city'] ?? '' }}
                                </div>
                            </td>
                            <td
                                style="height: 40px; padding:2px 3px; background: #d5d3d3; width: 3%; border: solid 1px #686767; vertical-align: top; border-top: none;">
                                11</td>
                            <td style="padding:2px 3px; width: 3%; border: solid 1px #3b3b3b; vertical-align: top; border-top: none;"
                                colspan="7">
                                District
                                <div style="padding: 2px 0;">
                                    <!-- Value Here -->
                                    {{ $borrower_details['address']['district'] ?? '' }}
                                </div>
                            </td>
                            <td
                                style="height: 40px; padding:2px 3px; background: #d5d3d3; width: 3%; border: solid 1px #686767; vertical-align: top; border-top: none;">
                                12</td>
                            <td style="padding:2px 3px; width: 3%; border: solid 1px #3b3b3b; vertical-align: top; border-top: none;"
                                colspan="7">
                                State
                                <div style="padding: 2px 0;">
                                    <!-- Value Here -->
                                    {{ $borrower_details['address']['state'] ?? '' }}
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <td
                                style="height: 40px; padding:2px 3px; background: #d5d3d3; width: 3%; border: solid 1px #686767; vertical-align: top; border-top: none;">
                                13</td>
                            <td style="padding:2px 3px; border: solid 1px #3b3b3b; vertical-align: top; border-top: none;"
                                colspan="6">
                                Pin code
                                <div style="padding: 2px 0;">
                                    <!-- Value Here -->
                                    {{ $borrower_details['address']['pincode'] ?? '' }}
                                </div>
                            </td>
                            <td
                                style="height: 40px; padding:2px 3px; background: #d5d3d3; border: solid 1px #686767; vertical-align: top; border-top: none;">
                                14</td>
                            <td style="padding:2px 3px; border: solid 1px #3b3b3b; vertical-align: top; border-top: none;"
                                colspan="8">
                                Telephone Number (with STD code)
                                <div style="padding: 2px 0;">
                                    <!-- Value Here -->
                                </div>
                            </td>
                            <td
                                style="height: 40px; padding:2px 3px; background: #d5d3d3; border: solid 1px #686767; vertical-align: top; border-top: none;">
                                15</td>
                            <td style="padding:2px 3px; border: solid 1px #3b3b3b; vertical-align: top; border-top: none;"
                                colspan="9">
                                Mobile Number
                                <div style="padding: 2px 0;">
                                    <!-- Value Here -->
                                    {{ $borrower_details['basic']['mobile'] ?? '' }}
                                </div>
                            </td>
                        </tr>
                        <!-- 16 Taxpayer identification  -->
                        <tr>
                            <td
                                style="height: 40px; padding:2px 3px; background: #d5d3d3; width: 3%; border: solid 1px #686767; vertical-align: top; border-top: none;">
                                16</td>
                            <td style="padding:2px 3px; border: solid 1px #3b3b3b; vertical-align: top; border-top: none;"
                                colspan="8">
                                Taxpayer identification Number in the
                                country of residence (if covered by third
                                proviso)
                            </td>
                            <td style="height: 40px; padding:2px 3px; background: #d5d3d3; border: solid 1px #686767; vertical-align: top; border-top: none;"
                                colspan="17">
                            </td>
                        </tr>
                        <!-- amount of transaction -->
                        <tr>
                            <td
                                style="height: 40px; padding:2px 3px; background: #d5d3d3; width: 3.7%; border: solid 1px #686767; vertical-align: top;">
                                17</td>
                            <td style="padding:2px 3px; border: solid 1px #3b3b3b; vertical-align: top;"
                                colspan="10">
                                Amount of transaction (Rs.)
                            </td>
                            <td style="padding:2px 3px; border: solid 1px #3b3b3b;"></td>
                            <td style="padding:2px 3px; border: solid 1px #3b3b3b;"></td>
                            <td style="padding:2px 3px; border: solid 1px #3b3b3b;"></td>
                            <td style="padding:2px 3px; border: solid 1px #3b3b3b;"></td>
                            <td style="padding:2px 3px; border: solid 1px #3b3b3b;"></td>
                            <td style="padding:2px 3px; border: solid 1px #3b3b3b;"></td>
                            <td style="width: 3%; height: 40px; padding:2px 3px; background: #d5d3d3; border: solid 1px #686767; vertical-align: top;"
                                rowspan="2">
                                19
                            </td>
                            <td style="padding:2px 3px; width: 20%; border: solid 1px #3b3b3b;" rowspan="2"
                                colspan="7">
                                In case of transaction in
                                joint names, number of
                                persons involved in the
                                transaction
                            </td>
                            <td style="padding:2px 3px; width: 4%; border: solid 1px #3b3b3b;" rowspan="2"></td>
                        </tr>

                        <!-- date of transaction -->
                        <tr>
                            <td
                                style="height: 40px; padding:2px 3px; background: #d5d3d3; width: 3.7%; border: solid 1px #686767; vertical-align: top; border-top: none;">
                                18</td>
                            <td style="padding:2px 3px; border: solid 1px #3b3b3b; vertical-align: top; border-top: none;"
                                colspan="8">
                                Date of transaction
                            </td>
                            <td style="padding:2px 3px; border: solid 1px #3b3b3b; border-top: none;"><span
                                    style="color: #b6b3b3;">D</span></td>
                            <td style="padding:2px 3px; border: solid 1px #3b3b3b; border-top: none;"><span
                                    style="color: #b6b3b3;">D</span></td>
                            <td style="padding:2px 3px; border: solid 1px #3b3b3b; border-top: none;"><span
                                    style="color: #b6b3b3;">M</span></td>
                            <td style="padding:2px 3px; border: solid 1px #3b3b3b; border-top: none;"><span
                                    style="color: #b6b3b3;">M</span></td>
                            <td style="padding:2px 3px; border: solid 1px #3b3b3b; border-top: none;"><span
                                    style="color: #b6b3b3;">Y</span></td>
                            <td style="padding:2px 3px; border: solid 1px #3b3b3b; border-top: none;"><span
                                    style="color: #b6b3b3;">Y</span></td>
                            <td style="padding:2px 3px; border: solid 1px #3b3b3b; border-top: none;"><span
                                    style="color: #b6b3b3;">Y</span></td>
                            <td style="padding:2px 3px; border: solid 1px #3b3b3b; border-top: none;"><span
                                    style="color: #b6b3b3;">Y</span></td>
                        </tr>
                        <!-- mode of payment -->
                        <tr>
                            <td
                                style="height: 40px; padding:2px 3px; background: #d5d3d3; width: 3.7%; border: solid 1px #686767; vertical-align: top;">
                                20</td>
                            <td style="padding:2px 3px; border: solid 1px #3b3b3b; vertical-align: top; vertical-align: middle;"
                                colspan="25">
                                Mode of transaction :
                                <input type="checkbox" id="cash"
                                    style="vertical-align: text-bottom; margin-left: 5px;"> Cash,
                                <input type="checkbox" id="cheque"
                                    style="vertical-align: text-bottom; margin-left: 5px;"> Cheque,
                                <input type="checkbox" id="card"
                                    style="vertical-align: text-bottom; margin-left: 5px;"> Card,
                                <input type="checkbox" id="draft"
                                    style="vertical-align: text-bottom; margin-left: 5px;">
                                Draft/Banker's Cheque,
                                <input type="checkbox" id="online-traansfer"
                                    style="vertical-align: text-bottom; margin-left: 5px;">
                                Online transfer,
                                <input type="checkbox" id="card"
                                    style="vertical-align: text-bottom; margin-left: 5px;"> Others
                            </td>
                        </tr>

                        <!-- UIDAI -->
                        <tr>
                            <td
                                style="height: 40px; padding:2px 3px; background: #d5d3d3; width: 3.7%; border: solid 1px #686767; vertical-align: top; border-top: none;">
                                21</td>
                            <td style="padding:2px 3px; border: solid 1px #3b3b3b; vertical-align: top; border-top: none;"
                                colspan="13">
                                Aadhaar Number issued by
                                UIDAI
                                (if available)
                            </td>
                            <td style="padding:2px 3px; border: solid 1px #3b3b3b; border-top: none;"
                                colspan="12"></td>
                        </tr>


                        <tr style="text-align: center;">
                            <td style="height: 40px; padding:2px 3px; background: #d5d3d3; width: 3.7%; border: solid 1px #686767; vertical-align: top; border-top: none;"
                                rowspan="2">
                                22</td>
                            <td style="padding:2px 3px; width: 40%; border: solid 1px #3b3b3b; vertical-align: top; border-top: none; text-align: left;"
                                rowspan="2" colspan="9">
                                If applied for PAN and it is not yet generated
                                enter date of application and
                                acknowledgement number
                            </td>
                            <td style="padding:2px 3px; border: solid 1px #3b3b3b; border-top: none;"
                                colspan="2"><span style="color: #b6b3b3;">D</span></td>
                            <td style="padding:2px 3px; border: solid 1px #3b3b3b; border-top: none;"
                                colspan="2"><span style="color: #b6b3b3;">D</span></td>
                            <td style="padding:2px 3px; border: solid 1px #3b3b3b; border-top: none;"
                                colspan="2"><span style="color: #b6b3b3;">M</span></td>
                            <td style="padding:2px 3px; border: solid 1px #3b3b3b; border-top: none;"
                                colspan="2"><span style="color: #b6b3b3;">M</span></td>
                            <td style="padding:2px 3px; border: solid 1px #3b3b3b; border-top: none;"
                                colspan="2"><span style="color: #b6b3b3;">Y</span></td>
                            <td style="padding:2px 3px; border: solid 1px #3b3b3b; border-top: none;"
                                colspan="2"><span style="color: #b6b3b3;">Y</span></td>
                            <td style="padding:2px 3px; border: solid 1px #3b3b3b; border-top: none;"
                                colspan="2"><span style="color: #b6b3b3;">Y</span></td>
                            <td style="padding:2px 3px; border: solid 1px #3b3b3b; border-top: none;"
                                colspan="2"><span style="color: #b6b3b3;">Y</span></td>
                        </tr>
                        <tr style="text-align: center;">
                            <td style="height: 30px; padding:2px 3px; border: solid 1px #3b3b3b; border-top: none;">
                            </td>
                            <td style="height: 30px; padding:2px 3px; border: solid 1px #3b3b3b; border-top: none;">
                            </td>
                            <td style="height: 30px; padding:2px 3px; border: solid 1px #3b3b3b; border-top: none;">
                            </td>
                            <td style="height: 30px; padding:2px 3px; border: solid 1px #3b3b3b; border-top: none;">
                            </td>
                            <td style="height: 30px; padding:2px 3px; border: solid 1px #3b3b3b; border-top: none;">
                            </td>
                            <td style="height: 30px; padding:2px 3px; border: solid 1px #3b3b3b; border-top: none;">
                            </td>
                            <td style="height: 30px; padding:2px 3px; border: solid 1px #3b3b3b; border-top: none;">
                            </td>
                            <td style="height: 30px; padding:2px 3px; border: solid 1px #3b3b3b; border-top: none;">
                            </td>
                            <td style="height: 30px; padding:2px 3px; border: solid 1px #3b3b3b; border-top: none;">
                            </td>
                            <td style="height: 30px; padding:2px 3px; border: solid 1px #3b3b3b; border-top: none;">
                            </td>
                            <td style="height: 30px; padding:2px 3px; border: solid 1px #3b3b3b; border-top: none;">
                            </td>
                            <td style="height: 30px; padding:2px 3px; border: solid 1px #3b3b3b; border-top: none;">
                            </td>
                            <td style="height: 30px; padding:2px 3px; border: solid 1px #3b3b3b; border-top: none;">
                            </td>
                            <td style="height: 30px; padding:2px 3px; border: solid 1px #3b3b3b; border-top: none;">
                            </td>
                            <td style="height: 30px; padding:2px 3px; border: solid 1px #3b3b3b; border-top: none;">
                            </td>
                            <td style="height: 30px; padding:2px 3px; border: solid 1px #3b3b3b; border-top: none;">
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </page>
    <div class="page-break"></div>
    <page size="A4" style="padding: 10px;">

        <div style="margin-top: 10px; font-size: 12px;">
            <!-- point 10x` to 21 -->
            <div>
                <table style="width: 100%; border-collapse: collapse; margin-bottom: 20px;">
                    <tbody>

                        <tr style="text-align: center;">
                            <td
                                style="height: 40px; padding:2px 3px; background: #d5d3d3; width: 3.7%; border: solid 1px #686767; vertical-align: top;">
                                23</td>

                            <td style="padding:2px 3px; border: solid 1px #3b3b3b; vertical-align: top; text-align: left;"
                                colspan="25">
                                If PAN not applied, fill estimated total income (including income of spouse,
                                minor child etc. as per
                                section 64
                                of Income-tax Act, 1961) for the financial year in which the above transaction
                                is held
                            </td>
                        </tr>

                        <!-- Agricultural income -->
                        <tr style="text-align: center;">
                            <td style="height: 40px; padding:2px 3px; background: #d5d3d3; width: 3.7%; border: solid 1px #686767; vertical-align: top; border-top: none;"
                                rowspan="2">
                            </td>
                            <td style="height: 40px; padding:2px 3px; background: #d5d3d3; width: 3.7%; border: solid 1px #686767; vertical-align: top; border-top: none;"
                                colspan="2">
                                a
                            </td>
                            <td style="padding:2px 3px; width: 30%; border: solid 1px #3b3b3b; vertical-align: top; border-top: none; text-align: left;"
                                colspan="13">
                                Agricultural income (Rs.)
                            </td>
                            <td style="height: 30px; padding:2px 3px; border: solid 1px #3b3b3b; border-top: none;">
                            </td>
                            <td style="height: 30px; padding:2px 3px; border: solid 1px #3b3b3b; border-top: none;">
                            </td>
                            <td style="height: 30px; padding:2px 3px; border: solid 1px #3b3b3b; border-top: none;">
                            </td>
                            <td style="height: 30px; padding:2px 3px; border: solid 1px #3b3b3b; border-top: none;">
                            </td>
                            <td style="height: 30px; padding:2px 3px; border: solid 1px #3b3b3b; border-top: none;">
                            </td>
                            <td style="height: 30px; padding:2px 3px; border: solid 1px #3b3b3b; border-top: none;">
                            </td>
                            <td style="height: 30px; padding:2px 3px; border: solid 1px #3b3b3b; border-top: none;">
                            </td>
                            <td style="height: 30px; padding:2px 3px; border: solid 1px #3b3b3b; border-top: none;">
                            </td>
                            <td style="height: 30px; padding:2px 3px; border: solid 1px #3b3b3b; border-top: none;">
                            </td>
                            <td style="height: 30px; padding:2px 3px; border: solid 1px #3b3b3b; border-top: none;">
                            </td>
                        </tr>

                        <!-- Other than agricultural income -->
                        <tr style="text-align: center;">
                            <td style="height: 40px; padding:2px 3px; background: #d5d3d3; width: 3.7%; border: solid 1px #686767; vertical-align: top; border-top: none;"
                                colspan="2">
                                b
                            </td>
                            <td style="padding:2px 3px; width: 30%; border: solid 1px #3b3b3b; vertical-align: top; border-top: none; text-align: left;"
                                colspan="13">
                                Other than agricultural income (Rs.)
                            </td>
                            <td style="height: 30px; padding:2px 3px; border: solid 1px #3b3b3b; border-top: none;">
                            </td>
                            <td style="height: 30px; padding:2px 3px; border: solid 1px #3b3b3b; border-top: none;">
                            </td>
                            <td style="height: 30px; padding:2px 3px; border: solid 1px #3b3b3b; border-top: none;">
                            </td>
                            <td style="height: 30px; padding:2px 3px; border: solid 1px #3b3b3b; border-top: none;">
                            </td>
                            <td style="height: 30px; padding:2px 3px; border: solid 1px #3b3b3b; border-top: none;">
                            </td>
                            <td style="height: 30px; padding:2px 3px; border: solid 1px #3b3b3b; border-top: none;">
                            </td>
                            <td style="height: 30px; padding:2px 3px; border: solid 1px #3b3b3b; border-top: none;">
                            </td>
                            <td style="height: 30px; padding:2px 3px; border: solid 1px #3b3b3b; border-top: none;">
                            </td>
                            <td style="height: 30px; padding:2px 3px; border: solid 1px #3b3b3b; border-top: none;">
                            </td>
                            <td style="height: 30px; padding:2px 3px; border: solid 1px #3b3b3b; border-top: none;">
                            </td>
                        </tr>

                        <!-- Income chargeable -->
                        <tr style="text-align: center;">
                            <td
                                style="height: 40px; padding:2px 3px; background: #d5d3d3; width: 3.7%; border: solid 1px #686767; vertical-align: top; border-top: none;">
                            </td>
                            <td style="height: 40px; padding:2px 3px; background: #d5d3d3; width: 3.7%; border: solid 1px #686767; vertical-align: top; border-top: none;"
                                rowspan="2">
                                c
                            </td>
                            <td
                                style="height: 40px; padding:2px 3px; background: #d5d3d3; width: 3.7%; border: solid 1px #686767; vertical-align: top; border-top: none;">
                                i
                            </td>
                            <td style="padding:2px 3px; width: 30%; border: solid 1px #3b3b3b; vertical-align: top; border-top: none; text-align: left;"
                                colspan="13">
                                Income chargeable to tax (for a
                                foreign company)
                            </td>
                            <td style="height: 30px; padding:2px 3px; border: solid 1px #3b3b3b; border-top: none;">
                            </td>
                            <td style="height: 30px; padding:2px 3px; border: solid 1px #3b3b3b; border-top: none;">
                            </td>
                            <td style="height: 30px; padding:2px 3px; border: solid 1px #3b3b3b; border-top: none;">
                            </td>
                            <td style="height: 30px; padding:2px 3px; border: solid 1px #3b3b3b; border-top: none;">
                            </td>
                            <td style="height: 30px; padding:2px 3px; border: solid 1px #3b3b3b; border-top: none;">
                            </td>
                            <td style="height: 30px; padding:2px 3px; border: solid 1px #3b3b3b; border-top: none;">
                            </td>
                            <td style="height: 30px; padding:2px 3px; border: solid 1px #3b3b3b; border-top: none;">
                            </td>
                            <td style="height: 30px; padding:2px 3px; border: solid 1px #3b3b3b; border-top: none;">
                            </td>
                            <td style="height: 30px; padding:2px 3px; border: solid 1px #3b3b3b; border-top: none;">
                            </td>
                            <td style="height: 30px; padding:2px 3px; border: solid 1px #3b3b3b; border-top: none;">
                            </td>
                        </tr>

                        <!-- Income not chargeable -->
                        <tr style="text-align: center;">
                            <td
                                style="height: 40px; padding:2px 3px; background: #d5d3d3; width: 3.7%; border: solid 1px #686767; vertical-align: top; border-top: none;">
                            </td>
                            <td
                                style="height: 40px; padding:2px 3px; background: #d5d3d3; width: 3.7%; border: solid 1px #686767; vertical-align: top; border-top: none;">
                                i
                            </td>
                            <td style="padding:2px 3px; width: 30%; border: solid 1px #3b3b3b; vertical-align: top; border-top: none; text-align: left;"
                                colspan="13">
                                Income not chargeable to tax (for a
                                foreign company)
                            </td>
                            <td style="height: 30px; padding:2px 3px; border: solid 1px #3b3b3b; border-top: none;">
                            </td>
                            <td style="height: 30px; padding:2px 3px; border: solid 1px #3b3b3b; border-top: none;">
                            </td>
                            <td style="height: 30px; padding:2px 3px; border: solid 1px #3b3b3b; border-top: none;">
                            </td>
                            <td style="height: 30px; padding:2px 3px; border: solid 1px #3b3b3b; border-top: none;">
                            </td>
                            <td style="height: 30px; padding:2px 3px; border: solid 1px #3b3b3b; border-top: none;">
                            </td>
                            <td style="height: 30px; padding:2px 3px; border: solid 1px #3b3b3b; border-top: none;">
                            </td>
                            <td style="height: 30px; padding:2px 3px; border: solid 1px #3b3b3b; border-top: none;">
                            </td>
                            <td style="height: 30px; padding:2px 3px; border: solid 1px #3b3b3b; border-top: none;">
                            </td>
                            <td style="height: 30px; padding:2px 3px; border: solid 1px #3b3b3b; border-top: none;">
                            </td>
                            <td style="height: 30px; padding:2px 3px; border: solid 1px #3b3b3b; border-top: none;">
                            </td>
                        </tr>
                        <tr style="text-align: center;">
                            <td
                                style="height: 40px; padding:2px 3px; background: #d5d3d3; width: 3.7%; border: solid 1px #686767; vertical-align: top;">
                                24
                            </td>

                            <td style="width: 30%; padding:2px 30px 2px 3px; border: solid 1px #3b3b3b; vertical-align: top; text-align: left;"
                                colspan="9">
                                Details of document being
                                produced in support of identify
                                in Column 1 (Refer note
                                overleaf)
                            </td>

                            <td style="width: 15%; padding:2px 20px 2px 3px; border: solid 1px #3b3b3b; vertical-align: top; text-align: left;"
                                colspan="3">
                                Document
                                code
                            </td>

                            <td style="width: 15%; padding:2px 3px; border: solid 1px #3b3b3b; vertical-align: top; text-align: left;"
                                colspan="3">
                                Document
                                identification
                                number
                            </td>

                            <td style="padding:2px 3px; border: solid 1px #3b3b3b; vertical-align: top; text-align: left;"
                                colspan="10">
                                Name and address of the authority <br>
                                issuing the document
                            </td>
                        </tr>
                        <tr style="text-align: center;">
                            <td
                                style="height: 40px; padding:2px 3px; background: #d5d3d3; width: 3.7%; border: solid 1px #686767; vertical-align: top;">
                                25
                            </td>

                            <td style="width: 30%; padding:2px 30px 2px 3px; border: solid 1px #3b3b3b; vertical-align: top; text-align: left;"
                                colspan="9">
                                Details of document being
                                produced in support of address
                                in Columns 4 to 13
                                (Refer note overleaf)
                            </td>

                            <td style="width: 15%; padding:2px 20px 2px 3px; border: solid 1px #3b3b3b; vertical-align: top; text-align: left;"
                                colspan="3">
                                Document
                                code
                            </td>

                            <td style="width: 15%; padding:2px 3px; border: solid 1px #3b3b3b; vertical-align: top; text-align: left;"
                                colspan="3">
                                Document
                                identification
                                number
                            </td>

                            <td style="padding:2px 3px; border: solid 1px #3b3b3b; vertical-align: top; text-align: left;"
                                colspan="10">
                                Name and address of the authority <br>
                                issuing the document
                            </td>
                        </tr>
                    </tbody>
                </table>
                <!-- Substituted by the IT  -->
                <div style="margin-top: 40px;">
                    <p style="width: 150px; border-top: solid 1px #3b3b3b; margin-bottom: 10px;"></p>
                    <p>1. Substituted by the IT (Twenty-fourth Amdt.) Rules, 2023, w.e.f.
                        <strong>10-10-2023</strong>.
                    </p>
                </div>

                <!-- Verification -->
                <div style="margin-top: 40px;">
                    <p style="text-align: center; margin-bottom: 20px;"><strong>Verification</strong></p>

                    <p>I, <span style="display: inline-block; width: 150px; border-bottom: solid 1px #3b3b3b;">
                            <!-- value -->
                            {{ $borrower_details['basic']['name'] ?? '' }}
                        </span>, son/ daughter/ wife** of Shri
                        @php
                            $guardian_name = !empty($borrower_details['basic']['father_name'])
                                ? $borrower_details['basic']['father_name']
                                : $borrower_details['basic']['husband_name'] ?? '';
                        @endphp
                        <span style="display: inline-block; width: 150px; border-bottom: solid 1px #3b3b3b;">
                            <!-- value -->
                            {{ $guardian_name ?? '' }}
                        </span>, solemnly declare that
                        to the best of
                        my knowledge and belief, the information given in this application and documents, if any,
                        accompanying it
                        are correct and
                        complete and particulars shown therein are truly stated.
                    </p>

                    <p style="margin-top: 20px; margin-bottom: 2px;">2. I further declare that I /applicant does
                        not have a
                        Permanent Account Number
                        and</p>

                    <p style="margin-left: 30px; margin-bottom: 2px; margin-top: 5px;">(i) my/our/applicant’s
                        estimated total
                        income (including
                        income of spouse, minor child etc. as per section
                        64 of Income-tax
                        Act, 1961) computed in accordance with the provisions of Income-tax Act, 1961 for the
                        financial year in
                        which the
                        above transaction is held will be less than maximum amount not chargeable to tax, or</p>

                    <p style="margin-left: 30px; margin-top: 5px;">(ii) the applicant is a foreign company covered
                        by the third
                        proviso to Rule
                        114B and does not have any
                        income chargeable
                        to tax in India. </p>

                    <p style="margin-top: 40px;">3. I also declare that I am making this application for
                        <span style="display: inline-block; width: 150px; border-top: solid 1px #3b3b3b;">
                            <!-- value -->
                        </span> in
                        my capacity as
                        <span style="display: inline-block; width: 80px; border-top: solid 1px #3b3b3b;">
                            <!-- value -->
                        </span>
                        (designation) and
                        I am competent to make this application and verify it. [if applicable]
                    </p>

                    <p style="margin-top: 20px;">Verified today, the
                        <span style="display: inline-block; width: 80px; border-top: solid 1px #3b3b3b;">
                            <!-- value -->
                        </span>day of 20
                        <span style="display: inline-block; width: 40px; border-top: solid 1px #3b3b3b;">
                            <!-- value -->
                        </span>.
                    </p>

                    <p style="margin-top: 20px;">Place:
                        <span style="display: inline-block; width: 80px; border-top: solid 1px #3b3b3b;">
                            <!-- value -->
                        </span>
                    </p>

                    <p style="width: 135px; float: right; border-bottom: solid 2px #3b3b3b; margin-top: 10px;">
                        <strong>(Signature
                            of declarant)</strong>
                    </p>
                </div>
            </div>
        </div>
    </page>
    <div class="page-break"></div>
    <page size="A4" style="padding: 10px;">
        <div style="margin-top: 0px; font-size: 12px;">
            <!-- Notes -->
            <div style="margin-top: 0px;">
                <h3 style="width: 40px; border-bottom: solid 2px #3b3b3b;">Notes: </h3>
                <ol>
                    <li>Before signing the declaration, the declarant should satisfy himself that the
                        information furnished in
                        this form is true,
                        correct and complete in all respects. Any person making a false statement in the
                        declaration shall be
                        liable to prosecution
                        under section 277 of the Income‐tax Act, 1961 and on conviction be punishable,‐
                        <p style="padding-left: 5px; margin-top: 10px;">(i) in a case where tax sought to be
                            evaded exceeds
                            twenty‐five lakh rupees, with rigorous imprisonment
                            which shall not
                            be less than six months but which may extend to seven years and with fine; </p>
                        <p style="padding-left: 5px; margin-top: 5px;">(ii) in any other case, with rigorous
                            imprisonment which
                            shall not be less than three months but which
                            may extend to two
                            years and with fine. </p>
                    </li>
                    <li style="margin-top: 15px;">The person accepting the declaration shall not accept the
                        declaration where,-
                        <p style="padding-left: 5px; margin-top: 5px;">(i) the amount of income of the nature
                            referred to in item
                            23b exceeds the maximum amount which is not chargeable
                            to tax, unless PAN is applied for and column 22 is duly filled;</p>
                        <p style="padding-left: 5px; margin-top: 5px;">(ii) in any other case, with rigorous
                            (ii) there is income
                            chargeable to tax shown in item 23c(i) in case of a foreign company. </p>
                    </li>
                    <li style="margin-top: 15px;">
                        Documents which can be produced in support of identity and address (not required if
                        applied for PAN and
                        item 21 is
                        filled):-
                    </li>
                </ol>

                <!-- document listing table -->
                <div style="margin-top: 30px;">
                    <table style="width: 100%; border-collapse:collapse;">
                        <thead>
                            <tr style="text-align: left;">
                                <th style="width: 3%; border: solid 1px #3b3b3b; padding: 2px 3px;">
                                    Sl. No.</th>
                                <th style="border: solid 1px #3b3b3b; padding: 2px 3px;">Nature of
                                    Document</th>
                                <th style="border: solid 1px #3b3b3b; padding: 2px 3px;">Document Code
                                </th>
                                <th style="border: solid 1px #3b3b3b; padding: 2px 3px;">Proof of
                                    Identity</th>
                                <th style="border: solid 1px #3b3b3b; padding: 2px 3px;">Proof of
                                    Address</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td style="padding:2px 30px 2px 3px; border: solid 1px #3b3b3b; vertical-align: top; text-align: left;"
                                    colspan="2">
                                    <strong>A. For Individuals and HUF </strong>
                                </td>
                                <td style="background: #d5d3d3; border: solid 1px #3b3b3b; padding: 2px 3px;">
                                </td>
                                <td style="background: #d5d3d3; border: solid 1px #3b3b3b; padding: 2px 3px;">
                                </td>
                                <td style="background: #d5d3d3; border: solid 1px #3b3b3b; padding: 2px 3px;">
                                </td>
                            </tr>

                            <tr>
                                <td
                                    style="width: 3%; padding:2px 3px; text-align: center; border: solid 1px #3b3b3b; vertical-align: top;">
                                    1
                                </td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px;">AADHAAR card
                                </td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px; text-align: center;">
                                    <strong>01</strong>
                                </td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px; text-align: center;">
                                    Yes</td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px; text-align: center;">
                                    Yes</td>
                            </tr>

                            <tr>
                                <td
                                    style="width: 3%; padding:2px 3px; text-align: center;  border: solid 1px #3b3b3b; vertical-align: top;">
                                    2
                                </td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px;">Bank/Post
                                    office passbook bearing
                                    photograph
                                    of the person</td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px; text-align: center;">
                                    <strong>02</strong>
                                </td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px; text-align: center;">
                                    Yes</td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px; text-align: center;">
                                    Yes</td>
                            </tr>

                            <tr>
                                <td
                                    style="width: 3%; padding:2px 3px; text-align: center;  border: solid 1px #3b3b3b; vertical-align: top;">
                                    3
                                </td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px;">Elector’s
                                    photo identity card </td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px; text-align: center;">
                                    <strong>03</strong>
                                </td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px; text-align: center;">
                                    Yes</td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px; text-align: center;">
                                    Yes</td>
                            </tr>

                            <tr>
                                <td
                                    style="width: 3%; padding:2px 3px; text-align: center;  border: solid 1px #3b3b3b; vertical-align: top;">
                                    4
                                </td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px;">Ration/Public
                                    Distribution System card
                                    bearing photograph of
                                    the person</td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px; text-align: center;">
                                    <strong>04</strong>
                                </td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px; text-align: center;">
                                    Yes</td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px; text-align: center;">
                                    Yes</td>
                            </tr>

                            <tr>
                                <td
                                    style="width: 3%; padding:2px 3px; text-align: center; border: solid 1px #3b3b3b; vertical-align: top;">
                                    5
                                </td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px;">Driving
                                    License</td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px; text-align: center;">
                                    <strong>05</strong>
                                </td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px; text-align: center;">
                                    Yes</td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px; text-align: center;">
                                    Yes</td>
                            </tr>

                            <tr>
                                <td
                                    style="width: 3%; padding:2px 3px; text-align: center;  border: solid 1px #3b3b3b; vertical-align: top;">
                                    6
                                </td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px;">Passport</td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px; text-align: center;">
                                    <strong>06</strong>
                                </td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px; text-align: center;">
                                    Yes</td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px; text-align: center;">
                                    Yes</td>
                            </tr>

                            <tr>
                                <td
                                    style="width: 3%; padding:2px 3px; text-align: center;  border: solid 1px #3b3b3b; vertical-align: top;">
                                    7
                                </td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px;">Pensioner
                                    Photo card</td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px; text-align: center;">
                                    <strong>07</strong>
                                </td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px; text-align: center;">
                                    Yes</td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px; text-align: center;">
                                    Yes</td>
                            </tr>

                            <tr>
                                <td
                                    style="width: 3%; padding:2px 3px; text-align: center;  border: solid 1px #3b3b3b; vertical-align: top;">
                                    8
                                </td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px;">National
                                    Rural Employment Guarantee
                                    Scheme
                                    (NREGS) Job card</td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px; text-align: center;">
                                    <strong>08</strong>
                                </td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px; text-align: center;">
                                    Yes</td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px; text-align: center;">
                                    Yes</td>
                            </tr>

                            <tr>
                                <td
                                    style="width: 3%; padding:2px 3px; text-align: center;  border: solid 1px #3b3b3b; vertical-align: top;">
                                    9
                                </td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px;">Caste or
                                    Domicile certificate bearing
                                    photo
                                    of the person</td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px; text-align: center;">
                                    <strong>09</strong>
                                </td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px; text-align: center;">
                                    Yes</td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px; text-align: center;">
                                    Yes</td>
                            </tr>

                            <tr>
                                <td
                                    style="width: 3%; padding:2px 3px; text-align: center;  border: solid 1px #3b3b3b; vertical-align: top;">
                                    10
                                </td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px;">Certificate
                                    of identity/address signed by
                                    a
                                    Member of Parliament or Member of
                                    Legislative Assembly or Municipal Councillor or a
                                    Gazetted Officer as per annexure A prescribed in Form 49A</td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px; text-align: center;">
                                    <strong>10</strong>
                                </td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px; text-align: center;">
                                    Yes</td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px; text-align: center;">
                                    Yes</td>
                            </tr>

                            <tr>
                                <td
                                    style="width: 3%; padding:2px 3px; text-align: center;  border: solid 1px #3b3b3b; vertical-align: top;">
                                    11
                                </td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px;"> Certificate
                                    from employer as per annexure B prescribed in Form
                                    49A</td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px; text-align: center;">
                                    <strong>11</strong>
                                </td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px; text-align: center;">
                                    Yes</td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px; text-align: center;">
                                    Yes</td>
                            </tr>

                            <tr>
                                <td
                                    style="width: 3%; padding:2px 3px; text-align: center;  border: solid 1px #3b3b3b; vertical-align: top;">
                                    12
                                </td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px;"> Kisan
                                    passbook bearing photo</td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px; text-align: center;">
                                    <strong>12</strong>
                                </td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px; text-align: center;">
                                    Yes</td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px; text-align: center;">
                                    No</td>
                            </tr>

                            <tr>
                                <td
                                    style="width: 3%; padding:2px 3px; text-align: center;  border: solid 1px #3b3b3b; vertical-align: top;">
                                    13
                                </td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px;"> Arm’s
                                    license
                                </td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px; text-align: center;">
                                    <strong>13</strong>
                                </td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px; text-align: center;">
                                    Yes</td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px; text-align: center;">
                                    No</td>
                            </tr>

                            <tr>
                                <td
                                    style="width: 3%; padding:2px 3px; text-align: center;  border: solid 1px #3b3b3b; vertical-align: top;">
                                    14
                                </td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px;"> Central
                                    Government Health Scheme /Ex-servicemen
                                    Contributory Health Scheme card </td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px; text-align: center;">
                                    <strong>14</strong>
                                </td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px; text-align: center;">
                                    Yes</td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px; text-align: center;">
                                    No</td>
                            </tr>

                            <tr>
                                <td
                                    style="width: 3%; padding:2px 3px; text-align: center;  border: solid 1px #3b3b3b; vertical-align: top;">
                                    15
                                </td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px;"> Photo
                                    identity card issued by the government./ Public Sector
                                    Undertaking </td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px; text-align: center;">
                                    <strong>15</strong>
                                </td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px; text-align: center;">
                                    Yes</td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px; text-align: center;">
                                    No</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

            </div>
        </div>
    </page>
    <div class="page-break"></div>
    <page size="A4" style="padding: 10px;">
        <div style="margin-top: 0px; font-size: 12px;">
            <!-- Notes -->
            <div style="margin-top: 0px;">
                <div style="margin-top: 0px;">
                    <table style="width: 100%; border-collapse:collapse;">
                        <thead>
                            <tr style="text-align: left;">
                                <th style="width: 3%; border: solid 1px #3b3b3b; padding: 2px 3px;">
                                    Sl. No.</th>
                                <th style="border: solid 1px #3b3b3b; padding: 2px 3px;">Nature of
                                    Document</th>
                                <th style="border: solid 1px #3b3b3b; padding: 2px 3px;">Document Code
                                </th>
                                <th style="border: solid 1px #3b3b3b; padding: 2px 3px;">Proof of
                                    Identity</th>
                                <th style="border: solid 1px #3b3b3b; padding: 2px 3px;">Proof of
                                    Address</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td
                                    style="width: 3%; padding:2px 3px; text-align: center;  border: solid 1px #3b3b3b; vertical-align: top;">
                                    16
                                </td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px;">Electricity
                                    bill (Not more than 3 months
                                    old)
                                </td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px; text-align: center;">
                                    <strong>16</strong>
                                </td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px; text-align: center;">
                                    No</td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px; text-align: center;">
                                    Yes</td>
                            </tr>

                            <tr>
                                <td
                                    style="width: 3%; padding:2px 3px; text-align: center;  border: solid 1px #3b3b3b; vertical-align: top;">
                                    17
                                </td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px;">Landline
                                    Telephone bill (Not more than 3
                                    months old) </td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px; text-align: center;">
                                    <strong>17</strong>
                                </td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px; text-align: center;">
                                    No</td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px; text-align: center;">
                                    Yes</td>
                            </tr>

                            <tr>
                                <td
                                    style="width: 3%; padding:2px 3px; text-align: center;  border: solid 1px #3b3b3b; vertical-align: top;">
                                    18
                                </td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px;">Water bill
                                    (Not more than 3 months old)
                                </td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px; text-align: center;">
                                    <strong>18</strong>
                                </td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px; text-align: center;">
                                    No</td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px; text-align: center;">
                                    Yes</td>
                            </tr>

                            <tr>
                                <td
                                    style="width: 3%; padding:2px 3px; text-align: center;  border: solid 1px #3b3b3b; vertical-align: top;">
                                    19
                                </td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px;">Consumer gas
                                    card/book or piped gas bill
                                    (Not
                                    more than 3
                                    months old)</td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px; text-align: center;">
                                    <strong>19</strong>
                                </td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px; text-align: center;">
                                    No</td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px; text-align: center;">
                                    Yes</td>
                            </tr>

                            <tr>
                                <td
                                    style="width: 3%; padding:2px 3px; text-align: center;  border: solid 1px #3b3b3b; vertical-align: top;">
                                    20
                                </td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px;">Bank Account
                                    Statement (Not more than 3
                                    months old) </td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px; text-align: center;">
                                    <strong>20</strong>
                                </td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px; text-align: center;">
                                    No</td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px; text-align: center;">
                                    Yes</td>
                            </tr>

                            <tr>
                                <td
                                    style="width: 3%; padding:2px 3px; text-align: center;  border: solid 1px #3b3b3b; vertical-align: top;">
                                    21
                                </td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px;">Credit Card
                                    statement (Not more than 3
                                    months
                                    old)</td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px; text-align: center;">
                                    <strong>21</strong>
                                </td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px; text-align: center;">
                                    No</td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px; text-align: center;">
                                    Yes</td>
                            </tr>

                            <tr>
                                <td
                                    style="width: 3%; padding:2px 3px; text-align: center;  border: solid 1px #3b3b3b; vertical-align: top;">
                                    22
                                </td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px;">Depository
                                    Account Statement (Not more
                                    than
                                    3
                                    months old) </td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px; text-align: center;">
                                    <strong>22</strong>
                                </td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px; text-align: center;">
                                    No</td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px; text-align: center;">
                                    Yes</td>
                            </tr>

                            <tr>
                                <td
                                    style="width: 3%; padding:2px 3px; text-align: center;  border: solid 1px #3b3b3b; vertical-align: top;">
                                    23
                                </td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px;">Property
                                    registration document</td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px; text-align: center;">
                                    <strong>23</strong>
                                </td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px; text-align: center;">
                                    No</td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px; text-align: center;">
                                    Yes</td>
                            </tr>

                            <tr>
                                <td
                                    style="width: 3%; padding:2px 3px; text-align: center;  border: solid 1px #3b3b3b; vertical-align: top;">
                                    24
                                </td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px;">Allotment
                                    letter of accommodation from
                                    Government </td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px; text-align: center;">
                                    <strong>24</strong>
                                </td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px; text-align: center;">
                                    No</td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px; text-align: center;">
                                    Yes</td>
                            </tr>

                            <tr>
                                <td
                                    style="width: 3%; padding:2px 3px; text-align: center;  border: solid 1px #3b3b3b; vertical-align: top;">
                                    25
                                </td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px;">Passport of
                                    spouse bearing name of the
                                    person
                                </td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px; text-align: center;">
                                    <strong>25</strong>
                                </td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px; text-align: center;">
                                    No</td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px; text-align: center;">
                                    Yes</td>
                            </tr>

                            <tr>
                                <td
                                    style="width: 3%; padding:2px 3px; text-align: center;  border: solid 1px #3b3b3b; vertical-align: top;">
                                    26
                                </td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px;">Property tax
                                    payment receipt (Not more
                                    than
                                    one year old) </td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px; text-align: center;">
                                    <strong>26</strong>
                                </td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px; text-align: center;">
                                    No</td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px; text-align: center;">
                                    Yes</td>
                            </tr>

                            <tr>
                                <td style="padding:2px 30px 2px 3px; border: solid 1px #3b3b3b; vertical-align: top; text-align: left;"
                                    colspan="2">
                                    <strong>B. For Association of persons (Trusts)</strong>
                                </td>
                                <td style="background: #d5d3d3; border: solid 1px #3b3b3b; padding: 2px 3px;">
                                </td>
                                <td style="background: #d5d3d3; border: solid 1px #3b3b3b; padding: 2px 3px;">
                                </td>
                                <td style="background: #d5d3d3; border: solid 1px #3b3b3b; padding: 2px 3px;">
                                </td>
                            </tr>

                            <tr>
                                <td
                                    style="width: 3%; padding:2px 3px; text-align: center;  border: solid 1px #3b3b3b; vertical-align: top;">
                                </td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px;">Copy of trust
                                    deed or copy of certificate
                                    of
                                    registration issued by
                                    Charity Commissioner</td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px; text-align: center;">
                                    <strong>27</strong>
                                </td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px; text-align: center;">
                                    Yes</td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px; text-align: center;">
                                    Yes</td>
                            </tr>

                            <tr>
                                <td style="padding:2px 30px 2px 3px; border: solid 1px #3b3b3b; vertical-align: top; text-align: left;"
                                    colspan="2">
                                    <strong>C. For Association of persons (other than Trusts) or Body of
                                        Individuals or Local
                                        authority or Artificial Juridical Person)</strong>
                                </td>
                                <td style="background: #d5d3d3; border: solid 1px #3b3b3b; padding: 2px 3px;">
                                </td>
                                <td style="background: #d5d3d3; border: solid 1px #3b3b3b; padding: 2px 3px;">
                                </td>
                                <td style="background: #d5d3d3; border: solid 1px #3b3b3b; padding: 2px 3px;">
                                </td>
                            </tr>

                            <tr>
                                <td
                                    style="width: 3%; padding:2px 3px; text-align: center;  border: solid 1px #3b3b3b; vertical-align: top;">
                                </td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px;">Copy of
                                    Agreement or copy of certificate
                                    of
                                    registration issued by Charity
                                    commissioner or Registrar of Cooperative society or any other
                                    competent
                                    authority or any other document originating from any Central or
                                    State
                                    Government Department establishing identity and address of such
                                    person. </td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px; text-align: center;">
                                    <strong>28</strong>
                                </td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px; text-align: center;">
                                    Yes</td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px; text-align: center;">
                                    Yes</td>
                            </tr>

                            <tr>
                                <td style="padding:2px 30px 2px 3px; border: solid 1px #3b3b3b; vertical-align: top; text-align: left;"
                                    colspan="2">
                                    <strong> D. For a foreign company </strong>
                                </td>
                                <td style="background: #d5d3d3; border: solid 1px #3b3b3b; padding: 2px 3px;">
                                </td>
                                <td style="background: #d5d3d3; border: solid 1px #3b3b3b; padding: 2px 3px;">
                                </td>
                                <td style="background: #d5d3d3; border: solid 1px #3b3b3b; padding: 2px 3px;">
                                </td>
                            </tr>

                            <tr>
                                <td
                                    style="width: 3%; padding:2px 3px; text-align: center;  border: solid 1px #3b3b3b; vertical-align: top;">
                                    1
                                </td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px;">Copy of
                                    Certificate of Registration or
                                    incorporation issued in the country where
                                    the applicant is located, duly attested by authorised officials of
                                    IFSC banking
                                    unit </td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px; text-align: center;">
                                    <strong>29</strong>
                                </td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px; text-align: center;">
                                    Yes</td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px; text-align: center;">
                                    Yes</td>
                            </tr>

                            <tr>
                                <td
                                    style="width: 3%; padding:2px 3px; text-align: center;  border: solid 1px #3b3b3b; vertical-align: top;">
                                    2
                                </td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px;">Copy of tax
                                    identification number issued
                                    in
                                    the country where the applicant is
                                    located, duly attested by authorised officials of IFSC banking unit
                                </td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px; text-align: center;">
                                    <strong>30</strong>
                                </td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px; text-align: center;">
                                    Yes</td>
                                <td style="border: solid 1px #3b3b3b; padding: 2px 3px; text-align: center;">
                                    Yes
                                    (if address
                                    mentioned
                                    in the same)</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <ol start="4">
                    <li>In case of a transaction in the name of a Minor, any of the above-mentioned documents as
                        proof of
                        Identity and
                        Address of any of parents/guardians of such minor shall be deemed to be the proof of
                        identity and address
                        for
                        the minor declarant, and the declaration should be signed by the parent/guardian. </li>
                </ol>
            </div>
        </div>
    </page>
    <div class="page-break"></div>
    <page size="A4" style="padding: 10px;">
        <div style="margin-top: 0px; font-size: 12px;">
            <!-- Notes -->
            <div style="margin-top: 0px;">
                <ol start="5">
                    <li style="margin-top: 5px;">For HUF any document in the name of Karta of HUF is required.
                    </li>

                    <li style="margin-top: 5px;"><span style="display: block;">In case the transaction is in
                            the name of more
                            than one person the total
                            number of persons should be
                            mentioned in
                            Sl. No. 19 and the total amount of transaction is to be filled in Sl. No. 17.</span>
                        <span style="display: block; margin: 7px 0;">In case the estimated total income in
                            column 23b exceeds the
                            maximum amount not chargeable to tax
                            the
                            person </span>
                        should apply for PAN, fill out item 22 and furnish proof of submission of application.]
                    </li>
                </ol>
            </div>
        </div>
    </page>
    
    <div class="page-break"></div>
    <page size="A4" style="padding: 10px;">
        <div class="container-fluid" style="padding:0;">

            <p style="font-size:16px; text-align:center; font-weight:600; margin-bottom:5px;">
                SELF DECLARATION OF INCOME
            </p>

            <div style="border:1px solid; margin-bottom:20px;"></div>

            <p style="font-size:12px; line-height:1.8; color:#000;">

                I, <span style="display:inline-block; min-width:100px; border-bottom:1px solid #000;">
                    {{ isset($borrower_details['basic']['name']) ? $borrower_details['basic']['name'] : '' }}
                </span>, son/daughter/wife/husband of <span style="display:inline-block; min-width:200px; border-bottom:1px solid #000;">
                    {{ isset($borrower_details['basic']['father_name']) ? $borrower_details['basic']['father_name'] : '' }}
                </span>, residing at <span style="display:inline-block; min-width:300px; border-bottom:1px solid #000;"></span>, hereby declare that I am currently engaged in <span style="display:inline-block; min-width:200px; border-bottom:1px solid #000;"></span>
                (Occupation – e.g., E-rickshaw Driver / Delivery Partner / Self-employed).

                <br /><br />

                My Aadhaar Number is
                <span style="display:inline-block; min-width:200px; border-bottom:1px solid #000;"></span>.

                <br /><br />

                I earn my livelihood through the above-mentioned occupation and my
                average monthly income is approximately INR
                <span style="display:inline-block; min-width:150px; border-bottom:1px solid #000;"></span>.

                <br /><br />

                <strong style="font-weight: normal;">Declaration:</strong><br />

                • I do not have formal income proof such as salary slips or income tax returns.<br />
                • The income stated above is true and correct to the best of my knowledge and belief.<br />
                • I am submitting this declaration for the purpose of Loan Application / Financial Assistance /
                Verification.<br />
                • I hereby give my consent for digital verification, Aadhaar authentication, and e-signature for
                processing my application.

                <br /><br />

                I take full responsibility for the correctness of the above information. Any discrepancy found may lead
                to rejection of my application or legal action.

                <br /><br />

                Date:
                <span style="display:inline-block; min-width:150px; border-bottom:1px solid #000;"></span><br />

                Place:
                <span style="display:inline-block; min-width:150px; border-bottom:1px solid #000;"></span><br /><br />

                Signature:
                <span style="display:inline-block; min-width:200px; border-bottom:1px solid #000;"></span><br /><br />

                Name:
                <span style="display:inline-block; min-width:200px; border-bottom:1px solid #000;"></span>

            </p>

        </div>
    </page>

    <div class="page-break"></div>
    <page size="A4" style="padding: 10px;">
        <div class="running-header">Deed of Guarantee — Individual Guarantor — EV Vehicle Loan</div>

        <h1 class="doc-title">DEED OF GUARANTEE</h1>
        <p class="doc-subtitle">(For an Individual Guarantor — Electric Vehicle Loan)</p>

        <p class="act-note">The Indian Contract Act, 1872 (Sections 126-147) and the applicable RBI Fair Practices
            Code / Master Direction on guarantor protections.</p>

        <p class="body-text">THIS DEED OF GUARANTEE is executed at [PLACE] on this [DAY] day of [MONTH], [YEAR],</p>

        <p class="label-line">BY</p>

        <p class="body-text">{{ $guarantor_details['basic']['name'] ?? '____________________________' }}
            {{-- Guarantor Name --}}, son/daughter/wife of
            {{ $guarantor_details['basic']['father_name'] ?? ($guarantor_details['basic']['husband_name'] ?? '____________________________') }}{{-- Guarantor father or husband --}},
            aged ________{{-- [AGE] --}} years,
            residing at
            {{ isset($guarantor_details['address']['current_address']) ? $guarantor_details['address']['current_address'] : '_____________________________________________________' }}
            {{-- [GUARANTOR ADDRESS] --}}, holding PAN
            {{ isset($guarantor_details['basic']['pan']) ? $guarantor_details['basic']['pan'] : '_______________' }}
            {{-- [PAN] --}} and Aadhaar
            {{ isset($guarantor_details['basic']['aadhar']) ? $guarantor_details['basic']['aadhar'] : '_______________' }}
            {{-- [AADHAAR — last 4 digits only] --}} (hereinafter
            referred to as the &ldquo;Guarantor&rdquo;, which expression shall, unless repugnant to the context, include
            his/her legal heirs, executors and administrators),</p>

        <p class="label-line">IN FAVOUR OF</p>

        <p class="body-text">
            {{ !empty($lender_details['company_name']) ? $lender_details['company_name'] : '______________________________________________' }}
            {{-- [LENDER NAME] --}}, a company/NBFC/bank registered with / regulated by the Reserve Bank of India under
            CoR No.
            {{ !empty($lender_details['licence_no']) ? $lender_details['licence_no'] : '_______________________________' }}{{-- [RBI CoR NUMBER / LICENCE NO.] --}},
            having its registered office / branch office at
            {{ !empty($lender_details['address']) ? $lender_details['address'] : '_________________________________________________________________________________' }}
            {{-- [REGISTERED OFFICE / BRANCH ADDRESS] --}} (hereinafter referred to as the &ldquo;Lender&rdquo;, which expression shall include
            its successors and assigns).</p>

        <h2 class="section-heading">RECITALS</h2>

        <p class="body-text"><b>A.</b> At the request of
            {{ isset($borrower_details['basic']['name']) ? $borrower_details['basic']['name'] : '_____________________________________' }}
            {{-- [BORROWER NAME] --}} (the &ldquo;Borrower&rdquo;), the Lender has agreed to grant / has granted the
            Borrower a loan of Rs. {{ $loan_details['loan_amount'] ?? '_______________' }}/-{{-- [LOAN AMOUNT] --}}
            (Rupees
            {{ !empty($loan_details['loan_amount_in_words']) ? $loan_details['loan_amount_in_words'] : '________________________________________________________' }}
            {{-- [AMOUNT IN WORDS] --}} only) (the &ldquo;Loan&rdquo;) for the purpose of purchasing the electric vehicle
            described in the Schedule (the &ldquo;Vehicle&rdquo;), under a Loan-cum-Hypothecation Agreement dated
            {{ !empty($loan_details['hypothication_date']) ? $loan_details['hypothication_date'] : '______' }}
            {{-- [DATE] --}} bearing Loan/Agreement No. {{ $loan_details['borrower_id'] ?? '______________' }}
            {{-- [LOAN NO.] --}} (the &ldquo;Loan Agreement&rdquo;), further particulars of which are set out in the
            Schedule to this Deed.</p>

        <p class="body-text"><b>B.</b> As a pre-condition to sanctioning the Loan, the Lender has required that the
            Guarantor guarantee the due repayment of the Loan and the due performance of the Borrower's obligations
            under the Loan Agreement, and the Guarantor has agreed to do so on the terms of this Deed.</p>

        <p class="body-text"><b>C.</b> The Vehicle shall stand hypothecated to the Lender as primary security for the
            Loan under the Loan Agreement; this Deed is additional, and not alternative, security.</p>

        <p class="body-text"><b>D.</b> The Guarantor confirms that the nature and extent of this guarantee — including
            the maximum liability assumed and the consequences of default by the Borrower — has been explained to the
            Guarantor in ____________________________[VERNACULAR LANGUAGE], a language understood by the Guarantor, and
            that the Guarantor has executed this Deed voluntarily, with full understanding of its contents, and without
            coercion, undue
            influence or misrepresentation.</p>

        <h2 class="section-heading">NOW THIS DEED WITNESSES AS FOLLOWS</h2>

        <h2 class="section-heading">1. Guarantee</h2>
        <p class="body-text"><strong>1.1</strong> The Guarantor hereby unconditionally and irrevocably guarantees to
            the Lender the due and punctual payment of all sums payable by the Borrower under the Loan Agreement —
            including principal, interest, penal/default charges, and reasonable costs and expenses of recovery — and
            the due performance and observance by the Borrower of all obligations under the Loan Agreement, up to a
            maximum aggregate amount of ₹[MAXIMUM GUARANTEE AMOUNT — should not exceed the sanctioned Loan amount plus
            reasonably foreseeable interest/charges] (Rupees [AMOUNT IN WORDS] only) (the &ldquo;Guaranteed
            Amount&rdquo;).</p>
    </page>

    <div class="page-break"></div>
    <page size="A4" style="padding: 10px;">
        <div class="running-header">Deed of Guarantee — Individual Guarantor — EV Vehicle Loan</div>

        <p class="body-text"><strong>1.2</strong> This is a guarantee of payment and performance. The Guarantor's
            liability is co-extensive with that of the Borrower under Section 128 of the Indian Contract Act, 1872, save
            as expressly capped by the Guaranteed Amount and the other terms of this Deed.</p>

        <h2 class="section-heading">2. Nature of the Guarantee</h2>
        <p class="body-text"><strong>2.1</strong> This is a continuing guarantee and shall remain in force until the
            Loan, together with all interest and other charges thereon, has been repaid in full and the Lender has
            issued a written discharge/No Objection Certificate to the Guarantor.</p>
        <p class="body-text"><strong>2.2</strong> In accordance with Section 130 of the Indian Contract Act, 1872, the
            Guarantor may revoke this guarantee as to future advances/transactions by giving the Lender not less than
            [NOTICE PERIOD, e.g., 30] days' prior written notice; such revocation shall not discharge the Guarantor from
            liability already accrued in respect of amounts disbursed to the Borrower prior to the date the revocation
            takes effect.</p>
        <p class="body-text"><strong>2.3</strong> This guarantee shall continue and remain in full force
            notwithstanding the discharge of the Borrower by operation of law, or the insolvency, bankruptcy, winding-up
            or dissolution of the Borrower (where the Borrower is a firm or company), and shall cease only on full
            payment of the amount guaranteed hereunder, whether paid by the Borrower or the Guarantor.</p>

        <h2 class="section-heading">3. Hypothecation of the Vehicle and the Guarantor's Position</h2>
        <p class="body-text"><strong>3.1</strong> The Guarantor acknowledges that the Vehicle stands hypothecated to
            the Lender as primary security and that the Lender may, on default and after following the repossession
            procedure prescribed by Applicable Law and its Fair Practices Code, repossess and sell the Vehicle and apply
            the net sale proceeds towards the amount due under the Loan Agreement.</p>
        <p class="body-text"><strong>3.2</strong> The Guarantor's liability under this Deed shall apply only to the
            shortfall (if any) remaining after such adjustment, up to the Guaranteed Amount, unless the Guarantor is
            also the registered owner/co-owner of the Vehicle and separately liable in that capacity.</p>
        <p class="body-text"><strong>3.3</strong> In accordance with Sections 140 and 141 of the Indian Contract Act,
            1872, the Guarantor shall not be entitled to claim the benefit of, or step into, the Lender's security over
            the Vehicle or any other security held by the Lender, until the Lender has received payment in full of all
            amounts due under the Loan Agreement; thereafter, the Guarantor shall be subrogated to the rights of the
            Lender to the extent of the amount paid by the Guarantor.</p>

        <h2 class="section-heading">4. Lender's Rights</h2>
        <p class="body-text"><strong>4.1</strong> The Lender shall be entitled to enforce this guarantee against the
            Guarantor without first exhausting its remedies against the Borrower or the Vehicle, and without being
            obliged to proceed against the Borrower first, in accordance with Section 128 of the Indian Contract Act,
            1872.</p>
        <p class="body-text" style="margin-bottom:0;"><strong>4.2</strong> The Lender's rights under this Deed shall
            not be affected by any time, indulgence or concession given to the Borrower, or by any variation of the
            terms of the Loan Agreement made with the Borrower's consent, or by the Lender's forbearance or delay in
            enforcing the Loan Agreement — provided that any variation that materially increases the Guarantor's
            liability beyond the Guaranteed Amount shall require the Guarantor's separate prior written consent.</p>

    </page>
    <div class="page-break"></div>
    <page size="A4" style="padding: 10px;">
        <div class="running-header">Deed of Guarantee — Individual Guarantor — EV Vehicle Loan</div>

        <h2 class="section-heading" style="margin-top:0;">5. Notice to Guarantor and Right to Information</h2>
        <p class="body-text"><strong>5.1</strong> The Lender shall, before invoking this guarantee, issue a written
            notice of default to the Guarantor at the address on record, specifying the amount in default and allowing a
            reasonable opportunity to make payment or regularise the account, consistent with the Lender's Fair
            Practices Code.</p>
        <p class="body-text"><strong>5.2</strong> The Guarantor shall be entitled, on request, to obtain from the
            Lender information regarding the outstanding amount, repayment status and the extent of default by the
            Borrower, to the extent such disclosure is permissible under Applicable Law and does not breach the
            Borrower's right to privacy beyond what is necessary to apprise the Guarantor of the guaranteed liability.
        </p>
        <p class="body-text"><strong>5.3</strong> Before repossessing the Vehicle, the Lender shall follow the
            repossession and sale notice procedure applicable under its Fair Practices Code, and shall account to the
            Guarantor and the Borrower for the sale proceeds.</p>
        <p class="body-text"><strong>5.4</strong> Any notice or demand under this Deed shall be in writing and shall
            be deemed to have been duly served on the Guarantor if delivered at the address stated in this Deed, or, if
            sent by registered post/speed post/courier or by electronic means (e-mail/SMS) to the address, e-mail or
            mobile number on record, shall be deemed to have been received 48 hours after dispatch, unless returned
            undelivered earlier to the Lender's knowledge.</p>

        <h2 class="section-heading">6. Guarantor's Representations</h2>
        <p class="body-text"><strong>6.1</strong> The Guarantor represents that he/she is a major, of sound mind,
            competent to contract, and is entering into this Deed of his/her own free will, having independently
            assessed the risk of standing guarantee for the Borrower.</p>
        <p class="body-text"><strong>6.2</strong> The Guarantor confirms that all KYC information, income details and
            other particulars furnished to the Lender are true, correct and complete.</p>

        <h2 class="section-heading">7. Discharge of Guarantee</h2>
        <p class="body-text"><strong>7.1</strong> This guarantee shall stand discharged upon full and final repayment
            of all amounts due under the Loan Agreement, upon the Lender's written release of the Guarantor, or by
            operation of Applicable Law — including discharge of the Guarantor's liability where the Lender materially
            varies the Loan Agreement without the Guarantor's consent, under Section 133 of the Indian Contract Act,
            1872, to the extent applicable.</p>

        <h2 class="section-heading">8. Assignment</h2>
        <p class="body-text"><strong>8.1</strong> The Lender may assign or transfer the benefit of this guarantee
            together with its rights under the Loan Agreement, in accordance with Applicable Law, without the
            Guarantor's consent, provided the Guarantor is notified of such assignment.</p>
        <p class="body-text"><strong>8.2</strong> This guarantee shall remain enforceable notwithstanding any change
            in the Lender's name, or any amalgamation, merger, reconstitution or corporate restructuring of the Lender,
            and shall enure for the benefit of any entity with which the Lender may amalgamate or merge, or to which the
            Lender may assign its rights under Clause 8.1.</p>

        <h2 class="section-heading">9. Governing Law and Jurisdiction</h2>
        <p class="body-text" style="margin-bottom:0;"><strong>9.1</strong> This Deed shall be governed by the laws
            of India. Disputes shall be subject to the arbitration and/or jurisdiction clause of the Loan Agreement,
            applied mutatis mutandis to the Guarantor. Nothing in this</p>

    </page>
    <div class="page-break"></div>
    <page size="A4" style="padding: 10px;">
        <div class="running-header">Deed of Guarantee — Individual Guarantor — EV Vehicle Loan</div>

        <p class="body-text">Deed excludes the Guarantor's right to approach the RBI Ombudsman, a consumer forum, or
            any other forum where such right cannot lawfully be excluded.</p>

        <h2 class="section-heading">10. Mode of Execution — Aadhaar-based Electronic Signature</h2>
        <p class="body-text"><strong>10.1</strong> The Guarantor may, at his/her option and with his/her free and
            specific consent, execute this Deed by way of an Aadhaar-based electronic signature (&ldquo;e-Sign&rdquo;)
            obtained through a UIDAI-licensed Electronic Signature Service Provider, in accordance with the Information
            Technology Act, 2000 (including the Second Schedule thereto) and the Aadhaar (Targeted Delivery of Financial
            and Other Subsidies, Benefits and Services) Act, 2016 and the rules/regulations framed thereunder. An
            electronic signature so affixed shall have the same legal effect, and be as valid and binding, as a
            physical/wet-ink signature.</p>
        <p class="body-text"><strong>10.2</strong> Aadhaar-based authentication is entirely voluntary. The Guarantor
            may, in the alternative, choose to execute this Deed by physical/wet-ink signature, and the sanction or
            disbursement of the Loan, and the validity of this guarantee, shall not be made conditional upon the
            Guarantor's use of Aadhaar-based e-Sign.</p>
        <p class="body-text"><strong>10.3</strong> The Guarantor's Aadhaar number, where used solely for e-Sign
            authentication, shall not be stored by the Lender in its systems except in redacted/masked form or as
            otherwise permitted under Applicable Law, and shall be used strictly for identity verification and execution
            of this Deed and for no other purpose.</p>

        <h2 class="section-heading">11. Credit Information Reporting</h2>
        <p class="body-text"><strong>11.1</strong> The Guarantor expressly consents that the Lender may, in accordance
            with the Credit Information Companies (Regulation) Act, 2005, the rules/regulations framed thereunder and
            applicable RBI directions, disclose and report to Credit Information Companies (&ldquo;CICs&rdquo;) —
            including TransUnion CIBIL, Equifax, Experian and CRIF High Mark — the Guarantor's personal and credit
            information, including the existence, extent and status of this guarantee, any default by the Borrower,
            invocation of this guarantee, and the Guarantor's repayment/settlement conduct hereunder.</p>
        <p class="body-text"><strong>11.2</strong> Such information shall be reported and reflected under the
            Guarantor's own credit profile as a distinct &ldquo;guarantor&rdquo; facility/contingent liability, and not
            as a direct borrowing by the Guarantor, and may affect the Guarantor's credit score and future
            creditworthiness assessment, including in respect of the Guarantor's own loan or credit applications.</p>
        <p class="body-text"><strong>11.3</strong> The Guarantor shall have the right to access his/her credit
            information report and to seek correction of any inaccurate reporting relating to this guarantee, through
            the grievance redressal mechanism prescribed under the Credit Information Companies (Regulation) Act, 2005
            and the Lender's/CIC's internal grievance redressal policy.</p>

        <h2 class="section-heading">SCHEDULE</h2>

        <table class="schedule">
            <tr>
                <td class="label">Borrower</td>
                <td>{{ isset($borrower_details['basic']['name']) ? $borrower_details['basic']['name'] : '' }}{{-- [BORROWER NAME] --}}
                </td>
            </tr>
            <tr>
                <td class="label">Guarantor</td>
                <td>{{ isset($guarantor_details['basic']['name']) ? $guarantor_details['basic']['name'] : '' }}
                    {{-- [GUARANTOR NAME] --}}</td>
            </tr>
            <tr>
                <td class="label">Loan Agreement No. / Date</td>
                <td>{{ $loan_details['borrower_id'] ?? '___________________' }} / _____________________</td>
            </tr>
            <tr>
                <td class="label">Loan Amount</td>
                <td>Rs.{{ !empty($loan_details['loan_amount']) ? $loan_details['loan_amount'] : '______________________' }}/-
                </td>
            </tr>
            <tr>
                <td class="label">Guaranteed Amount (max.)</td>
                <td>Rs.______________________________</td>
            </tr>
            <tr>
                <td class="label">Vehicle — Make &amp; Model</td>
                <td>{{ !empty($loan_details['model']) ? $loan_details['model'] : '______________________' }}</td>
            </tr>
        </table>
    </page>
    <div class="page-break"></div>
    <page size="A4" style="padding: 10px;">
        <div class="running-header">Deed of Guarantee — Individual Guarantor — EV Vehicle Loan</div>

        <table class="schedule" style="margin-bottom:18px;">
            <tr>
                <td class="label">Vehicle Type</td>
                <td>[2-Wheeler / 3-Wheeler / 4-Wheeler EV]</td>
            </tr>
            <tr>
                <td class="label">Chassis No.</td>
                <td>_____________________________ {{-- [CHASSIS NUMBER] --}}</td>
            </tr>
            <tr>
                <td class="label">Motor/Battery Sl. No.</td>
                <td>_____________________________ {{-- [MOTOR OR BATTERY SERIAL NUMBER] --}} </td>
            </tr>
            <tr>
                <td class="label">Registration No. (if allotted)</td>
                <td>_____________________________ {{-- [VEHICLE REGISTRATION NUMBER] --}} </td>
            </tr>
            <tr>
                <td class="label">Invoice Value of Vehicle</td>
                <td>Rs._____________________________ {{-- [INVOICE VALUE] --}}</td>
            </tr>
            <tr>
                <td class="label">Insurance Policy No. &amp; Insurer</td>
                <td>___________________ {{-- [INVOICE VALUE] --}} — {{-- [INSURER NAME] --}}</td>
            </tr>
            <tr>
                <td class="label">Hypothecation noted with RTO</td>
                <td>Yes / No — ___________________[RTO NAME]</td>
            </tr>
        </table>

        <h2 class="section-heading" style="margin-top:0;">IN WITNESS WHEREOF</h2>

        <p class="body-text">the Guarantor has set his/her hand to this Deed on the day, month and year first above
            written, in the presence of the witnesses below.</p>

        <p class="plain-line" style="margin-bottom:20px;">Mode of execution (tick one): [ ] Physical/wet-ink
            signature&nbsp;&nbsp;&nbsp;[ ] Aadhaar-based e-Sign (with e-Sign audit trail annexed)</p>

        <p class="plain-line"><strong>Guarantor's Signature:</strong> __________________________</p>
        <p class="plain-line"><strong>Name:</strong>
            {{ !empty($guarantor_details['basic']['name'])
                ? $guarantor_details['basic']['name']
                : '__________________________________________' }}
            {{-- [GUARANTOR NAME] --}}</p>
        <p class="plain-line" style="margin-bottom:22px;"><strong>Address:</strong>
            {{ isset($guarantor_details['address']['current_address']) ? $guarantor_details['address']['current_address'] : '___________________________________________________' }}{{-- [ADDRESS] --}}
        </p>

        <p class="plain-line"><strong>Accepted for and on behalf of the Lender</strong></p>
        <p class="plain-line"><strong>Signature:</strong> __________________________</p>
        <p class="plain-line"><strong>Name:</strong> _________________________________________</p>
        <p class="plain-line" style="margin-bottom:0;"><strong>Designation:</strong> _____________________________</p>

    </page>
    <div class="page-break"></div>
    <page size="A4" style="padding: 10px;">

        <h1 class="doc-title">CONSENT LETTER FOR SHARING OF DATA</h1>
        <hr class="title-rule">

        <p class="date-line"><strong>Date:</strong> <span class="blank">________________________</span>
            (DD/MM/YYYY)</p>

        <div class="callout">
            <p>To,</p>
            <p><strong>1.
                    {{ !empty($lender_details['company_name']) ? $lender_details['company_name'] : '______________________________________________' }}</strong>
                <em>(Financier / NBFC)</em><br>
                Registered Office Address: <span
                    class="blank">________________________________________________________________</span>
            </p>
            <p class="party-gap"><strong>2. Kilpi Technology and Services Private Limited (Finayo)</strong>
                <em>(Digital Lending App / LSP)</em><br>
                Registered Office Address: <span class="blank">Finayo, Ground Floor, H-57, H Block, Workspace by
                    Innova, Sector 63, Noida, Uttar Pradesh - 201309</span>
            </p>
        </div>

        <div class="callout" style="border: none;">
            <p><strong>Subject:</strong> Consent for collecting, storing, processing, and sharing of data under the loan
                obligations in connection with Application No. <span
                    class="blank">________________________________________</span>
            </p>
        </div>

        <p class="body-text">Dear Sir/Madam,</p>

        <p class="body-text">I, the undersigned customer(s) (&ldquo;Data Principal&rdquo;), hereby provide my informed
            and valid consent to <span class="blank">________________________________________</span>
            (&ldquo;Data Fiduciary&rdquo; / OEM) to collect, store, process, and share my personal data and electric
            vehicle (EV)-generated telemetry data to the extent required under the loan agreement and ancillary
            documents (&ldquo;Loan Documents&rdquo;).</p>

        <p class="body-text">I acknowledge and understand that the loan facility for the purchase and installation of
            my EV and EV infrastructure is provided by
            <strong>{{ !empty($lender_details['company_name']) ? $lender_details['company_name'] : '_____________________________________' }}</strong>
            (&ldquo;Financier
            / NBFC&rdquo;), and that <strong>Kilpi Technology and Services Private Limited (Finayo)</strong> acts as the
            official <strong>Digital Lending App (DLA) and Loan Service Provider (LSP)</strong> which processes the
            entire loan application and manages asset and collateral monitoring on behalf of
            {{ !empty($lender_details['company_name']) ? $lender_details['company_name'] : '_____________________________________' }}.
        </p>

        <p class="body-text">I hereby authorize the Data Fiduciary to share my personal and EV-generated data,
            including telemetry data, with <strong>Finayo</strong> and
            <strong>{{ !empty($lender_details['company_name']) ? $lender_details['company_name'] : '_____________________________________' }}</strong>.
            The data collected from me, my EV, and my EV infrastructure (collectively referred to as &ldquo;My
            Data&rdquo;) includes, but is not limited to:</p>

        <ol class="data-list">
            <li>My Name, Address, Age, Government ID Proof, and financial credibility documents.</li>
            <li>Vehicle GPS location data.</li>
            <li>Distance travelled / kilometers covered.</li>
            <li>Battery status, health, and diagnostics.</li>
            <li>Vehicle identification data (VIN / Chassis Number / Engine or Motor Number).</li>
            <li>Any other data collected with my explicit consent.</li>
        </ol>

        <h2 class="section-heading">1. Purpose</h2>
        <p class="body-text" style="margin-bottom:6px;">My Data shall be used solely for the following purposes:</p>

        <ol class="lettered-list">
            <li><span class="tag">(a)</span><span><strong>Collateral &amp; Asset Management:</strong> To allow
                    Finayo (on behalf of
                    {{ !empty($lender_details['company_name']) ? $lender_details['company_name'] : '_____________________________________' }})
                    to monitor vehicle condition and track GPS location in real-time
                    if I fail to pay the Equated Monthly Instalment (EMI) after seven (7) working days from the EMI due
                    date.</span></li>
            <li><span class="tag">(b)</span><span><strong>Credit Risk Management:</strong> To identify potential
                    defaults, assess risk, process payments, and execute recovery requirements on behalf of the
                    Financier in case of non-payment.</span></li>
            <li style="margin-bottom:0;"><span class="tag">(c)</span><span><strong>Subsidies:</strong> To
                    validate vehicle usage to claim or comply with government-linked green energy incentives and
                    subsidies.</span></li>
        </ol>
    </page>

    <div class="page-break"></div>
    <page size="A4" style="padding: 10px;">
        <ol class="lettered-list" style="margin-top:0;">
            <li style="margin-bottom:10px;"><span class="tag">(d)</span><span><strong>Asset Protection:</strong>
                    To assist in vehicle recovery in the event of theft, unauthorized tampering, or breach of the loan
                    agreement.</span></li>
        </ol>

        <h2 class="section-heading" style="margin-top:0;">2. Data Processing</h2>
        <p class="body-text">Finayo and
            {{ !empty($lender_details['company_name']) ? $lender_details['company_name'] : '_____________________________________' }}
            will store, process, and share My Data with
            necessary regulated third parties (including credit bureaus, recovery partners, or co-lending partners)
            strictly in accordance with applicable RBI Guidelines on Digital Lending, government orders, the
            <strong>Digital Personal Data Protection Act, 2023</strong>, and applicable privacy policies.
        </p>

        <h2 class="section-heading">3. Rights of the Data Principal &amp; Withdrawal</h2>
        <p class="body-text">I understand that I have the right to access, correct, or request deletion of my personal
            data, and lodge grievances regarding misuse. I may withdraw this consent by notifying Finayo (Email: <span
                class="blank">________________________________________</span>) or
            {{ !empty($lender_details['company_name']) ? $lender_details['company_name'] : '_____________________________________' }}
            (Email: <span class="blank">________________________________________</span>). I understand that withdrawal
            may impact the lender's ability to monitor the vehicle as collateral and may constitute a breach of the loan
            agreement.</p>

        <h2 class="section-heading">4. Grievance Redressal</h2>
        <p class="body-text">I can contact the Grievance Officer / Data Protection Officer at <span
                class="blank">____________________________________</span>
            for any data-related concerns or complaints.</p>

        <div class="declaration-box">
            <h2 class="section-heading">DECLARATION</h2>
            <p>I confirm that I have been informed, in a language understood by me, of the specific purposes for which
                My Data is collected, processed, stored, and shared. I fully understand the respective roles of
                {{ !empty($lender_details['company_name']) ? $lender_details['company_name'] : '_____________________________________' }}
                (Lender) and Finayo (DLA/LSP), as well as my rights as a Data Principal, and I
                voluntarily provide my explicit consent.</p>
        </div>

        <div class="sig-grid">
            <div class="sig-item"><span class="sig-label">Customer Name:</span><span class=""></span></div>
            <div class="sig-item"><span class="sig-label">Date:</span><span class=""></span></div>
            <div class="sig-item"><span class="sig-label">Signature:</span><span class="sig-fill"></span>
            </div>
            <div class="sig-item"><span class="sig-label">Place:</span><span class="sig-fill"></span></div>
        </div>
    </page>
    
    <div class="footer">
        <table style="width:100%;">
            @php
                $skipCo = $borrower_details['basic']['skip_co_applicant'] ?? true;
                $skipGuarantor = $borrower_details['basic']['skip_guarantor'] ?? true;
            @endphp
            <tr>
                <td style="text-align: center;vertical-align: top;">
                    ______________________<br>
                    {{-- <b>SB-6</b><br> --}}
                    (Signature)<br>
                    <b>For, LENDER</b><br>
                    Authorized Signatory
                </td>
                <td style="text-align:center;vertical-align:top;">
                    ______________________<br>
                    (Signature)<br>
                    <b>For, BORROWER</b>
                </td>

                @if (!$skipCo)
                    <td style="text-align:center;vertical-align:top;">
                        ______________________<br>
                        (Signature)<br>
                        <b>For, CO-BORROWER</b>
                    </td>
                @endif

                @if (!$skipGuarantor)
                    <td style="text-align:center;vertical-align:top;">
                        ______________________<br>
                        (Signature)<br>
                        <b>For, GUARANTOR</b>
                    </td>
                @endif
            </tr>
        </table>
    </div>
</body>

</html>
