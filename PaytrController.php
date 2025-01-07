<?php

namespace App\Http\Controllers\Web;
 
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Log;

class PaytrController extends Controller
{
    public function checkPayment(Request $request)
    {
        Log::info('Paytr callback received', $request->all());

        $verification = \Paytr::paymentVerification($request);  
        
        if (!$verification->verifyRequest()) {  
            Log::error('Paytr verification failed');
            return response()->json(['error' => 'Unauthorized'], 401);
        }
        
        $orderId = $verification->getMerchantOid();
        $isSuccess = $verification->isSuccess();
        
        if ($isSuccess) {
            Log::info('Paytr payment successful', ['orderId' => $orderId]);
            return redirect()->route('payment_verify', [
                'gateway' => 'Paytr',
                'status' => 'success'
            ]);
        }
        
        Log::info('Paytr payment failed', ['orderId' => $orderId]);
        return redirect()->route('payment_verify', [
            'gateway' => 'Paytr',
            'status' => 'fail'
        ]);
    }
}