<?php

namespace App\Http\Controllers;

use App\Services\PublicConsultationShareService;
use Illuminate\Http\Response;

class SharedConsultationController extends Controller
{
    public function __invoke(string $token, PublicConsultationShareService $shareService): Response
    {
        $consultation = $shareService->find($token);
        abort_if($consultation === null, 404);

        return response()
            ->view('consultations.shared', ['consultation' => $consultation])
            ->header('X-Robots-Tag', 'noindex, nofollow, noarchive')
            ->header('Referrer-Policy', 'no-referrer')
            ->header('Cache-Control', 'private, no-store');
    }
}
