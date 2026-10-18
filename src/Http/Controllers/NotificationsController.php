<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Http\Controllers;

use AlfaSix\Alfakit\Notifications\DatabaseNotificationScope;
use AlfaSix\Alfakit\Notifications\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

class NotificationsController
{
    public function index(Request $request): JsonResponse
    {
        $user = $this->user();
        $paginator = DatabaseNotificationScope::apply($user->notifications())->latest()->paginate(20);

        return response()->json([
            'notifications' => Notification::serializeDatabaseNotifications($paginator->getCollection()),
            'unreadCount' => DatabaseNotificationScope::apply($user->unreadNotifications())->count(),
            'hasMore' => $paginator->hasMorePages(),
            'page' => $paginator->currentPage(),
        ]);
    }

    public function markAsRead(Request $request, string $notification): JsonResponse
    {
        $record = DatabaseNotificationScope::apply($this->user()->notifications())->whereKey($notification)->first();

        abort_if(! $record instanceof DatabaseNotification, 404);

        // `unread`: the `markAsUnread()` notification action.
        $request->boolean('unread') ? $record->markAsUnread() : $record->markAsRead();

        return response()->json(['ok' => true, 'unreadCount' => DatabaseNotificationScope::apply($this->user()->unreadNotifications())->count()]);
    }

    public function markAllAsRead(): JsonResponse
    {
        DatabaseNotificationScope::apply($this->user()->unreadNotifications())->update(['read_at' => now()]);

        return response()->json(['ok' => true, 'unreadCount' => 0]);
    }

    public function destroyAll(): JsonResponse
    {
        DatabaseNotificationScope::apply($this->user()->notifications())->delete();

        return response()->json(['ok' => true, 'unreadCount' => 0]);
    }

    protected function user(): mixed
    {
        $user = alfakit()->getCurrentOrDefaultPanel()->auth()->user();

        abort_if($user === null || ! method_exists($user, 'notifications'), 403, 'The user model must use the Notifiable trait.');

        return $user;
    }
}
