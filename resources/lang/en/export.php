<?php

declare(strict_types=1);

return [
    'label' => 'Export :label',
    'bulk_label' => 'Export selected',
    'modal' => [
        'heading' => 'Export :label',
        'submit' => 'Export',
        'columns' => 'Columns',
        'description' => 'The export runs in the background. You will be notified when the files are ready.',
        'column_labels' => 'Column labels',
    ],
    'notifications' => [
        'started' => [
            'title' => 'Export started',
            'body' => '{1} :count row is being exported. You will be notified when the files are ready.|[2,*] :count rows are being exported. You will be notified when the files are ready.',
        ],
        'completed' => [
            'title' => 'Export completed',
            'body' => '{0} No rows were exported.|{1} :count row was exported.|[2,*] :count rows were exported.',
            'failed' => '{1} :count row failed.|[2,*] :count rows failed.',
            'download_csv' => 'Download .csv',
            'download_xlsx' => 'Download .xlsx',
        ],
        'max_rows' => [
            'title' => 'Too many rows',
            'body' => '{1} You can export at most :count row at once.|[2,*] You can export at most :count rows at once.',
        ],
    ],
];
