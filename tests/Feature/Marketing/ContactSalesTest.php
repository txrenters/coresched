<?php

use App\Notifications\Marketing\SalesInquiryReceived;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    config(['mail.sales_address' => 'sales@example.com']);
});

/**
 * @return array<string, string>
 */
function salesInquiry(array $overrides = []): array
{
    return [
        'name' => 'Dana Lee',
        'email' => 'dana@northwind.test',
        'company' => 'Northwind',
        'team_size' => '11-50',
        'plan' => 'business',
        'message' => 'We book demos by email today.',
        ...$overrides,
    ];
}

test('the pricing page renders', function () {
    $this->get(route('pricing'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Pricing'));
});

test('a sales inquiry is emailed to the sales inbox with reply-to set to the visitor', function () {
    $this->from(route('pricing'))
        ->post(route('contact-sales.store'), salesInquiry())
        ->assertRedirect(route('pricing'))
        ->assertSessionHasNoErrors();

    Notification::assertSentOnDemand(SalesInquiryReceived::class, function (SalesInquiryReceived $notification, array $channels, AnonymousNotifiable $notifiable) {
        $mail = $notification->toMail($notifiable);

        return $notifiable->routes['mail'] === 'sales@example.com'
            && $mail->replyTo === [['dana@northwind.test', 'Dana Lee']]
            && str_contains($mail->subject, 'Northwind');
    });
});

test('a sales inquiry needs a name, a valid email, a company and a known team size', function () {
    $this->post(route('contact-sales.store'), salesInquiry([
        'name' => '',
        'email' => 'not-an-email',
        'company' => '',
        'team_size' => '5000',
    ]))->assertSessionHasErrors(['name', 'email', 'company', 'team_size']);

    Notification::assertNothingSent();
});

test('a filled honeypot looks like success but sends nothing', function () {
    $this->from(route('pricing'))
        ->post(route('contact-sales.store'), salesInquiry(['website' => 'https://spam.test']))
        ->assertRedirect(route('pricing'))
        ->assertSessionHasNoErrors();

    Notification::assertNothingSent();
});

test('sales inquiries are rate limited', function () {
    foreach (range(1, 5) as $attempt) {
        $this->post(route('contact-sales.store'), salesInquiry());
    }

    $this->post(route('contact-sales.store'), salesInquiry())->assertTooManyRequests();
});

test('what the visitor typed is escaped in the email to sales', function () {
    $inquiry = salesInquiry([
        'name' => '<script>alert(1)</script>',
        'company' => '<img src=x onerror=alert(1)>',
        'message' => '<b>bold</b>',
    ]);

    $html = (string) (new SalesInquiryReceived($inquiry))->toMail(new AnonymousNotifiable)->render();

    expect($html)
        ->not->toContain('<script>alert(1)</script>')
        ->not->toContain('<img src=x')
        ->not->toContain('<b>bold</b>')
        ->toContain('&lt;script&gt;');
});
