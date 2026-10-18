<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Resources;

use Illuminate\Support\Str;

/**
 * How a nested resource hangs under its parent: `PostResource::asParent()->relationship('comments')->inverseRelationship('post')`.
 *
 * The relationship (on the parent model) defaults to the camel-cased plural of the child
 * model (`comments`); the inverse (on the child model) and the route parameter default to
 * the camel-cased parent model (`post`).
 */
final class ParentResourceRegistration
{
    private ?string $relationship = null;

    private ?string $inverseRelationship = null;

    private ?string $routeParameterName = null;

    private ?string $page = null;

    /**
     * @param  class-string<\AlfaSix\Alfakit\Resources\Resource>  $parentResource
     */
    public function __construct(private readonly string $parentResource) {}

    /**
     * @param  class-string<\AlfaSix\Alfakit\Resources\Resource>  $parentResource
     */
    public static function make(string $parentResource): self
    {
        return new self($parentResource);
    }

    public function relationship(?string $name): self
    {
        $this->relationship = $name;

        return $this;
    }

    public function inverseRelationship(?string $name): self
    {
        $this->inverseRelationship = $name;

        return $this;
    }

    public function routeParameterName(?string $name): self
    {
        $this->routeParameterName = $name;

        return $this;
    }

    /**
     * The parent resource's page (its key in the parent's `getPages()`) that lists the
     * nested records, e.g. a `ManageRelatedRecords` page registered as `manageLessons`.
     * Defaults to the kebab-cased relationship name (`lessons`).
     */
    public function page(?string $name): self
    {
        $this->page = $name;

        return $this;
    }

    /**
     * @param  class-string<\AlfaSix\Alfakit\Resources\Resource>  $childResource
     */
    public function getPageName(string $childResource): string
    {
        return $this->page ?? Str::kebab($this->getRelationshipName($childResource));
    }

    /**
     * @return class-string<\AlfaSix\Alfakit\Resources\Resource>
     */
    public function getParentResource(): string
    {
        return $this->parentResource;
    }

    /**
     * @param  class-string<\AlfaSix\Alfakit\Resources\Resource>  $childResource
     */
    public function getRelationshipName(string $childResource): string
    {
        return $this->relationship ?? Str::camel(Str::plural(class_basename($childResource::getModel())));
    }

    public function getInverseRelationshipName(): string
    {
        return $this->inverseRelationship ?? Str::camel(class_basename($this->parentResource::getModel()));
    }

    public function getRouteParameterName(): string
    {
        $name = $this->routeParameterName ?? Str::camel(class_basename($this->parentResource::getModel()));

        // `record` and `tenant` are taken by the child's own pages and the panel.
        return in_array($name, ['record', 'tenant'], true) ? "{$name}Parent" : $name;
    }
}
