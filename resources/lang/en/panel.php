<?php

declare(strict_types=1);

return [
    'dashboard' => [
        'title' => 'Dashboard',
        'customize' => [
            'span' => '{1} :count column|[2,*] :count columns',
            'span_full' => 'Full width',
            'invalid' => 'This dashboard layout is not valid.',
        ],
    ],
    'global_search' => [
        'placeholder' => 'Search…',
        'no_results' => 'No results found.',
    ],
    'resources' => [
        'list' => 'List',
        'create' => [
            'title' => 'Create :label',
            'breadcrumb' => 'Create',
        ],
        'edit' => [
            'title' => 'Edit :label',
            'breadcrumb' => 'Edit',
        ],
        'view' => [
            'title' => 'View :label',
            'breadcrumb' => 'View',
        ],
        'api' => [
            'failed' => 'The request failed',
            'unreachable' => 'The data source could not be reached. Please try again.',
            'error' => 'The data source answered with an error (:status). Please try again.',
            'invalid' => 'The data source rejected the data.',
        ],
    ],
    'tenancy' => [
        'switch' => 'Switch team',
        'register' => 'New team',
        'profile' => 'Team settings',
    ],
    'errors' => [
        'default' => [
            'title' => 'Something went wrong',
            'body' => 'The request could not be completed. Please try again.',
        ],
        403 => [
            'title' => 'Access denied',
            'body' => 'You are not allowed to do that.',
        ],
        404 => [
            'title' => 'Not found',
            'body' => 'The page or record you are looking for does not exist.',
        ],
    ],
    'command_palette' => [
        'placeholder' => 'Type a command or search…',
        'create' => 'New :label',
    ],
    'realtime' => [
        'conflict' => [
            'refused_title' => 'Not saved: the record was changed',
            'refused_body' => 'Someone else saved this record after you opened it. Review their changes, then save again or keep yours.',
            'yes' => 'Yes',
            'no' => 'No',
        ],
    ],
    'themes' => [
        'neutral' => [
            'label' => 'Neutral',
            'description' => 'The classic: inset content beside a sidebar, plain headers and bordered cards.',
        ],
        'alfakit' => [
            'label' => 'AlfaKit',
            'description' => 'The signature design in the AlfaKit brand: Alfa Navy and Fox Orange on warm cream, Montserrat titles and Inter text, a floating sidebar with pill navigation, a hero banner on every page, elevated cards, filled inputs and pill buttons.',
        ],
        'minimal' => [
            'label' => 'Minimal',
            'description' => 'No sidebar: navigation runs across the top as underlined tabs. Flat hairline surfaces, underlined inputs, square buttons, compact.',
        ],
        'soft' => [
            'label' => 'Soft',
            'description' => 'A slim icon rail, large serif titles, tinted borderless cards, filled rounded inputs and striped tables.',
        ],
        'corporate' => [
            'label' => 'Corporate',
            'description' => 'Navy sidebar with accent-bar navigation, a full-width title band, square bordered surfaces and dense striped tables.',
        ],
        'filament' => [
            'label' => 'Filament style',
            'description' => 'Grey canvas, transparent sidebar, white ringed cards, amber accents and Inter, like FilamentPHP.',
        ],
    ],
    'pwa' => [
        'offline' => [
            'title' => 'Offline',
            'heading' => 'You’re offline',
            'body' => 'Check your connection. This page reloads by itself when you’re back online.',
            'retry' => 'Try again',
        ],
    ],
];
