<?php

declare(strict_types=1);

namespace App\Support\Mail;

use Illuminate\Mail\Events\MessageSending;
use Symfony\Component\Mime\Address;

/**
 * The people of the demo world have addresses that do not exist (Д-2: demo.bz-crm.test). A letter to them must
 * never reach a real mail service: it would bounce and hurt the reputation of the sending domain. Such recipients
 * are removed; a letter left without recipients is not sent at all. Real addresses — of people who registered
 * themselves on a stand — are untouched.
 */
final class DropFictionalRecipients
{
    public function handle(MessageSending $event): bool
    {
        $domain = '@'.strtolower((string) config('demo.email_domain'));
        if ($domain === '@') {
            return true;
        }
        $real = fn (Address $address): bool => ! str_ends_with(strtolower($address->getAddress()), $domain);

        $message = $event->message;
        $to = array_values(array_filter($message->getTo(), $real));
        $cc = array_values(array_filter($message->getCc(), $real));
        $bcc = array_values(array_filter($message->getBcc(), $real));

        if ($to === [] && $cc === [] && $bcc === []) {
            return false;
        }
        $message->to(...$to);
        $message->cc(...$cc);
        $message->bcc(...$bcc);

        return true;
    }
}
