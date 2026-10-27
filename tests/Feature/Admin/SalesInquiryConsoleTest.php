<?php

use App\Enums\SalesInquiryStatus;
use App\Models\SalesInquiry;
use App\Models\User;

function inquiryOperator(): User
{
    return User::factory()->create(['is_super_admin' => true]);
}

test('a super admin sees the sales inquiries, newest first', function () {
    SalesInquiry::factory()->create(['company' => 'Older Co', 'created_at' => now()->subDay()]);
    SalesInquiry::factory()->create(['company' => 'Newer Co']);
    SalesInquiry::factory()->contacted()->create(['company' => 'Handled Co', 'created_at' => now()->subWeek()]);

    $this->actingAs(inquiryOperator())
        ->get(route('admin.inquiries.index'))
        ->assertInertia(fn ($page) => $page
            ->component('admin/SalesInquiries')
            ->where('inquiries.data.0.company', 'Newer Co')
            ->where('inquiries.data.1.company', 'Older Co'));
});

test('the dashboard counts the inquiries nobody has picked up', function () {
    SalesInquiry::factory()->count(2)->create();
    SalesInquiry::factory()->contacted()->create();

    $this->actingAs(inquiryOperator())
        ->get(route('admin.organizations.index'))
        ->assertInertia(fn ($page) => $page->where('newInquiryCount', 2));
});

test('inquiries can be filtered by status', function () {
    SalesInquiry::factory()->create(['company' => 'Waiting']);
    SalesInquiry::factory()->contacted()->create(['company' => 'Handled']);

    $this->actingAs(inquiryOperator())
        ->get(route('admin.inquiries.index', ['status' => 'contacted']))
        ->assertInertia(fn ($page) => $page
            ->has('inquiries.data', 1)
            ->where('inquiries.data.0.company', 'Handled'));
});

test('a super admin moves an inquiry along', function () {
    $inquiry = SalesInquiry::factory()->create();

    $this->actingAs(inquiryOperator())
        ->patch(route('admin.inquiries.update', $inquiry), ['status' => 'won'])
        ->assertSessionHasNoErrors();

    expect($inquiry->fresh()->status)->toBe(SalesInquiryStatus::Won);
});

test('an unknown status is refused', function () {
    $inquiry = SalesInquiry::factory()->create();

    $this->actingAs(inquiryOperator())
        ->patch(route('admin.inquiries.update', $inquiry), ['status' => 'maybe'])
        ->assertSessionHasErrors('status');
});

test('nobody but a super admin can read or change inquiries', function () {
    $inquiry = SalesInquiry::factory()->create();
    $orgAdmin = User::factory()->create();

    $this->actingAs($orgAdmin)->get(route('admin.inquiries.index'))->assertForbidden();
    $this->actingAs($orgAdmin)
        ->patch(route('admin.inquiries.update', $inquiry), ['status' => 'won'])
        ->assertForbidden();

    expect($inquiry->fresh()->status)->toBe(SalesInquiryStatus::New);
});
