<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Commands;

use AlfaSix\Alfakit\Commands\Concerns\CanGenerateFiles;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;

use function Laravel\Prompts\text;

#[AsCommand(name: 'make:alfakit-related-records-page', description: 'Create a ManageRelatedRecords page for a resource relationship')]
class MakeRelatedRecordsPageCommand extends Command
{
    use CanGenerateFiles;

    protected $signature = 'make:alfakit-related-records-page {resource?} {relationship?} {recordTitleAttribute?} {--attach : Use attach/detach actions (BelongsToMany)} {--associate : Use associate/dissociate actions (HasMany)} {--panel=} {--force}';

    public function handle(): int
    {
        $resource = Str::studly((string) ($this->argument('resource') ?? text('Resource (e.g. PostResource)', required: true)));
        $resource = Str::endsWith($resource, 'Resource') ? $resource : "{$resource}Resource";
        $relationship = Str::camel((string) ($this->argument('relationship') ?? text('Relationship name (e.g. comments)', required: true)));
        $title = (string) ($this->argument('recordTitleAttribute') ?? text('Record title attribute', 'title', 'title', required: true));

        $panelId = $this->option('panel') ?? alfakit()->getDefaultPanel()?->getId();
        $suffix = $this->panelNamespaceSuffix($panelId);
        $model = Str::singular(Str::replaceEnd('Resource', '', $resource));
        $class = 'Manage'.$model.Str::studly($relationship);
        $slug = Str::kebab($relationship);
        $namespace = $this->getGenerationNamespace().$suffix."\\Resources\\{$resource}\\Pages";
        $path = $this->getGenerationPath().str_replace('\\', '/', $suffix)."/Resources/{$resource}/Pages/{$class}.php";

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

        $this->writeStub('pages/related-records', $path, [
            'namespace' => $namespace,
            'class' => $class,
            'resource' => $resource,
            'resourceNamespace' => $this->getGenerationNamespace().$suffix.'\\Resources',
            'relationship' => $relationship,
            'recordTitleAttribute' => $title,
            'slug' => $slug,
            'headerActions' => $headerActions,
            'recordActions' => $recordActions,
            'imports' => $imports,
        ], (bool) $this->option('force'));

        $this->components->info("Register it in {$resource}::getPages(): '{$slug}' => Pages\\{$class}::route('/{record}/{$slug}'),");

        return self::SUCCESS;
    }
}
