<?php

declare(strict_types=1);

namespace App\Livewire\NotificationStreams\Forms;

use App\Models\NotificationStream;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Redirect;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\Features\SupportRedirects\Redirector;
use Masmerise\Toaster\Toaster;

/**
 * Manages the form for updating an existing notification stream.
 *
 * This component handles the UI and logic for modifying notification stream details,
 * including authorization, form validation, and submission.
 */
class UpdateNotificationStream extends Component
{
    use AuthorizesRequests;

    /** @var NotificationStream The notification stream being updated */
    public NotificationStream $notificationStream;

    /** @var NotificationStreamForm The form object for updating a notification stream */
    public NotificationStreamForm $form;

    /**
     * Initialize the component state.
     */
    public function mount(NotificationStream $notificationStream): void
    {
        $this->authorize('update', $notificationStream);

        $this->notificationStream = $notificationStream;
        $this->form = new NotificationStreamForm($this, 'form');
        $this->form->initialize();
        $this->form->setNotificationStream($notificationStream);
    }

    /**
     * Handle changes to the notification stream type.
     *
     * Resets validation for the value field when the type changes.
     */
    public function updatedFormType(): void
    {
        $this->resetValidation('form.value');
    }

    /**
     * Submit the form and update the notification stream.
     */
    public function submit(): RedirectResponse|Redirector
    {
        $this->authorize('update', $this->notificationStream);

        $this->form->validate();

        $this->notificationStream->update([
            'label' => $this->form->label,
            'type' => $this->form->type,
            'value' => $this->form->value,
            'receive_successful_backup_notifications' => $this->form->success_notification ? now() : null,
            'receive_failed_backup_notifications' => $this->form->failed_notification ? now() : null,
        ]);

        Toaster::success('Notification stream has been updated.');

        return Redirect::route('notification-streams.index');
    }

    /**
     * Render the update notification stream form.
     */
    public function render(): View
    {
        return view('livewire.notification-streams.forms.update-notification-stream', [
            'notificationStream' => $this->notificationStream,
        ])->layout('components.layouts.account-app');
    }
}
