<?php

namespace App\Http\Controllers;

use App\Actions\CreateNotificationsAction;
use App\Http\Requests\StoreDashboardNotificationsRequest;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class DashboardNotificationController extends Controller
{
    /**
     * Send notifications from the dashboard's form, as the API would.
     *
     * One copy is created as a single notification, more copies as one batch.
     * Back on the dashboard, a flash message names the result and the feed
     * shows the new notifications.
     */
    public function store(
        StoreDashboardNotificationsRequest $request,
        #[CurrentUser] User $user,
        CreateNotificationsAction $createNotifications,
    ): RedirectResponse {
        $newNotifications = $request->newNotifications();
        $batchId = null;

        if (count($newNotifications) === 1) {
            $createNotifications->handle($user, $newNotifications);
        } else {
            $batchId = $createNotifications->handleBatch($user, $newNotifications)['batch']->id;
        }

        Inertia::flash('notificationsAccepted', [
            'message' => trans_choice('dashboard.notifications_accepted', count($newNotifications), ['batch' => $batchId]),
            'batch_id' => $batchId,
        ]);

        return to_route('dashboard');
    }
}
