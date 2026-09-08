<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title>Finayo Credit Report</title>
    <style>
        body{
            margin: auto;
            width: 30%;
            padding: 10px;
        }
        input {
            padding: 5px;
        }
    </style>
</head>
<body>
    <h1><u>Generate Credit Report:</u></h1>
    <form action="{{route('generate_credit_report')}}" method="POST">
        @csrf
        <table>
            <tbody>
                <tr>
                    <td><label for="mobile">Mobile : </label> </td>
                    <td><input type="text" name="mobile" maxlength="10" placeholder="Enter mobile" required></td>
                </tr>
                <tr>
                    <td><label for="name">Name : </label></td>
                    <td><input type="text" name="name" placeholder="Enter name" required></td>
                </tr>
                <tr>
                    <td><label for="dob">Dob : </label></td>
                    <td><input type="date" name="dob" placeholder="Enter dob" required></td>
                </tr>
                <tr>
                    <td><label for="pan">Pan : </label></td>
                    <td><input type="text" name="pan" maxlength="10" placeholder="Enter pan" required></td>
                </tr>
                <tr>
                    <td><label for="pin">Pin : </label></td>
                    <td><input type="text" name="pin" maxlength="6" placeholder="Enter pin" required></td>
                </tr>
                <tr>
                    <td><label for="access_token">Access token : </label></td>
                    <td><input type="text" name="access_token" placeholder="Enter access token" required></td>
                </tr>
                <tr>
                    <td><button style="padding: 5px;" type="submit">Generate Report</button></td>
                    <td><button style="padding: 5px; float:right;" type="reset">Reset</button></td>
                </tr>
            </tbody>
        </table>
    </form>

    <div style="padding: 10px;">
        @if(session('failed'))
            <div style="color: red;">
                {{ session('failed') }}
            </div>
        @endif
    </div>
</body>
</html>