<?php

namespace App\Http\Controllers;

use App\Services\PublicConsultationShareService;
use Illuminate\Contracts\View\View;

class SharedConsultationController extends Controller
{
    public function __invoke(string $token, PublicConsultationShareService $shareService): View
    {
        $consultation = $shareService->find($token);
        abort_if($consultation === null, 404);

        return view('consultations.shared', ['consultation' => $consultation]);
    }
}
