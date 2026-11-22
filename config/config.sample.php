<?php
/**
 * College Management System - configuration
 *
 * Copy this file to config.php and fill in your values.
 * config.php is protected from web access by config/.htaccess.
 */
return [
    'app' => [
        'name'            => 'College Management System',
        'college_name'    => 'Sri Vidya College of Engineering & Management',
        'college_address' => '123 College Road, Bengaluru, Karnataka 560001',
        'college_phone'   => '+91 80 1234 5678',
        'college_email'   => 'office@college.edu',
        // Full public URL of the application, WITHOUT trailing slash.
        // e.g. https://cms.yourcollege.edu or https://yourdomain.com/cms
        'url'             => 'https://example.com',
        'timezone'        => 'Asia/Kolkata',
        'currency_symbol' => '₹',
        // Never enable on a live site: shows internal error details.
        'debug'           => false,
        // Idle session timeout in seconds.
        'session_timeout' => 1800,
    ],

    'db' => [
        'host'     => 'localhost',
        'port'     => 3306,
        'name'     => 'your_database_name',
        'user'     => 'your_database_user',
        'pass'     => 'your_database_password',
        'charset'  => 'utf8mb4',
    ],

    'mail' => [
        // Used for password-reset emails sent through PHP mail().
        // Use an address on your own domain so the hosting provider will send it.
        'from_email' => 'no-reply@example.com',
        'from_name'  => 'College Management System',
    ],

    'security' => [
        'max_login_attempts'   => 5,     // per username / IP ...
        'lockout_minutes'      => 15,    // ... within this window
        'password_min_length'  => 8,
        'reset_token_minutes'  => 30,
    ],

    'uploads' => [
        'max_photo_bytes' => 2 * 1024 * 1024, // 2 MB
    ],
];
