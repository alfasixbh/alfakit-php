<?php

declare(strict_types=1);

return [
    'modal' => [
        'confirmation' => 'Are you sure you would like to do this?',
        'confirm' => 'Confirm',
        'submit' => 'Submit',
        'cancel' => 'Cancel',
        'close' => 'Close',
    ],
    'create' => [
        'label' => 'New :label',
        'modal' => [
            'heading' => 'Create :label',
            'submit' => 'Create',
        ],
        'notification' => 'Created',
        'create_another' => 'Create & create another',
    ],
    'edit' => [
        'label' => 'Edit',
        'modal' => [
            'heading' => 'Edit :label',
            'submit' => 'Save',
        ],
        'notification' => 'Saved',
    ],
    'view' => [
        'label' => 'View',
        'modal' => [
            'heading' => 'View :label',
        ],
    ],
    'delete' => [
        'label' => 'Delete',
        'modal' => [
            'heading' => 'Delete :label',
            'submit' => 'Delete',
        ],
        'notification' => 'Deleted',
        'bulk' => [
            'heading' => 'Delete selected records',
            'notification' => 'Deleted',
        ],
    ],
    'restore' => [
        'label' => 'Restore',
        'modal' => [
            'heading' => 'Restore :label',
            'submit' => 'Restore',
        ],
        'notification' => 'Restored',
        'bulk' => [
            'heading' => 'Restore selected records',
            'notification' => 'Restored',
        ],
    ],
    'force_delete' => [
        'label' => 'Force delete',
        'modal' => [
            'heading' => 'Permanently delete :label',
            'description' => 'This cannot be undone.',
            'submit' => 'Delete',
        ],
        'notification' => 'Deleted permanently',
        'bulk' => [
            'heading' => 'Permanently delete selected records',
            'notification' => 'Deleted permanently',
        ],
    ],
    'replicate' => [
        'label' => 'Replicate',
        'modal' => [
            'heading' => 'Replicate :label',
            'submit' => 'Replicate',
        ],
        'notification' => 'Replicated',
    ],
    'attach' => [
        'label' => 'Attach',
        'modal' => [
            'heading' => 'Attach :label',
            'submit' => 'Attach',
        ],
        'notification' => 'Attached',
    ],
    'detach' => [
        'label' => 'Detach',
        'modal' => [
            'heading' => 'Detach :label',
            'submit' => 'Detach',
        ],
        'notification' => 'Detached',
    ],
    'associate' => [
        'label' => 'Associate',
        'modal' => [
            'heading' => 'Associate :label',
            'submit' => 'Associate',
        ],
        'notification' => 'Associated',
    ],
    'dissociate' => [
        'label' => 'Dissociate',
        'modal' => [
            'heading' => 'Dissociate :label',
            'submit' => 'Dissociate',
        ],
        'notification' => 'Dissociated',
    ],
    'detach_bulk' => [
        'heading' => 'Detach selected records',
        'notification' => 'Detached',
    ],
    'dissociate_bulk' => [
        'heading' => 'Dissociate selected records',
        'notification' => 'Dissociated',
    ],
    'rate_limited' => [
        'title' => 'Too many attempts',
        'body' => 'Please wait :seconds second before trying again.|Please wait :seconds seconds before trying again.',
    ],
    'password_confirmation' => [
        'incorrect' => 'The password is incorrect.',
        'throttled' => 'Too many attempts. Try again in :seconds seconds.',
    ],
    'bulk' => [
        'unauthorized_records' => ':count selected record was skipped because you are not allowed to change it.|:count selected records were skipped because you are not allowed to change them.',
        'all_failed' => 'The record could not be processed.|None of the :count records could be processed.',
        'some_failed' => ':failure of :total records could not be processed.|:failure of :total records could not be processed.',
    ],
    'history' => [
        'label' => 'History',
        'modal' => [
            'heading' => 'History of :label',
            'close' => 'Close',
        ],
        'empty' => 'No versions yet.',
        'events' => [
            'created' => 'Created',
            'updated' => 'Updated',
            'deleted' => 'Deleted',
            'restored' => 'Restored',
            'reverted' => 'Restored an earlier version',
            'baseline' => 'Before the history started',
        ],
        'restore' => [
            'label' => 'Restore this version',
            'modal' => [
                'heading' => 'Restore this version?',
                'description' => 'The tracked fields go back to their values in this version. The current values stay in the history.',
                'submit' => 'Restore',
            ],
            'notification' => 'Version restored',
        ],
    ],
    'undo' => [
        'done' => 'Done',
        'notification' => 'Undone',
        'expired' => 'This can no longer be undone.',
        'conflict' => 'The record was changed since, so this can\'t be undone.',
        'unavailable' => 'This can no longer be undone because the record is gone.',
    ],
];
