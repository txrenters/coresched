<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Marketing\ContactSalesRequest;
use App\Models\SalesInquiry;
use App\Notifications\Marketing\SalesInquiryReceived;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Notification;

class ContactSalesController extends Controller
{
    /**
     * Record a pricing-page inquiry for the admin console and email it to the
     * sales inbox (mail.sales_address).
     */
    public function store(ContactSalesRequest $request): RedirectResponse
    {
        if (blank($request->validated('website'))) {
            $inquiry = $request->safe()->except('website');

            SalesInquiry::create($inquiry);

            Notification::route('mail', config('mail.sales_address'))
                ->notify(new SalesInquiryReceived($inquiry));
        }

        return back();
    }
}
