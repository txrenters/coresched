<?php

namespace App\Services\Mail;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Message;
use Symfony\Component\Mime\MessageConverter;
use Symfony\Component\Mime\Part\DataPart;

/**
 * Sends mail through the Microsoft Graph sendMail endpoint.
 *
 * The bookings address is a shared mailbox, which has no password and cannot
 * authenticate to SMTP at all. Graph application permissions are the way in:
 * we hold an app-only token for the tenant and post to that mailbox's
 * /sendMail, rather than signing in as it.
 */
class MicrosoftGraphTransport extends AbstractTransport
{
    protected const GRAPH_URL = 'https://graph.microsoft.com/v1.0';

    protected const TOKEN_CACHE_KEY = 'microsoft-graph.mail.token';

    /**
     * @param  array<int, string>  $sendAs
     */
    public function __construct(
        protected Cache $cache,
        protected string $tenant,
        protected string $clientId,
        protected string $clientSecret,
        protected string $mailbox,
        protected bool $saveToSentItems = false,
        protected array $sendAs = [],
    ) {
        parent::__construct();
    }

    /**
     * Get the string representation of the transport.
     */
    public function __toString(): string
    {
        return 'microsoft-graph';
    }

    /**
     * Hand the message to Graph on behalf of the configured mailbox.
     */
    protected function doSend(SentMessage $message): void
    {
        $original = $message->getOriginalMessage();

        /**
         * Graph takes a structured message, not a MIME blob, so a pre-rendered
         * RawMessage has nothing we can map onto it.
         */
        if (! $original instanceof Message) {
            throw new TransportException('The Microsoft Graph transport cannot send a pre-rendered raw message.');
        }

        $email = MessageConverter::toEmail($original);

        Http::withToken($this->accessToken())
            ->acceptJson()
            ->post(self::GRAPH_URL.'/users/'.urlencode($this->sendingMailbox($email)).'/sendMail', [
                'message' => $this->message($email),
                'saveToSentItems' => $this->saveToSentItems,
            ])
            ->throw();
    }

    /**
     * Pick the mailbox to send through.
     *
     * Graph sends as the mailbox in the URL, and naming a different From
     * needs Send As rights on top. So a message from one of the other
     * mailboxes this app sends as -- the no-reply address account mail uses --
     * goes through that mailbox. Anything else goes through the configured
     * one, so an unexpected From cannot pick an arbitrary mailbox.
     */
    protected function sendingMailbox(Email $email): string
    {
        $from = $email->getFrom()[0] ?? null;

        if ($from === null) {
            return $this->mailbox;
        }

        foreach ($this->sendAs as $mailbox) {
            if (strcasecmp($mailbox, $from->getAddress()) === 0) {
                return $mailbox;
            }
        }

        return $this->mailbox;
    }

    /**
     * Build the Graph message resource for an outgoing email.
     *
     * @return array<string, mixed>
     */
    protected function message(Email $email): array
    {
        $message = [
            'subject' => (string) $email->getSubject(),
            'body' => $this->body($email),
            'toRecipients' => $this->recipients($email->getTo()),
        ];

        /**
         * Graph rejects empty recipient collections, so only send the ones
         * that actually carry addresses.
         *
         * @var array<string, array<int, Address>> $optional
         */
        $optional = [
            'ccRecipients' => $email->getCc(),
            'bccRecipients' => $email->getBcc(),
            'replyTo' => $email->getReplyTo(),
        ];

        foreach ($optional as $key => $addresses) {
            if ($addresses !== []) {
                $message[$key] = $this->recipients($addresses);
            }
        }

        /**
         * The mailbox in the URL already decides who the mail comes from, but
         * naming it explicitly is what carries MAIL_FROM_NAME through.
         */
        if ($from = $email->getFrom()[0] ?? null) {
            $message['from'] = $this->recipient($from);
        }

        if ($attachments = $this->attachments($email)) {
            $message['attachments'] = $attachments;
        }

        return $message;
    }

    /**
     * Pick the body Graph should carry.
     *
     * A Graph message holds a single body, so an email with both parts sends
     * as HTML and the plain text alternative is dropped.
     *
     * @return array{contentType: string, content: string}
     */
    protected function body(Email $email): array
    {
        $html = $email->getHtmlBody();

        if (filled($html)) {
            return ['contentType' => 'HTML', 'content' => $this->stringify($html)];
        }

        return ['contentType' => 'Text', 'content' => $this->stringify($email->getTextBody())];
    }

    /**
     * Encode the message's attachments as Graph file attachments.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function attachments(Email $email): array
    {
        return array_map(function (DataPart $part): array {
            $attachment = [
                '@odata.type' => '#microsoft.graph.fileAttachment',
                'name' => $part->getFilename() ?? 'attachment',
                'contentType' => $part->getMediaType().'/'.$part->getMediaSubtype(),
                'contentBytes' => base64_encode($part->getBody()),
                'isInline' => $part->getDisposition() === 'inline',
            ];

            if ($part->hasContentId()) {
                $attachment['contentId'] = $part->getContentId();
            }

            return $attachment;
        }, $email->getAttachments());
    }

    /**
     * Format a list of addresses as Graph recipients.
     *
     * @param  array<int, Address>  $addresses
     * @return array<int, array<string, mixed>>
     */
    protected function recipients(array $addresses): array
    {
        return array_map($this->recipient(...), $addresses);
    }

    /**
     * Format a single address as a Graph recipient.
     *
     * @return array{emailAddress: array<string, string>}
     */
    protected function recipient(Address $address): array
    {
        $email = ['address' => $address->getAddress()];

        if (filled($address->getName())) {
            $email['name'] = $address->getName();
        }

        return ['emailAddress' => $email];
    }

    /**
     * Get an app-only access token for the tenant, reusing it until it expires.
     */
    protected function accessToken(): string
    {
        $cached = $this->cache->get(self::TOKEN_CACHE_KEY);

        if (filled($cached)) {
            return $cached;
        }

        $tokens = Http::asForm()
            ->post("https://login.microsoftonline.com/{$this->tenant}/oauth2/v2.0/token", [
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'scope' => 'https://graph.microsoft.com/.default',
                'grant_type' => 'client_credentials',
            ])
            ->throw()
            ->json();

        if (blank($tokens['access_token'] ?? null)) {
            throw new RuntimeException('Microsoft Graph did not return an access token for the mail application.');
        }

        /**
         * Expire the cached token a minute early so a send never starts with a
         * token that lapses mid-request.
         */
        $this->cache->put(
            self::TOKEN_CACHE_KEY,
            $tokens['access_token'],
            max((int) ($tokens['expires_in'] ?? 3600) - 60, 60),
        );

        return $tokens['access_token'];
    }

    /**
     * Read a body part that Symfony may hand back as a resource.
     */
    protected function stringify(mixed $body): string
    {
        return is_resource($body) ? (string) stream_get_contents($body) : (string) $body;
    }
}
