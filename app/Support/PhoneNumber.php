<?php

namespace App\Support;

use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberUtil;

/**
 * Puts the phone numbers this app collects into the shape Twilio insists on.
 */
class PhoneNumber
{
    /**
     * Get the number in E.164 form, or null when it cannot be one.
     *
     * Numbers reach us typed by hand -- an invitee answering a booking
     * question, a host filling in their profile -- so "(512) 555-0100" and
     * "512.555.0100" both have to become +15125550100. A bare ten digit
     * number is assumed to be American, which every number in this system is;
     * anything international has to be written with its + and country code.
     *
     * The right number of digits is not enough: +18612390100 has ten after the
     * +1, but 861 is no area code, and Twilio refuses it at send time. So the
     * result has to be a number libphonenumber knows can exist.
     */
    public static function toE164(?string $number): ?string
    {
        $digits = preg_replace('/[^0-9]/', '', (string) $number) ?? '';

        if ($digits === '') {
            return null;
        }

        // A leading + means it is already international, and written as such.
        $e164 = match (true) {
            str_starts_with(trim((string) $number), '+') => '+'.$digits,
            strlen($digits) === 10 => '+1'.$digits,
            strlen($digits) === 11 && str_starts_with($digits, '1') => '+'.$digits,
            default => null,
        };

        return $e164 !== null && self::exists($e164) ? $e164 : null;
    }

    /**
     * Determine whether an E.164 number belongs to a real numbering plan.
     */
    private static function exists(string $e164): bool
    {
        $phoneNumbers = PhoneNumberUtil::getInstance();

        try {
            return $phoneNumbers->isValidNumber($phoneNumbers->parse($e164));
        } catch (NumberParseException) {
            return false;
        }
    }

    /**
     * Determine whether a number can be texted.
     */
    public static function isValid(?string $number): bool
    {
        return self::toE164($number) !== null;
    }
}
