<?php

namespace App\Http\Controllers;

use App\Mail\ContactInquiry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;

class ContactController extends Controller
{
    public function create(): View
    {
        return view('contact.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100', 'not_regex:/[\r\n]/'],
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
            'subject' => ['required', 'string', 'max:150', 'not_regex:/[\r\n]/'],
            'message' => ['required', 'string', 'max:5000'],
        ]);

        Mail::to(config('services.contact.email'))->send(new ContactInquiry(
            inquiryName: $validated['name'],
            replyEmail: $validated['email'],
            inquirySubject: $validated['subject'],
            inquiryMessage: $validated['message'],
        ));

        return to_route('contact.create')->with('status', __('お問い合わせを送信しました。'));
    }
}
