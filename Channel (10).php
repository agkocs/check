<?php
    namespace App\PaymentChannels\Drivers\Paytr;

use App\Models\Order;
use App\Models\PaymentChannel;
use App\PaymentChannels\BasePaymentChannel;
use App\PaymentChannels\IChannel;
use Illuminate\Http\Request;

class Channel extends BasePaymentChannel implements IChannel
{
    protected $currency;
    protected $test_mode;
    protected $merchant_id;
    protected $merchant_salt;
    protected $merchant_key;

    protected array $credentialItems = [
        'merchant_id',
        'merchant_salt',
        'merchant_key',
    ];

    public function __construct(PaymentChannel $paymentChannel)
    {
        $this->currency = "TRY";
        $this->setCredentialItems($paymentChannel);
    }

    private function handleConfigs()
    {
        \Config::set('paytr.credentials.merchant_id', $this->merchant_id);
        \Config::set('paytr.credentials.merchant_key', $this->merchant_key);
        \Config::set('paytr.credentials.merchant_salt', $this->merchant_salt);
        
        \Config::set('paytr.options.base_uri', 'https://www.paytr.com');
        \Config::set('paytr.options.timeout', 60);
        \Config::set('paytr.options.success_url', url("/paytr/payment-check"));
        \Config::set('paytr.options.fail_url', url("/paytr/payment-check"));
        \Config::set('paytr.options.test_mode', !!$this->test_mode);
    }

    public function paymentRequest(Order $order)
    {
        $this->handleConfigs();

        $user = $order->user;
        $price = $this->makeAmountByCurrency($order->total_amount, $this->currency);

        $products = $this->prepareOrderItems($order);
        $userInfo = $this->prepareUserInfo($user);
        
        $basket = \Paytr::basket()->addProducts($products);
        $payment = $this->preparePayment($userInfo, $order, $price, $basket);

        $paymentRequest = \Paytr::createPayment($payment);

        if ($paymentRequest->isSuccess()) {
            return 'https://www.paytr.com/odeme/guvenli/' . $paymentRequest->getToken();
        }

        return $this->handlePaymentError();
    }

    private function prepareOrderItems($order)
    {
        $products = [];
        foreach ($order->orderItems as $orderItem) {
            $products[] = [
                'Cart Item ' . $orderItem->id,
                $this->makeAmountByCurrency($orderItem->amount, $this->currency),
                1,
            ];
        }
        return $products;
    }

    private function prepareUserInfo($user)
    {
        $generalSettings = getGeneralSettings();
        return [
            'mobile' => !empty($user->mobile) ? $user->mobile : ($generalSettings['site_phone'] ?? '0123456789'),
            'email' => !empty($user->email) ? $user->email : ($generalSettings['site_email'] ?? 'site_email@gmail.com'),
            'address' => !empty($user->address) ? $user->address : (getContactPageSettings("address") ?? 'Platform address'),
            'full_name' => $user->full_name
        ];
    }

    private function preparePayment($userInfo, $order, $price, $basket)
    {
        return \Paytr::payment()
            ->setCurrency($this->currency)
            ->setUserPhone($userInfo['mobile'])
            ->setUserAddress($userInfo['address'])
            ->setNoInstallment(1)
            ->setMaxInstallment(1)
            ->setEmail($userInfo['email'])
            ->setMerchantOid($order->id)
            ->setUserIp(request()->ip())
            ->setPaymentAmount($price)
            ->setUserName($userInfo['full_name'])
            ->setSuccessUrl($this->makeCallbackUrl("success"))
            ->setFailUrl($this->makeCallbackUrl("fail"))
            ->setLang("en")
            ->setBasket($basket);
    }

public function verify(Request $request)
{
    $this->handleConfigs();
    $verification = \Paytr::paymentVerification($request);

    if (!$verification->verifyRequest()) {
        return null;
    }

    $orderId = $verification->getMerchantOid();
    $order = Order::where('id', $orderId)->first();

    if ($order && $verification->isSuccess()) {
        $order->update(['status' => Order::$paying]);
        return $order;
    }

    return null;
}
    private function makeCallbackUrl($status)
    {
        return route('payment_verify', [
            'gateway' => 'Paytr',
            'status' => $status
        ]);
    }

    private function handlePaymentError()
    {
        $toastData = [
            'title' => trans('cart.fail_purchase'),
            'msg' => '',
            'status' => 'error'
        ];
        return redirect()->back()->with(['toast' => $toastData])->withInput();
    }
}