<?php

declare(strict_types=1);

return [
    'label' => 'Import :label',
    'column_number' => 'Column :number',
    'modal' => [
        'heading' => 'Import :label',
        'max_rows' => 'Files can contain up to :count rows.',
        'submit' => 'Import',
        'file' => 'CSV file',
        'columns' => 'Columns',
        'skip_column' => 'Don\'t import',
        'example' => 'Download example CSV',
    ],
    'validation' => [
        'max_rows' => '{1} The file can contain at most :count row.|[2,*] The file can contain at most :count rows.',
    ],
    'notifications' => [
        'started' => [
            'title' => 'Import started',
            'body' => '{1} :count row is being imported. You will be notified when it is done.|[2,*] :count rows are being imported. You will be notified when it is done.',
        ],
        'completed' => [
            'title' => 'Import completed',
            'body' => '{0} No rows were imported.|{1} :count row was imported.|[2,*] :count rows were imported.',
            'failed' => '{1} :count row failed.|[2,*] :count rows failed.',
            'download_failed_rows' => '{1} Download the failed row|[2,*] Download the failed rows',
        ],
    ],
    'failures' => [
        'record_not_found' => 'The record to update was not found.',
        'relationship_not_found' => 'No :attribute matches ":value".',
        'unauthorized' => 'You are not allowed to update this record.',
        'unexpected' => 'The row could not be imported.',
    ],
    'failed_rows' => [
        'error' => 'Error',
    ],
];
