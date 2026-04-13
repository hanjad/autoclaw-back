<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Verification</title>
    <style>
        #verify-email {
            display: inline-block;
            padding: 10px 20px;
            font-size: 16px;
            color: #fff;
            background-color: #007BFF;
            text-decoration: none;
            border-radius: 5px;
        }
        #token {
            word-break: break-all;
            background-color: #f8f9fa;
            padding: 10px;
            border: 1px solid #dee2e6;
            border-radius: 5px;
        }
    </style>
</head>
<body>
    <h1>Email Verification</h1>
    <h3>Dear {{ $user->firstname }} {{ $user->surname }},</h3>
    <p>Thank you for registering with us. Please click the link below to verify your email address:</p>
    <a href="{{ $url }}" id='verify-email'>Verify Email</a>
    <p>This link will expire in 30 minutes.</p>
    <p>or copy and paste the following link into your browser:</p>
    <h2 id='token'>{{ $token }}</h2>
    <p>If you did not create an account, no further action is required.</p>
    <h6>Best regards,<br>The Team</h6>
</body>
</html>