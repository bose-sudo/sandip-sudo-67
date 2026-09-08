<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title>Finayo Credit Report</title>
    <style>
        body {
            font-family: monospace;
            margin: 0;
            padding: 0;
            box-shadow: none;
            width: 100%;
        }
        .json-container {
            width: 100%;
            overflow: auto;
            word-wrap: break-word;
            white-space: pre-wrap;
            margin: 0;
            padding: 0;
        }
    </style>
</head>
<body>
    <div class="json-container">
        {{isset($data) ? $data : ''}}
    </div>
</body>
</html>