<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Commands;

use AlfaSix\Alfakit\Commands\Concerns\CanGenerateFiles;
use AlfaSix\Alfakit\Commands\Support\PanelProviderEditor;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;

use function Laravel\Prompts\text;

#[AsCommand(name: 'make:alfakit-relation-manager', description: 'Create a relation manager for a resource')]
class MakeRelationManagerCommand extends Command
{
    use CanGenerateFiles;

    protected $signature = 'make:alfakit-relation-manager {resource?} {relationship?} {recordTitleAttribute?} {--attach : Use attach/detach actions (BelongsToMany)} {--associate : Use associate/dissociate actions (HasMany)} {--soft-deletes : Add the trashed filter and restore/force-delete actions} {--view : Add a view action and an infolist} {--related= : The nested resource it links to (e.g. CommentResource): its create, view and edit pages open instead of modals} {--register : Add it to the resource\'s getRelations()} {--rich : The title attribute holds rich text: list it as plain text} {--panel=} {--force}';

    public function handle(): int
    {
        $resource = Str::studly((string) ($this->argument('resource') ?? text('Resource (e.g. PostResource)', required: true)));
        $resource = Str::endsWith($resource, 'Resource') ? $resource : "{$resource}Resource";
        $relationship = Str::camel((string) ($this->argument('relationship') ?? text('Relationship name (e.g. comments)', required: true)));
        $title = (string) ($this->argument('recordTitleAttribute') ?? text('Record title attribute', 'title', 'title', required: true));

        $panelId = $this->option('panel') ?? alfakit()->getDefaultPanel()?->getId();
        $suffix = $this->panelNamespaceSuffix($panelId);
        $class = Str::studly($relationship).'RelationManager';
        $namespace = $this->getGenerationNamespace().$suffix."\\Resources\\{$resource}\\RelationManagers";
        $path = $this->getGenerationPath().str_replace('\\', '/', $suffix)."/Resources/{$resource}/RelationManagers/{$class}.php";

        $headerActions = 'CreateAction::make(),';
        $recordActions = "EditAction::make(),\n                DeleteAction::make(),";
        $imports = "use AlfaSix\\Alfakit\\Actions\\CreateAction;\nuse AlfaSix\\Alfakit\\Actions\\DeleteAction;\nuse AlfaSix\\Alfakit\\Actions\\EditAction;";

        if ($this->option('attach')) {
            $headerActions .= "\n                AttachAction::make(),";
            $recordActions .= "\n                DetachAction::make(),";
            $imports .= "\nuse AlfaSix\\Alfakit\\Actions\\AttachAction;\nuse AlfaSix\\Alfakit\\Actions\\DetachAction;";
        }

        if ($this->option('associate')) {
            $headerActions .= "\n                AssociateAction::make(),";
            $recordActions .= "\n                DissociateAction::make(),";
            $imports .= "\nuse AlfaSix\\Alfakit\\Actions\\AssociateAction;\nuse AlfaSix\\Alfakit\\Actions\\DissociateAction;";
        }

        $tableExtras = '';
        $bulkActions = '';
        $infolist = '';

        if ($this->option('view')) {
            $recordActions = "ViewAction::make(),\n                ".$recordActions;
            $imports .= "\nuse AlfaSix\\Alfakit\\Actions\\ViewAction;\nuse AlfaSix\\Alfakit\\Infolists\\Components\\TextEntry;";
            $infolist = "\n    public function infolist(Schema \$schema): Schema\n    {\n        return \$schema->components([\n            TextEntry::make('{$title}'),\n        ]);\n    }\n";
        }

        if ($this->option('soft-deletes')) {
            $tableExtras = "\n            ->modifyQueryUsing(fn (Builder \$query): Builder => \$query->withoutGlobalScopes([SoftDeletingScope::class]))\n            ->filters([\n                TrashedFilter::make(),\n            ])";
            $recordActions .= "\n                RestoreAction::make(),\n                ForceDeleteAction::make(),";
            $bulkActions = "\n                RestoreBulkAction::make(),\n                ForceDeleteBulkAction::make(),";
            $imports .= "\nuse AlfaSix\\Alfakit\\Actions\\ForceDeleteAction;\nuse AlfaSix\\Alfakit\\Actions\\ForceDeleteBulkAction;\nuse AlfaSix\\Alfakit\\Actions\\RestoreAction;\nuse AlfaSix\\Alfakit\\Actions\\RestoreBulkAction;\nuse AlfaSix\\Alfakit\\Tables\\Filters\\TrashedFilter;\nuse Illuminate\\Database\\Eloquent\\Builder;\nuse Illuminate\\Database\\Eloquent\\SoftDeletingScope;";
        }

        $relatedResource = '';
        $relatedClass = '';
        $related = trim((string) $this->option('related'));

        if ($related !== '') {
            $related = Str::endsWith($related, 'Resource') ? $related : "{$related}Resource";
            $relatedClass = Str::contains($related, '\\') ? ltrim($related, '\\') : $this->getGenerationNamespace().$suffix."\\Resources\\{$related}";
            $imports .= "\nuse {$relatedClass};";
            $relatedResource = "\n    protected static ?string \$relatedResource = ".class_basename($relatedClass)."::class;\n";
        }

        $titleColumn = $this->option('rich')
            ? "TextColumn::make('{$title}')\n                    ->formatStateUsing(fn (?string \$state): string => trim(strip_tags((string) \$state)))\n                    ->limit(80)\n                    ->searchable()"
            : "TextColumn::make('{$title}')->searchable()";

        // `make:alfakit-resource Comment --nested=PostResource` after the quick start's own
        // CommentsRelationManager: keep the file, but link it to the nested resource, or its
        // create, view and edit pages can't be reached from the parent.
        if ($related !== '' && is_file($path) && ! $this->option('force')) {
            $linked = self::linkRelatedResource((string) file_get_contents($path), $relatedClass);

            // The nested resource edits it as rich text: list the text, not the stored HTML.
            if ($linked !== null && $this->option('rich')) {
                $linked = self::listAsPlainText($linked, $title);
            }

            if ($linked === null) {
                $this->components->warn("{$class} already exists. Link it to {$related} yourself: protected static ?string \$relatedResource = {$related}::class;");
            } elseif ($linked !== file_get_contents($path)) {
                file_put_contents($path, $linked);
                $this->components->info("{$class} already exists: it now links to {$related} (\$relatedResource), so its create, view and edit open the nested pages.");
            }
        } else {
            $this->writeStub('relation-manager', $path, [
                'tableExtras' => $tableExtras,
                'bulkActions' => $bulkActions,
                'infolist' => $infolist,
                'namespace' => $namespace,
                'class' => $class,
                'relationship' => $relationship,
                'recordTitleAttribute' => $title,
                'headerActions' => $headerActions,
                'recordActions' => $recordActions,
                'imports' => $imports,
                'relatedResource' => $relatedResource,
                'titleColumn' => $titleColumn,
            ], (bool) $this->option('force'));
        }

        $resourcePath = $this->getGenerationPath().str_replace('\\', '/', $suffix)."/Resources/{$resource}.php";

        if ($this->option('register') && self::registerInResource($resourcePath, "{$resource}\\RelationManagers\\{$class}")) {
            $this->components->info("Registered it in {$resource}::getRelations().");
        } else {
            // Relative to the resource's own namespace, which doesn't import RelationManagers.
            $this->components->info("Register it in {$resource}::getRelations(): {$resource}\\RelationManagers\\{$class}::class (or run the command with --register to add it for you).");
        }

        return self::SUCCESS;
    }

