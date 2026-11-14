<?php

namespace App\Http\Controllers;

use App\Enums\NotificationChannel;
use App\Http\Requests\ListNotificationTemplatesRequest;
use App\Http\Requests\StoreNotificationTemplateRequest;
use App\Http\Requests\UpdateNotificationTemplateRequest;
use App\Http\Resources\NotificationTemplateResource;
use App\Models\NotificationTemplate;
use App\Models\User;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Attributes\Controllers\Authorize;

#[Group('Templates', 'Reusable content with {{ variable }} placeholders.', weight: 3)]
class NotificationTemplateController extends Controller
{
    /**
     * List the authenticated user's templates by name.
     */
    #[Endpoint(title: 'List templates', description: 'The authenticated user\'s templates by name, with cursor pagination.')]
    public function index(ListNotificationTemplatesRequest $request, #[CurrentUser] User $user): AnonymousResourceCollection
    {
        $templates = $user->notificationTemplates()
            ->when($request->channel(), fn (Builder $q, NotificationChannel $channel) => $q->where('channel', $channel))
            ->orderBy('name')
            ->orderBy('id')
            ->cursorPaginate($request->perPage())
            ->withQueryString();

        return NotificationTemplateResource::collection($templates);
    }

    /**
     * Create a template.
     *
     * Placeholders take the form `{{ name }}`. Notifications created from the
     * template give their values in `variables`.
     */
    #[Endpoint(title: 'Create a template')]
    public function store(StoreNotificationTemplateRequest $request, #[CurrentUser] User $user): JsonResponse
    {
        $template = $user->notificationTemplates()->create($request->validated());

        return NotificationTemplateResource::make($template)->response()->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Show the template.
     */
    #[Authorize('view', 'template')]
    #[Endpoint(title: 'Get a template', description: 'The template and the variables a notification created from it must give a value to.')]
    public function show(NotificationTemplate $template): NotificationTemplateResource
    {
        return NotificationTemplateResource::make($template);
    }

    /**
     * Update the template's name or content.
     *
     * Notifications already created from the template keep the content they were created with.
     */
    #[Authorize('update', 'template')]
    #[Endpoint(title: 'Update a template')]
    public function update(UpdateNotificationTemplateRequest $request, NotificationTemplate $template): NotificationTemplateResource
    {
        $template->update($request->validated());

        return NotificationTemplateResource::make($template);
    }

    /**
     * Delete the template.
     *
     * Notifications created from the template keep their content.
     */
    #[Authorize('delete', 'template')]
    #[Endpoint(title: 'Delete a template')]
    public function destroy(NotificationTemplate $template): Response
    {
        $template->delete();

        return response()->noContent();
    }
}
