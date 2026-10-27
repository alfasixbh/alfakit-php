<?php

declare(strict_types=1);

return [
    'select' => [
        'boolean' => [
            'true' => 'Yes',
            'false' => 'No',
        ],
        'placeholder' => 'Select an option',
        'search_prompt' => 'Start typing to search…',
        'no_search_results' => 'No options match your search.',
        'loading' => 'Loading…',
    ],
    'key_value' => [
        'key' => 'Key',
        'value' => 'Value',
        'add' => 'Add row',
        'delete' => 'Delete row',
        'reorder' => 'Reorder',
    ],
    'repeater' => [
        'cannot_add' => 'Items can\'t be added to :attribute.',
        'cannot_delete' => 'Items can\'t be removed from :attribute.',
        'cannot_reorder' => 'The items in :attribute can\'t be reordered.',
        'add' => 'Add to :label',
        'delete' => 'Delete',
        'clone' => 'Clone',
        'move_up' => 'Move up',
        'move_down' => 'Move down',
        'collapse' => 'Collapse',
        'expand' => 'Expand',
        'reorder' => 'Reorder',
        'collapse_all' => 'Collapse all',
        'expand_all' => 'Expand all',
    ],
    'wizard' => [
        'next' => 'Next',
        'previous' => 'Back',
        'submit' => 'Submit',
    ],
    'file_upload' => [
        'drop' => 'Drag & drop files or',
        'browse' => 'browse',
        'uploading' => 'Uploading…',
        'remove' => 'Remove',
        'download' => 'Download',
        'too_large' => 'This file is too large.',
        'invalid_type' => 'This file type is not allowed.',
        'too_small' => 'This file is too small.',
        'unavailable' => 'This upload is no longer available. Upload the file again.',
        'invalid_dimensions' => 'The image dimensions are not allowed.',
        'invalid_aspect_ratio' => 'The image aspect ratio is not allowed.',
        'chunk_failed' => 'The upload is incomplete. Upload the file again.',
        'types' => [
            'images' => 'Images',
            'videos' => 'Videos',
            'audio' => 'Audio',
            'text' => 'Text files',
        ],
    ],
    'tags_input' => [
        'placeholder' => 'Add a tag…',
    ],
    'rich_editor' => [
        'link_prompt' => 'Enter a URL',
        'insert_block' => 'Insert :block',
        'edit_block' => 'Edit :block',
        'save_block' => 'Save',
        'insert' => 'Insert',
        'text_colors' => [
            'red' => 'Red',
            'orange' => 'Orange',
            'amber' => 'Amber',
            'green' => 'Green',
            'teal' => 'Teal',
            'blue' => 'Blue',
            'indigo' => 'Indigo',
            'purple' => 'Purple',
            'pink' => 'Pink',
            'gray' => 'Gray',
        ],
    ],
    'actions' => [
        'save' => 'Save changes',
        'create' => 'Create',
        'create_another' => 'Create & create another',
        'cancel' => 'Cancel',
        'saved' => 'Saved',
        'created' => 'Created',
    ],
    'builder' => [
        'add' => 'Add to :label',
        'block_max_items' => ':block can be used once.|:block can be used up to :count times.',
    ],
    'slider' => [
        'difference' => 'The values must be between :min and :max apart.',
    ],
    'modal_table_select' => [
        'heading' => 'Select :label',
        'select' => 'Select',
    ],
    'translatable' => [
        'attribute' => ':attribute (:locale)',
        'editing_language' => 'Editing language',
    ],
    'morph_to_select' => [
        'type' => 'Type',
    ],
    'icon_picker' => [
        'invalid' => 'The selected icon is not available.',
    ],
];
