<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Marketing\ContactSalesRequest;
use App\Notifications\Marketing\SalesInquiryReceived;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Notification;

class ContactSalesController extends Controller
{
    /**
     * Email a pricing-page inquiry to the sales inbox (mail.sales_address).
     */
    public function store(ContactSalesRequest $request): RedirectResponse
    {
        if (blank($request->validated('website'))) {
            Notification::route('mail', config('mail.sales_address'))
                ->notify(new SalesInquiryReceived($request->safe()->except('website')));
        }

        return back();
    }
}
