<?php

// MySQL connection to the original hackpad database
putenv('DATABASE_URL=mysql://user:password@localhost:3306/hackpad');

// Random secret for HMAC-signed session cookies (use a long random string)
putenv('SESSION_SECRET=replace_with_random_secret');

// Cookie domain — set to ".hackpad.tw" to share session across all subdomains
putenv('SESSION_DOMAIN=.hackpad.tw');

// The primary domain (without leading dot) used for subdomain detection and OAuth callback
putenv('HACKPAD_PRIMARY_DOMAIN=hackpad.tw');

// Google OAuth2 credentials (from Google Cloud Console)
// Redirect URI must be set to: https://hackpad.tw/ep/account/openid
putenv('GOOGLE_CLIENT_ID=your-client-id.apps.googleusercontent.com');
putenv('GOOGLE_CLIENT_SECRET=your-client-secret');

// Set to "production" to suppress error details and enable 404/500 responses
putenv('ENV=production');
