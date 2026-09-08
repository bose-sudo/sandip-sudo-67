<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title>Finayo</title>
</head>
<body>
    <div  style="text-align: center">
        <h1>Welcome to Finayo</h1>
        <p id="message">Address verification in progress, do not close the window...</p>
        <input type="hidden" value="{{isset($str) ? $str : ''}}" id="verifcation_code">
        <button type="button" id="geoLocation">Get Your Geo Location</button>
    </div>
    
<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>

    <script>
        // $(document).ready(function() {
        //     getLocation();
        // });
        $(document).on('click', '#geoLocation', function(){
           getLocation(); 
        })

        function getLocation() {
            if (navigator.geolocation) {
                navigator.geolocation.getCurrentPosition(showPosition, showError);
            } else {
                setDefaultLocation();
            }
        }

        function showPosition(position) {
            // // console.log("Latitude: " + position.coords.latitude);
            // $('#lat').val(position.coords.latitude);
            // console.log("Longitude: " + position.coords.longitude);
            // $('#long').val(position.coords.longitude);
            field_verification(position.coords.latitude, position.coords.longitude);
        }

        function showError(error) {
            switch(error.code) {
                case error.PERMISSION_DENIED:
                    console.log("User denied the request for Geolocation.");
                    break;
                case error.POSITION_UNAVAILABLE:
                    console.log("Location information is unavailable.");
                    break;
                case error.TIMEOUT:
                    console.log("The request to get user location timed out.");
                    break;
                case error.UNKNOWN_ERROR:
                    console.log("An unknown error occurred.");
                    break;
            }
            setDefaultLocation();
        }

        function setDefaultLocation() {
            // Set default coordinates if geolocation is not available
            var defaultLat = 28.4791573; // Default latitude
            var defaultLong = 77.0800548; // Default longitude

            field_verification(defaultLat, defaultLong);
        }

        function field_verification(latitude, longitude) {
            console.log('lat', latitude);
            console.log('lon', longitude);
            var verifcation_code = $('#verifcation_code').val();
            var toKen = '{{ csrf_token() }}';
            $.ajax({
                type: 'POST',
                url: "{{ url('api/v1/field-verification') }}",
                data:{latitude:latitude, longitude:longitude, verifcation_code:verifcation_code},
                headers: {
                'X-CSRF-TOKEN': toKen
                },
                success: function(data) {
                    console.log(data);
                        // $('#message').text(data);
                    if(data['message'] !== '') {
                        $('#message').text(data['message']);
                        if(data['status'] == 'success') {
                            $('#geoLocation').hide();
                        }
                    }
                },
                error: function(xhr, status, errors) {
                    console.log(xhr);
                }
            });
        }

    </script>
</body>
</html>