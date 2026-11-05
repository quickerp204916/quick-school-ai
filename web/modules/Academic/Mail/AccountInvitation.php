<?php

namespace Modules\Academic\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Invites a staff member, parent or student to set a password for an institution account. */
class AccountInvitation extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public string $institution, public string $name, public string $role, public string $link)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Your {$this->institution} account on Quick School AI");
    }

    public function content(): Content
    {
        return new Content(text: 'academic::mail.invitation');
    }
}
