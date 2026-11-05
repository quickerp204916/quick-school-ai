<?php

namespace Modules\Platform\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Modules\Platform\Models\Tenant;

class OwnerInvitation extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Tenant $tenant, public string $ownerName, public string $link)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Your Quick School AI account for {$this->tenant->name} is ready");
    }

    public function content(): Content
    {
        return new Content(view: 'mail.owner-invitation');
    }
}
