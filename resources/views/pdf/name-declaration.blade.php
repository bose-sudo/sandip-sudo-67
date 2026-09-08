<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title>Name Declaration</title>
    <style>
        body {
            text-align: justify;
        }
    </style>
</head>
<body>
    <strong>Name Mismatch Declaration</strong>
    <p>I, ____________________________________________, <br> residing at ___________________________________________________________________________, hereby declare and acknowledge the following:</p>
    <ol>
        <li>
            I am the borrower associated with borrower id : {{isset($borrower_id) ? $borrower_id : ''}}
            {{-- [Loan/Account Number: ________]. --}}
        </li>
        <li>
            I understand that my name may appear differently in various official documents and records due to reasons such as formatting variations, abbreviations, or translations.
        </li>
        <li>
            The variations in my name may include but are not limited to differences in spelling, use of initials, or order of names.
        </li>
        <li>
            Notwithstanding these variations, I confirm that all such variations pertain to my identity.
        </li>
        <li>
            I hereby declare that the aforementioned discrepancies in my name across documents do not affect my eligibility, rights, or obligations under the loan agreement or any related documents.
        </li>
        <li>
            I undertake to notify the lender promptly of any changes to my name that may affect the information provided in this declaration.
        </li>
    </ol>
    <p>Date: _______________</p>
    Signature: ____________________________ (Borrower's Full Name)
</body>
</html>