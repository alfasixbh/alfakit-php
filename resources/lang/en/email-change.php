<?php

declare(strict_types=1);

return [
    'sent' => 'We sent a link to :email. Your email address changes once you open it.',
    'pending_heading' => 'Email change pending',
    'pending' => 'Open the link we sent to :email to confirm your new address.',
    'cancel' => 'Cancel change',
    'cancelled' => 'Email change cancelled.',
    'verified' => 'Your email address has been changed.',
    'blocked' => 'The email change has been cancelled.',
    'invalid' => 'This link is no longer valid.',
    'verify_mail' => [
        'subject' => 'Confirm your new email address',
        'intro' => 'Click the button below to use this address for your account.',
        'action' => 'Confirm email address',
        'expiry' => 'The link expires in :minutes minute.|The link expires in :minutes minutes.',
        'ignore' => 'If you did not ask for this change, you can ignore this email.',
    ],
    'notice_mail' => [
        'subject' => 'Email change requested',
        'intro' => 'Someone asked to change the email address of your account to :email.',
        'no_action' => 'If this was you, there is nothing to do. If not, cancel the change and change your password.',
        'action' => 'Cancel the change',
    ],
];
