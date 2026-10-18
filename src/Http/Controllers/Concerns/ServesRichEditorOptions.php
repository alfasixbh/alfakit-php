<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Http\Controllers\Concerns;

use AlfaSix\Alfakit\Forms\Components\RichEditor;
use AlfaSix\Alfakit\Schemas\Schema;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use JsonException;

/**
 * Rich editor lookups on the options endpoint: mention search (`mention` = trigger
 * character, `search`) and custom block previews (`block` = id, `config` = JSON). The
 * field must be a visible RichEditor that defines the provider or block, and enabled for
 * mention searches.
 */
trait ServesRichEditorOptions
{
    protected function isRichEditorOptionsRequest(Request $request): bool
    {
        return $request->filled('mention') || $request->filled('block');
    }

    protected function richEditorOptions(Schema $schema, Request $request): JsonResponse
    {
        $field = $schema->getFieldByStatePath((string) $request->query('path'));

        abort_unless($field instanceof RichEditor && ! $field->isHiddenInTree(), 404, 'Rich editor not found.');

        if ($request->filled('mention')) {
            // Only an editor the user can type in searches; previews also show in disabled ones.
            abort_if($field->isDisabled() || $field->isReadOnly(), 403);

            $results = $field->getMentionSearchResults((string) $request->query('mention'), (string) $request->query('search', ''));

            abort_if($results === null, 404, 'Mention provider not found.');

            return response()->json(['options' => array_map(static fn (array $item): array => ['value' => $item['id'], 'label' => $item['label']], $results)]);
        }

        try {
            $config = json_decode((string) $request->query('config', '{}'), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            abort(422, 'Invalid block configuration.');
        }

        $config = is_array($config) ? $config : [];
        $preview = $field->getCustomBlockPreview((string) $request->query('block'), $config);

        abort_if($preview === null, 404, 'Custom block not found.');

        return response()->json([
            'preview' => $preview,
            'prose' => $field->shouldApplyProseStylingToCustomBlockPreview((string) $request->query('block'), $config),
        ]);
    }
}
