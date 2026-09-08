<!DOCTYPE html>
<html>
<head>
    <title>Finayo Login Credential</title>
</head>
<body>
    <p>Dear {{ $data['name'] }},</p>
    <p>Welcome to finayo, you can access your account using the given credentials.</p>
    <p><b>Url: </b>{{ $data['admin_url'] }}</p> 
    <p><b>User Id: </b>{{ $data['user_id'] }}</p> 
    <p><b>Password: </b>{{ $data['password'] }}</p> 
</body>
</html>
