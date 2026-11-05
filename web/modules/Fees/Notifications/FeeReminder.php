<?php

namespace Modules\Fees\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Accounting\Support\Amount;
use Modules\Fees\Models\FeeInvoice;

/** Email to a guardian about a fee that is due soon or overdue. */
class FeeReminder extends Notification
{
    use Queueable;

    public function __construct(public readonly FeeInvoice $invoice, public readonly string $kind, public readonly string $institution)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $i = $this->invoice;
        $due = $i->due_date->format('j M Y');
        $m = (new MailMessage)->subject(($this->kind === 'overdue' ? 'Overdue: ' : 'Reminder: ')."fees for {$i->student->fullName()} ({$i->period})")
            ->greeting('Dear parent,')
            ->line($this->kind === 'overdue'
                ? "The {$i->period} fees for {$i->student->fullName()} were due on {$due} and are still unpaid."
                : "The {$i->period} fees for {$i->student->fullName()} are due on {$due}.")
            ->line('Amount due: '.Amount::format($i->balance()).' (invoice '.$i->number.').');
        if ($i->late_fee > 0) {
            $m->line('This includes a late fee of '.Amount::format($i->late_fee).'.');
        }

        return $m->line('You can pay in the school app (UPI, cards or net banking) or at the fee counter.')
            ->line('If you have already paid, please ignore this message.')->salutation($this->institution);
    }
}
