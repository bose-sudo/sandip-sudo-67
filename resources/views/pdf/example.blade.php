<!DOCTYPE html>
<html>
<head>
    <title>Finayo</title>
    <style>
        body { 
            font-family: 'Times New Roman'; 
            margin: 0;
            padding: 0;
        }
        h1 { 
            color: #333; 
        }
        .page-break {
            page-break-before: always;
        }
        .signature-container {
            position: fixed;
            bottom: 50px; /* Adjust the value as needed */
            width: 100%;
            text-align: center;
        }
        .signature {
            display: inline-block;
            width: 100%;
            border-top: 1px solid #000;
            margin: 0 1%;
            padding-top: 5px;
        }
    </style>
</head>
<body>
    {{-- <h1>{{ $title }}</h1>
    <p>{{ $content }}</p> --}}
    <p style="font-weight: bold;"><u>Test Document Akib</u></p>
    <div style="font-weight: bold;">
        FROM: Test<br> 
        SB--1 <br>
        COMPANY NAME <br>
        COMPANY FULL ADDRESS <br>
    </div>

    <h1 style="margin-top: 50%;">
        Error
        <hr>
    </h1>
    
    <div class="signature-container">
        <table class="signature-container">
            <tr>
                <th>
                    <div class="signature">User 1</div>
                </th>
                <th>
                    <div class="signature">User 2</div>
                </th>
                <th>
                    <div class="signature">User 3</div>
                </th>
            </tr>
        </table>
    </div>  
<div class="page-break"></div>

<p style="font-weight: bold;"><u>Test Document</u></p>
    <div style="font-weight: bold;">
        FROM: Test<br> 
        SB--1 <br>
        COMPANY NAME <br>
        COMPANY FULL ADDRESS <br>
    </div>

    <h1 style="margin-top: 50%;">
        Error
        <hr>
    </h1>
    <div class="signature-container">
        <table class="signature-container">
            <tr>
                <th>
                    <div class="signature">User 1</div>
                </th>
                <th>
                    <div class="signature">User 2</div>
                </th>
                <th>
                    <div class="signature">User 3</div>
                </th>
            </tr>
        </table>
</div>  
        
</body>
</html>
