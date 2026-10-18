<?php

declare(strict_types=1);

return [
    'model_label' => 'user',
    'plural_model_label' => 'users',
    'never' => 'Never',
    'sections' => [
        'account' => 'Account',
        'security' => 'Security',
    ],
    'fields' => [
        'name' => 'Name',
        'email' => 'Email address',
        'password' => 'Password',
        'password_help' => 'Leave empty to keep the current password.',
        'password_confirmation' => 'Confirm password',
        'current_password' => 'Current password',
        'new_password' => 'New password',
        'password_change_required' => 'Require a new password',
        'password_change_required_help' => 'The user must choose a new password the next time they sign in.',
        'roles' => 'Roles',
        'status' => 'Status',
        'ban_reason' => 'Reason',
        'created_at' => 'Created',
        'last_login_at' => 'Last sign-in',
        'last_login_ip' => 'Last sign-in IP',
        'two_factor' => 'Two-factor authentication',
    ],
    'status' => [
        'active' => 'Active',
        'invited' => 'Invited',
        'suspended' => 'Suspended',
        'banned' => 'Banned',
    ],
    'actions' => [
        'invite' => [
            'label' => 'Invite user',
            'heading' => 'Invite a user',
            'description' => 'They get an email with a link to choose their password.',
            'submit' => 'Send invitation',
            'done' => 'Invitation sent',
        ],
        'resend_invitation' => [
            'label' => 'Resend invitation',
            'done' => 'Invitation sent again',
        ],
        'suspend' => [
            'label' => 'Suspend',
            'description' => 'The user is signed out and can\'t sign in until you unsuspend them.',
            'done' => 'User suspended',
        ],
        'unsuspend' => [
            'label' => 'Unsuspend',
            'done' => 'User can sign in again',
        ],
        'ban' => [
            'label' => 'Ban',
            'description' => 'The user is signed out and blocked from the panel. The reason is only shown to admins.',
            'done' => 'User banned',
        ],
        'unban' => [
            'label' => 'Lift ban',
            'done' => 'Ban lifted',
        ],
        'require_password_change' => [
            'label' => 'Require a new password',
            'description' => 'The user must choose a new password before they can use the panel again.',
            'done' => 'The user will be asked for a new password',
        ],
        'reset_two_factor' => [
            'label' => 'Reset two-factor authentication',
            'description' => 'Turns off every second factor and recovery code. The user signs in with their password and can set it up again.',
            'done' => 'Two-factor authentication reset',
        ],
        'revoke_session' => [
            'label' => 'Sign out a session',
            'session' => 'Session',
            'done' => 'Session signed out',
        ],
        'revoke_sessions' => [
            'label' => 'Sign out everywhere',
            'description' => 'Ends every session of this user on every device.',
            'done' => 'Signed out everywhere',
        ],
    ],
    'blocked' => [
        'suspended' => 'Your account is suspended. Contact an administrator.',
        'banned' => 'Your account has been banned.',
    ],
    'invitation' => [
        'title' => 'Accept invitation',
        'heading' => 'Welcome to :app',
        'submit' => 'Set password and sign in',
        'accepted' => 'Welcome! Your password is set.',
        'invalid' => 'This invitation link is invalid or has already been used.',
        'mail' => [
            'subject' => 'You\'re invited to :app',
            'greeting' => 'Hello!',
            'intro' => 'You have been invited to :app. Choose a password to activate your account.',
            'action' => 'Accept invitation',
            'expiry' => 'This link expires in :days days.',
        ],
    ],
    'change_password' => [
        'title' => 'Choose a new password',
        'heading' => 'Choose a new password',
        'description' => 'An administrator asked you to change your password before you continue.',
        'submit' => 'Save password',
        'done' => 'Your password has been changed.',
    ],
    'sessions' => [
        'heading' => 'Sessions',
        'description' => 'Browsers and devices where this user is signed in.',
        'empty' => 'No active sessions',
        'device' => 'Device',
        'ip_address' => 'IP address',
        'last_active' => 'Last active',
        'unknown_device' => 'Unknown device',
    ],
];