    /**
     * Add the manager to the resource's `getRelations()` (once). False when the method is
     * missing or not a plain array, so the caller prints the manual step instead.
     */
    /**
     * Add `protected static ?string $relatedResource = X::class;` (and its import) to an
     * existing relation manager. Unchanged when it has one; null when the class body can't
     * be found.
     */
    public static function linkRelatedResource(string $code, string $relatedClass): ?string
    {
        if (str_contains($code, '$relatedResource')) {
            return $code;
        }

        $basename = class_basename($relatedClass);
        $updated = preg_replace('/(protected static string \$relationship = [^;]+;\n)/', "$1\n    protected static ?string \$relatedResource = {$basename}::class;\n", $code, 1, $count);

        if (! is_string($updated) || $count !== 1) {
            return null;
        }

        return PanelProviderEditor::addImport($updated, $relatedClass);
    }

    /**
     * Turn the manager's plain `TextColumn::make($column)` into the plain-text form of a rich
     * text column (tags stripped). Columns that already format their state are left alone.
     */
    public static function listAsPlainText(string $code, string $column): string
    {
        $pattern = '/TextColumn::make\(\''.preg_quote($column, '/').'\'\)(?!\s*->formatStateUsing)/';

        return (string) preg_replace($pattern, "TextColumn::make('{$column}')\n                    ->formatStateUsing(fn (?string \$state): string => trim(strip_tags((string) \$state)))\n                    ->limit(80)\n                    ", $code, 1);
    }

    public static function registerInResource(string $resourcePath, string $relativeClass): bool
    {
        if (! is_file($resourcePath)) {
            return false;
        }

        $code = (string) file_get_contents($resourcePath);
        $entry = $relativeClass.'::class';

        if (str_contains($code, $entry)) {
            return true;
        }

        $updated = preg_replace(
            '/(public static function getRelations\(\): array\s*\{\s*return \[)(\s*\/\/\s*(?=\]))?/',
            '$1'."\n            {$entry},\n        ",
            $code,
            1,
            $count,
        );

        if (! is_string($updated) || $count !== 1) {
            return false;
        }

        // `return [\n            X::class,\n        ` + an existing entry list: tidy the blank line.
        $updated = (string) preg_replace('/'.preg_quote($entry, '/').',\n        \n/', $entry.",\n", $updated);

        file_put_contents($resourcePath, $updated);

        return true;
    }
}
