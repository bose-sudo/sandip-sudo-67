<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FINAYO EMI RECEIPT</title>

    <style>
        /* style="font-family: Arial, sans-serif; max-width: 800px; margin: 0 auto; padding: 20px; line-height: 1.4;" */
        body {
            /* background: rgb(226, 226, 226);  */
            margin: 0;
            box-shadow: 0;
            padding: 0;
            font-size: 14px;
            line-height: 1.2;
            font-family: 'Arial, sans-serif'; /*'Times New Roman';*/
            /* text-align: justify; */
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
    </style>
</head>

<body>
    <page size="A4" style="padding: 10px;">
        <!-- Header with Logo -->
        <table style="width: 100%; border-collapse: collapse; margin-bottom: 20px;">
            <tr>
                <!-- Left Side: Logo + Address -->
                <td style="width: 50%; text-align: left;">
                    <img src="{{ asset('Assets/pdf-image/akme-logo-pdf.jpg') }}" alt="Akme Logo" style="height: 50px; margin-left: 10px;">
                    
                </td>

                <!-- Right Side: Second Logo -->
                <td style="width: 50%; text-align: right;">
                    <img src="{{ asset('Assets/pdf-image/finayo-logo-pdf.png') }}" alt="Finayo Logo" style="height: 50px; margin-right: 30px;">
                </td>
            </tr>
        </table>
        <table style="width: 100%; border-collapse: collapse; margin-bottom: 5px;">
            <tr>
                <!-- Left Side: Logo + Address -->
                <td style="width: 50%; text-align: left; padding: 10px;">
                    <p style="margin: 0; margin-top: 10px;">
                        Address: Akme Business Centre, 4-5 Subcity Center, Savina Circle, Udaipur 313002, Rajasthan
                    </p>
                </td>
                <!-- Right Side: Second Logo -->
                <td style="width: 50%;">
                </td>
            </tr>
        </table>


        <!-- Receipt Label -->
        {{--
        <div style="width: 150px; text-align: right;">
            <!-- <h3 style="margin: 0; color: #8B0000; font-size: 16px;">EMI RECEIPT</h3> -->
        </div>
        --}}

        {{-- <div style="display: flex; justify-content: space-between; margin-bottom: 15px; padding-bottom: 10px;">
            <div style="font-size: 14px;">
                <p style="margin: 0; margin-top: 15px;">Receipt No: {{ $receipt_no ?? '' }}</p>
            </div>
            <div style="font-size: 14px;">
                <p style="margin: 0;">Date: {{ $payment_date ?? '' }}</p>
            </div>
        </div> --}}
        <table style="width: 100%; border-collapse: collapse; margin-bottom: 15px;">
            <tr>
                <td style="font-size: 14px; width: 50%; padding: 10px;">
                    <p style="margin: 0; font-weight: bold; padding-bottom: 5px;">
                        Receipt No: {{ $receipt_no ?? '' }}
                    </p>
                </td>
                <td style="font-size: 14px; width: 50%; padding: 10px;">
                    <p style="margin: 0; font-weight: bold; padding-bottom: 5px; float: right;">
                        Date: {{ $payment_date ?? '' }}
                    </p>
                </td>
            </tr>
        </table>


        <table style="width: 100%; border-collapse: collapse; margin-bottom: 15px;">
            <tr>
                <td style="font-size: 14px; width: 50%; border: 1px solid #ccc; padding: 10px; background-color: #f9f9f9;">
                    <p style="margin: 0; font-weight: bold; border-bottom: 1px solid #ccc; padding-bottom: 5px;">
                        Lender & DLA Information:
                    </p>
                    <p style="margin: 5px 0;"><strong>Lender Name:</strong> {{ $lender_name ?? '' }}</p>
                    <p style="margin: 5px 0;"><strong>Digital Loan App:</strong> FINAYO</p>
                    <p></p>
                </td>

                <td style="font-size: 14px; width: 50%; border: 1px solid #ccc; padding: 10px; background-color: #f9f9f9;">
                    <p style="margin: 0; font-weight: bold; border-bottom: 1px solid #ccc; padding-bottom: 5px;">
                        Borrower Information:
                    </p>
                    <p style="margin: 5px 0;"><strong>Name:</strong> {{ $borrower_name ?? '' }}</p>
                        <p style="margin: 5px 0;"><strong>Mobile:</strong> {{ $borrower_mobile ?? ''}}</p>
                    <p style="margin: 5px 0;"><strong>Loan A/c Number:</strong> {{ $loan_account_number }} / {{ $borrower_fin_id }}</p>
                </td>
            </tr>
        </table>


        <div style="margin-bottom: 15px;">
            <h3
                style="font-size: 16px; margin: 10px 0; text-align: center; background-color: #e6e6e6; padding: 5px; border: 1px solid #ccc;">
                EMI Payment Details</h3>
            <table style="width: 100%; border-collapse: collapse; font-size: 14px;">
                <thead>
                    <tr style="background-color: #f0f0f0;">
                        <th style="border: 1px solid #000; padding: 8px; text-align: center;">Loan Tenure</th>
                        <th style="border: 1px solid #000; padding: 8px; text-align: center;">EMI Amount (Rs.)</th>
                        <th style="border: 1px solid #000; padding: 8px; text-align: center;">EMI Due Date</th>
                        <th style="border: 1px solid #000; padding: 8px; text-align: center;">Payment Received Date</th>
                        <th style="border: 1px solid #000; padding: 8px; text-align: center;">Payment
                            Mode<br>(Cash/UPI/NEFT)<br>(UTR No.)</th>
                        <th style="border: 1px solid #000; padding: 8px; text-align: center;">Installment Number</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td style="border: 1px solid #000; padding: 8px; text-align: center;">{{ $loan_tenure ?? '' }}</td>
                        <td style="border: 1px solid #000; padding: 8px; text-align: center; font-weight: bold;">{{ $emi_amount ?? ''}}
                        </td>
                        <td style="border: 1px solid #000; padding: 8px; text-align: center;">{{ $due_date ?? ''}}</td>
                        <td style="border: 1px solid #000; padding: 8px; text-align: center;">{{ $payment_received_date ?? ''}}</td>
                        <td style="border: 1px solid #000; padding: 8px; text-align: center;">{{ $payment_mode ?? ''}} <br> {{$utr_number ?? '-'}}</td>
                        <td style="border: 1px solid #000; padding: 8px; text-align: center;">{{$number_of_emi ?? ''}} Installment
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div
            style="display: flex; justify-content: space-between; margin-bottom: 15px; font-size: 14px; padding: 10px 0;">
            <div>
                <p style="margin: 20px 0;">Total Amount Received: <span style="font-weight: bold;">Rs. {{ $total_amount_collected ?? ''}}/-</span></p>
                <p style="margin: 20px 0;">(In words): <span style="font-weight: bold;">{{ $total_amount_in_words ?? ''}} rupees.</span></p>
            </div>
            <div>
                <p style="margin: 20px 0;">Remarks: {{$remarks ?? ''}}</p>
            </div>
        </div>

        <div style="display: flex; justify-content: space-between; margin-top: 40px; font-size: 14px;">
            {{-- <div style="text-align: left; width: 60%;">
                <p style="margin: 0;">Received By: {{ $received_by ?? ''}}</p>
                <div style="margin-top: 50px; width: 80%; margin-left: auto; margin-right: auto;"></div>
            </div> --}}
            {{-- <div style="text-align: center; width: 36%;">
                <p style="margin: 0; margin-left: 25px;">Signature: {{ $signature ?? ''}}</p>
                <div style="margin-top: 50px; width: 80%; margin-left: auto; margin-right: auto;"></div>
            </div> --}}
        </div>

        <div style="text-align: center; margin-top: 30px; font-size: 12px; padding-top: 10px;">
            <p style="margin: 0;">THIS IS COMPUTER GENERATED RECEIPT AND DOES NOT REQUIRED A SIGNATURE OR STAMP.
            </p>
        </div>

        <div style="position: absolute; bottom: 10px; right: 10px; font-size: 12px;">
            <p style="margin: 0;"> 
                @php
                    date_default_timezone_set('Asia/Kolkata');
                @endphp
                {{ date("d/m/Y H:i:s") }}
            </p>
        </div>

        </div>
    </page>
</body>

</html>
