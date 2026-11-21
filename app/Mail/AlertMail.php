<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** A short notification e-mail: one or more lines of text and a button. */
class AlertMail extends Mailable
{
    use Queueable, SerializesModels;

    /** @param string[] $lines */
    public function __construct(
        public string $subjectLine,
        public string $greeting,
        public array $lines,
        public string $buttonText,
        public string $buttonUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.alert');
    }

    /**
     * The mail is written in Markdown, and its lines carry thread and conversation titles that members chose.
     * Without this a title like "[Verify your account](https://…)" would arrive as a link in a genuine forum mail.
     */
    public static function plain(string $text): string
    {
        return preg_replace('/([\\\\`*_\[\]()#!<>|~])/', '\\\\$1', str_replace(["\r", "\n"], ' ', $text));
    }
}
