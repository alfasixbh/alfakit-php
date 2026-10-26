<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Http\Controllers\Concerns;

use AlfaSix\Alfakit\Actions\Exceptions\Cancel;
use AlfaSix\Alfakit\Actions\Exceptions\Halt;
use AlfaSix\Alfakit\Pages\Page;
use AlfaSix\Alfakit\Schemas\SchemaDiff;
use AlfaSix\Alfakit\Support\ActionDownload;
use AlfaSix\Alfakit\Support\Serializable;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Actions attached to fields and entries (`field:{schema}::{key}`). They answer with JSON:
 * the action's result plus the owning form, re-rendered after the action ran (partial
 * when the browser sent its fingerprints), so `$set()` reaches the browser without a
 * page visit.
 */
trait HandlesFieldActions
{
    use PullsFlashedNotifications;
    use ResolvesSchemas;

    /**
     * The modal schema, or `{cancelled: true}` when mounting halted the action.
     */
    abstract protected function modalResponse(\AlfaSix\Alfakit\Actions\Action $action, Request $request): JsonResponse;

    protected function isFieldActionComponent(mixed $component): bool
    {
        return is_string($component) && str_starts_with($component, self::FIELD_COMPONENT_PREFIX);
    }

    protected function fieldActionModal(Page $page, string $component, Request $request): JsonResponse
    {
        [$action] = $this->resolveFieldAction(
            $page,
            substr($component, strlen(self::FIELD_COMPONENT_PREFIX)),
            (string) $request->input('action'),
            $this->clientState($request),
        );

        $action->arguments((array) $request->input('arguments', []));

        abort_unless($action->isVisible() && $action->isAuthorized(), 403);

        return $this->modalResponse($action, $request);
    }

    protected function executeFieldAction(Page $page, string $component, Request $request): Response|Responsable
    {
        $state = $this->clientState($request);

        [$action, $schema] = $this->resolveFieldAction(
            $page,
            substr($component, strlen(self::FIELD_COMPONENT_PREFIX)),
            (string) $request->input('action'),
            $state,
        );

        abort_if($action->isLink(), 400, "The [{$action->getName()}] action opens a URL and cannot be executed.");

        try {
            $result = $action->execute(
                data: (array) $request->input('data', []),
                arguments: (array) $request->input('arguments', []),
            );
        } catch (Halt) {
            return response()->json(['status' => 'halted', 'notifications' => $this->pullFlashedNotifications($request)]);
        } catch (Cancel) {
            return response()->json(['status' => 'cancelled', 'notifications' => $this->pullFlashedNotifications($request)]);
        }

        if ($result instanceof RedirectResponse) {
            return response()->json(['status' => 'success', 'redirect' => \AlfaSix\Alfakit\Support\Url::sanitize($result->getTargetUrl())]);
        }

        if ($result instanceof Responsable) {
            $result = $result->toResponse($request);
        }

        // Field actions answer with JSON, which cannot carry a file: the browser fetches it
        // from a one-time link.
        $download = $result instanceof Response && ActionDownload::isDownload($result)
            ? ActionDownload::store($result, $request)
            : null;

        if ($download === null && $result instanceof Response) {
            return $result;
        }

        $fingerprints = $request->input('fingerprints');

        return response()->json([
            'status' => 'success',
            'result' => is_scalar($result) || is_array($result) || $result === null ? Serializable::value($result) : null,
            'download' => $download,
            'closeModal' => $action->shouldCloseModal(),
            'redirect' => \AlfaSix\Alfakit\Support\Url::sanitize($action->getSuccessRedirectUrl()),
            'form' => SchemaDiff::respond($schema->toPayload(), is_array($fingerprints) ? $fingerprints : null, $state),
            // Field actions answer with JSON, so their notifications are shown from here.
            'notifications' => $this->pullFlashedNotifications($request),
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function clientState(Request $request): ?array
    {
        $state = $request->input('state');

        return is_array($state) ? $state : null;
    }
}
