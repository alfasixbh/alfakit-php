<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Commands;

use AlfaSix\Alfakit\Commands\Concerns\CanGenerateFiles;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;

use function Laravel\Prompts\text;

#[AsCommand(name: 'make:alfakit-relation-manager', description: 'Create a relation manager for a resource')]
class MakeRelationManagerCommand extends Command
{
    use CanGenerateFiles;

    protected $signature = 'make:alfakit-relation-manager {resource?} {relationship?} {recordTitleAttribute?} {--attach : Use attach/detach actions (BelongsToMany)} {--associate : Use associate/dissociate actions (HasMany)} {--soft-deletes : Add the trashed filter and restore/force-delete actions} {--view : Add a view action and an infolist} {--panel=} {--force}';

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
        ], (bool) $this->option('force'));

        $this->components->info("Register it in {$resource}::getRelations(): RelationManagers\\{$class}::class");

        return self::SUCCESS;
    }
}
